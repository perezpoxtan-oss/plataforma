<?php

namespace App\Services\EquiposAcceso;

use App\Models\Acceso;
use App\Models\Colaborador;
use App\Models\Credencial;
use App\Models\EquipoAcceso;
use App\Models\MarcajeEquipoAcceso;
use App\Services\Accesos\MovimientoNoPermitido;
use App\Services\Accesos\MovimientosAccesos;
use App\Services\Accesos\RegistroAccesos;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Recepcion\Destinatarios;
use App\Support\EstadoActivo;
use App\Support\Lector\Etiqueta;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Convierte los marcajes de un equipo en entradas y salidas del colaborador
 * por el MISMO servicio que usan la caseta y el lector
 * (RegistroAccesos::entradaDeColaborador y MovimientosAccesos::salida). Así
 * aparecen en la Bitácora de accesos, en Gente en sitio y en Asistencia sin
 * tocar esos módulos.
 *
 * Reglas (decisión 10):
 *  - idempotencia: cada marcaje se guarda una vez (unique equipo + clave);
 *    el mismo marcaje repetido no duplica nada;
 *  - quién: PIN = número de empleado; tarjeta = etiqueta NFC/RFID del
 *    colaborador (cualquiera de sus formas). Una tarjeta de una credencial
 *    dada de baja, un colaborador de baja o sin credencial vigente (si usa
 *    credenciales) → se rechaza y se avisa «credencial no vigente usada en
 *    <puerta>» a la caseta y al supervisor de la sede;
 *  - dónde: la sede del equipo, con la regla de la caseta (de la sede,
 *    corporativo o con la sede como adicional);
 *  - sentido: el del equipo (solo entrada / solo salida) o, si es de los
 *    dos, lo que dijo el equipo; si no dijo, se alterna (en sitio → salida);
 *  - antipassback = solo aviso: una entrada de quien sigue en sitio cierra
 *    la anterior sin hora de salida y avisa «no registró su salida»; una
 *    salida sin entrada se guarda y avisa «no registró su entrada».
 *  - lo marcado antes del alta del equipo en la plataforma se descarta (el
 *    primer saludo de ZKTeco puede mandar todo su historial).
 */
class RegistroMarcajes
{
    public const AVISO_NO_VIGENTE = 'equipo_no_vigente';

    public const AVISO_ANTIPASSBACK = 'equipo_antipassback';

    public function __construct(
        private readonly RegistroAccesos $registro,
        private readonly MovimientosAccesos $movimientos,
        private readonly AdministradorRoles $auditoria,
    ) {}

    /**
     * Procesa los marcajes de una petición (dentro del tenant del equipo).
     *
     * @param  list<MarcajeLeido>  $marcajes
     * @return array<string, int> resultado => cuántos (más «repetido» y «anterior»)
     */
    public function procesar(EquipoAcceso $equipo, array $marcajes): array
    {
        $cuenta = [];
        foreach ($marcajes as $m) {
            $resultado = $this->uno($equipo, $m);
            $cuenta[$resultado] = ($cuenta[$resultado] ?? 0) + 1;
        }
        if (array_diff(array_keys($cuenta), ['repetido', 'anterior']) !== []) {
            EquipoAcceso::whereKey($equipo->id)->update(['ultimo_marcaje_at' => now()]);
        }

        return $cuenta;
    }

    public function uno(EquipoAcceso $equipo, MarcajeLeido $m): string
    {
        if ($m->cuando !== null && $m->ignorar === null && $m->cuando->lt($equipo->created_at->copy()->subMinutes(5))) {
            return 'anterior';
        }
        $clave = hash('sha256', $equipo->marca.'|'.$m->clave);
        if (MarcajeEquipoAcceso::where('equipo_acceso_id', $equipo->id)->where('clave', $clave)->exists()) {
            return 'repetido';
        }

        try {
            return DB::transaction(function () use ($equipo, $m, $clave) {
                // Se guarda primero: si otra petición trae el mismo marcaje a la vez, el índice único la detiene
                $fila = MarcajeEquipoAcceso::create([
                    'equipo_acceso_id' => $equipo->id, 'clave' => $clave, 'marcado_at' => $m->cuando,
                    'identificador' => MarcajeEquipoAcceso::enmascarar($m->tarjeta ?? $m->pin), 'resultado' => 'ignorado',
                    'detalle' => $m->ignorar !== null ? mb_substr($m->ignorar, 0, 255) : null,
                ]);
                if ($m->ignorar !== null) {
                    return 'ignorado';
                }
                [$resultado, $datos] = $this->aplicar($equipo, $m);
                $fila->forceFill($datos + ['resultado' => $resultado])->save();

                return $resultado;
            });
        } catch (UniqueConstraintViolationException) {
            return 'repetido';
        }
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function aplicar(EquipoAcceso $equipo, MarcajeLeido $m): array
    {
        [$colaborador, $porTarjetaVieja] = $this->quien($m);
        if ($colaborador === null) {
            return ['desconocido', ['detalle' => 'No hay colaborador con ese '.($m->tarjeta !== null ? 'número de tarjeta' : 'número de empleado')]];
        }
        $datos = ['colaborador_id' => $colaborador->id];
        $motivo = match (true) {
            $porTarjetaVieja => 'tarjeta de una credencial dada de baja',
            ! $colaborador->activo || $colaborador->fusionado_en_id !== null => 'colaborador dado de baja',
            ! $this->credencialVigente($colaborador) => 'sin credencial vigente',
            default => null,
        };
        if ($motivo !== null) {
            $this->avisarNoVigente($equipo, $colaborador, $motivo);
            $this->auditoria->auditar(null, 'equipos_acceso.marcaje_rechazado', $equipo, null, ['colaborador' => $colaborador->id, 'motivo' => $motivo]);

            return ['no_vigente', $datos + ['detalle' => ucfirst($motivo)]];
        }
        if ($this->registro->colaboradorEnSede($colaborador->id, (int) $equipo->sede_id) === null) {
            return ['otra_sede', $datos + ['detalle' => 'El colaborador no pertenece a la sede del equipo']];
        }

        $abierta = Acceso::where('colaborador_id', $colaborador->id)->where('sede_id', $equipo->sede_id)
            ->where('tipo', 'colaborador')->where('movimiento', 'entrada')->where('estado', 'en_sitio')->orderByDesc('id')->first();
        $sentido = match ($equipo->sentido) {
            'entrada', 'salida' => $equipo->sentido,
            default => $m->sentido ?? ($abierta !== null ? 'salida' : 'entrada'),
        };
        $datos['sentido'] = $sentido;

        if ($sentido === 'salida') {
            if ($abierta === null) {
                $this->avisarAntipassback($equipo, $colaborador, 'no registró su entrada');

                return ['sin_entrada', $datos + ['detalle' => 'Salida sin entrada registrada']];
            }
            $cuando = $m->cuando !== null && $abierta->entrada_at !== null && $m->cuando->lt($abierta->entrada_at) ? $abierta->entrada_at : $m->cuando;
            try {
                $this->movimientos->salida(null, $abierta, ['equipo_salida_id' => $equipo->id, 'salida_at' => $cuando]);
            } catch (MovimientoNoPermitido) {
                return ['sin_entrada', $datos + ['detalle' => 'La salida ya estaba registrada']];
            }

            return ['salida', $datos + ['acceso_id' => $abierta->id]];
        }

        if ($abierta !== null) {
            // Antipassback (decisión del dueño: solo avisar): la entrada anterior se cierra sin hora de salida
            Acceso::whereKey($abierta->id)->where('estado', 'en_sitio')->update(['estado' => 'finalizado', 'updated_at' => now()]);
            $this->auditoria->auditar(null, 'accesos.sin_salida', $abierta, ['estado' => 'en_sitio'], ['estado' => 'finalizado', 'equipo' => $equipo->id]);
            $this->avisarAntipassback($equipo, $colaborador, 'no registró su salida');
        }
        try {
            $acceso = $this->registro->entradaDeColaborador(null, $colaborador, (int) $equipo->sede_id,
                ['equipo_entrada_id' => $equipo->id, 'entrada_at' => $m->cuando]);
        } catch (ValidationException $e) {
            return ['otra_sede', $datos + ['detalle' => mb_substr(collect($e->errors())->flatten()->first() ?? 'No se pudo registrar', 0, 255)]];
        }

        return ['entrada', $datos + ['acceso_id' => $acceso->id]];
    }

    /**
     * El colaborador del marcaje y si llegó con la tarjeta de una credencial
     * ya dada de baja.
     *
     * @return array{0: ?Colaborador, 1: bool}
     */
    public function quien(MarcajeLeido $m): array
    {
        $candidatos = Etiqueta::candidatos($m->tarjeta);
        if ($m->tarjeta !== null && ctype_digit($m->tarjeta) && strlen(ltrim($m->tarjeta, '0')) < 10) {
            // Los equipos mandan el número sin ceros a la izquierda; la tarjeta de 125 kHz lo imprime con 10 dígitos
            $candidatos[] = str_pad(ltrim($m->tarjeta, '0'), 10, '0', STR_PAD_LEFT);
        }
        if ($candidatos !== []) {
            $porTarjeta = Colaborador::whereIn('etiqueta_nfc', $candidatos)->orderByDesc('activo')->first();
            if ($porTarjeta !== null) {
                return [$this->sustituto($porTarjeta), false];
            }
            $vieja = Credencial::whereIn('etiqueta_nfc', $candidatos)->where('estado', EstadoActivo::BAJA)->orderByDesc('id')->first();
            if ($vieja !== null && ($c = Colaborador::find($vieja->colaborador_id)) !== null) {
                return [$this->sustituto($c), true];
            }
        }
        if ($m->pin !== null) {
            $porPin = Colaborador::where('num_empleado', $m->pin)->orderByDesc('activo')->orderBy('id')->first();
            if ($porPin === null && ctype_digit($m->pin) && ltrim($m->pin, '0') !== $m->pin) {
                $porPin = Colaborador::where('num_empleado', ltrim($m->pin, '0'))->orderByDesc('activo')->first();
            }

            return [$porPin === null ? null : $this->sustituto($porPin), false];
        }

        return [null, false];
    }

    /**
     * Vigente si no usa credenciales (nunca se le emitió una) o tiene una
     * DISPONIBLE o ASIGNADA sin vencer (la misma regla de la verificación
     * pública por QR).
     */
    public function credencialVigente(Colaborador $c): bool
    {
        $credenciales = Credencial::where('colaborador_id', $c->id)->get(['id', 'estado', 'vence_en']);

        return $credenciales->isEmpty() || $credenciales->contains(
            fn (Credencial $cr) => in_array($cr->estado, [EstadoActivo::DISPONIBLE, EstadoActivo::ASIGNADO], true) && ! $cr->vencida());
    }

    /** Un colaborador unido a otro se sustituye por el correcto. */
    private function sustituto(Colaborador $c): Colaborador
    {
        return $c->fusionado_en_id !== null ? (Colaborador::find($c->fusionado_en_id) ?? $c) : $c;
    }

    private function avisarNoVigente(EquipoAcceso $equipo, Colaborador $c, string $motivo): void
    {
        $usuarios = $this->casetaYSupervisor($equipo);
        app(CentroNotificaciones::class)->avisar((int) $equipo->empresa_id, $usuarios, self::AVISO_NO_VIGENTE, [
            'titulo' => 'Credencial no vigente usada en '.$equipo->lugar(),
            'texto' => $c->nombreCompleto().' ('.$motivo.'). El equipo no registró su paso. Si está en la puerta, atiéndelo en caseta.',
            'url' => route('accesos.index'),
            'referencia_tipo' => 'equipo_acceso',
            'referencia_id' => (int) $equipo->id,
        ]);
    }

    private function avisarAntipassback(EquipoAcceso $equipo, Colaborador $c, string $que): void
    {
        $usuarios = app(Destinatarios::class)->conPermiso((int) $equipo->empresa_id, 'accesos.aprobar', (int) $equipo->sede_id)->pluck('id');
        app(CentroNotificaciones::class)->avisar((int) $equipo->empresa_id, $usuarios, self::AVISO_ANTIPASSBACK, [
            'titulo' => $c->nombreCompleto().' '.$que,
            'texto' => 'Lo detectó '.$equipo->origen().($equipo->puerta ? ' ('.$equipo->puerta.')' : '').'. Solo es un aviso: su paso sí se registró.',
            'url' => route('accesos.index'),
            'referencia_tipo' => 'equipo_acceso',
            'referencia_id' => (int) $equipo->id,
        ]);
    }

    /** @return list<int> quienes registran ingresos o los aprueban en la sede del equipo */
    private function casetaYSupervisor(EquipoAcceso $equipo): array
    {
        $d = app(Destinatarios::class);

        return $d->conPermiso((int) $equipo->empresa_id, 'accesos.crear', (int) $equipo->sede_id)
            ->merge($d->conPermiso((int) $equipo->empresa_id, 'accesos.aprobar', (int) $equipo->sede_id))
            ->pluck('id')->unique()->values()->all();
    }
}
