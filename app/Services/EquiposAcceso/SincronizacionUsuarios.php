<?php

namespace App\Services\EquiposAcceso;

use App\Models\Colaborador;
use App\Models\ComandoEquipoAcceso;
use App\Models\Credencial;
use App\Models\EquipoAcceso;
use App\Models\User;
use App\Services\Accesos\RegistroAccesos;
use App\Services\Permisos\AdministradorRoles;
use App\Support\EstadoActivo;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Altas y bajas de usuarios en los equipos que las aceptan (hoy ZKTeco).
 *
 * Quién debe estar en un equipo: colaborador activo, con número de empleado,
 * que aplica en la sede del equipo (de la sede, corporativo o con la sede
 * como adicional) y con credencial vigente (DISPONIBLE o ASIGNADA, sin
 * vencer). Su tarjeta es la etiqueta NFC/RFID del colaborador.
 *
 * Cuándo: al emitir, entregar, reponer o dar de baja una credencial, al dar
 * de baja al colaborador o cambiarle la tarjeta, el número o la sede
 * (eventos de los modelos, ver EquiposAccesoServiceProvider) y con el botón
 * «Sincronizar». Se pone en la cola del equipo (equipos_acceso_comandos) y
 * se entrega cuando el equipo pregunta: sin cron.
 */
class SincronizacionUsuarios
{
    /** Un comando enviado sin respuesta se reenvía después de estos minutos. */
    public const REENVIO_MINUTOS = 5;

    public function __construct(private readonly RegistroAccesos $registro) {}

    /** Encola lo que toca para un colaborador en todos los equipos de su empresa que sincronizan. */
    public function colaborador(Colaborador|int $colaborador): void
    {
        $c = $colaborador instanceof Colaborador ? $colaborador : Colaborador::withoutGlobalScopes()->find($colaborador);
        if ($c === null) {
            return;
        }
        app(Tenant::class)->conEmpresa((int) $c->empresa_id, function () use ($c) {
            $equipos = EquipoAcceso::where('activo', true)->whereIn('marca', Conectores::queSincronizan())->get();
            foreach ($equipos as $equipo) {
                $this->encolar($equipo, $c, $this->debeEstar($equipo, $c));
            }
        });
    }

    /**
     * «Sincronizar»: carga en el equipo a todos los que deben estar y quita a
     * quienes se le mandaron y ya no deben estar.
     *
     * @return array{altas: int, bajas: int}
     */
    public function equipo(EquipoAcceso $equipo, ?User $actor): array
    {
        $conector = Conectores::para($equipo->marca);
        if (! $conector->sincronizaUsuarios()) {
            return ['altas' => 0, 'bajas' => 0];
        }
        $deben = $this->quienesDebenEstar($equipo);
        $altas = 0;
        foreach ($deben as $c) {
            $altas += $this->encolar($equipo, $c, true, forzar: true) ? 1 : 0;
        }
        $pinesDeben = $deben->map(fn (Colaborador $c) => ConectorZkteco::pin($c->num_empleado))->filter()->all();
        $bajas = 0;
        $mandados = ComandoEquipoAcceso::where('equipo_acceso_id', $equipo->id)->where('tipo', 'alta')->distinct()->pluck('pin');
        foreach ($mandados->diff($pinesDeben) as $pin) {
            $bajas += $this->encolarPin($equipo, (string) $pin, 'baja', null, null) ? 1 : 0;
        }
        app(AdministradorRoles::class)->auditar($actor, 'equipos_acceso.sincronizacion', $equipo, null, ['altas' => $altas, 'bajas' => $bajas]);

        return ['altas' => $altas, 'bajas' => $bajas];
    }

    /** @return Collection<int, Colaborador> */
    public function quienesDebenEstar(EquipoAcceso $equipo): Collection
    {
        $conVigente = Credencial::whereIn('estado', [EstadoActivo::DISPONIBLE, EstadoActivo::ASIGNADO])->get(['colaborador_id', 'vence_en', 'estado'])
            ->reject(fn (Credencial $cr) => $cr->vencida())->pluck('colaborador_id')->unique()->all();

        return Colaborador::where('colaboradores.activo', true)->whereNull('colaboradores.fusionado_en_id')
            ->whereNotNull('num_empleado')->whereIn('colaboradores.id', $conVigente)
            ->where(fn ($q) => $q->whereNull('colaboradores.sede_id')->orWhere(fn ($s) => $s->enSedes([(int) $equipo->sede_id])))
            ->orderBy('colaboradores.id')->get();
    }

    public function debeEstar(EquipoAcceso $equipo, Colaborador $c): bool
    {
        if (! $c->activo || $c->fusionado_en_id !== null || ConectorZkteco::pin($c->num_empleado) === null) {
            return false;
        }
        if ($this->registro->colaboradorEnSede($c->id, (int) $equipo->sede_id) === null) {
            return false;
        }

        return Credencial::where('colaborador_id', $c->id)->whereIn('estado', [EstadoActivo::DISPONIBLE, EstadoActivo::ASIGNADO])->get(['id', 'vence_en'])
            ->contains(fn (Credencial $cr) => ! $cr->vencida());
    }

    /**
     * Pone en la cola el alta (con su tarjeta de hoy) o la baja. La baja solo
     * si alguna vez se le mandó a ese equipo. Un comando aún no entregado del
     * mismo PIN se reemplaza (el equipo recibe solo lo último).
     */
    public function encolar(EquipoAcceso $equipo, Colaborador $c, bool $alta, bool $forzar = false): bool
    {
        $pin = ConectorZkteco::pin($c->num_empleado);
        $original = ConectorZkteco::pin((string) $c->getOriginal('num_empleado'));
        // Cambió su número de empleado: el número anterior sale del equipo
        if ($original !== null && $original !== $pin) {
            $this->encolarPin($equipo, $original, 'baja', $c->id, null);
        }
        if ($pin === null) {
            return false;
        }
        if (! $alta && ! ComandoEquipoAcceso::where('equipo_acceso_id', $equipo->id)->where('pin', $pin)->exists()) {
            return false;
        }

        return $this->encolarPin($equipo, $pin, $alta ? 'alta' : 'baja', $c->id, $alta ? $c->etiqueta_nfc : null, $forzar);
    }

    private function encolarPin(EquipoAcceso $equipo, string $pin, string $tipo, ?int $colaboradorId, ?string $tarjeta, bool $forzar = false): bool
    {
        $conector = Conectores::para($equipo->marca);
        $comando = $tipo === 'alta' ? $conector->comandoAlta($pin, $tarjeta) : $conector->comandoBaja($pin);
        if ($comando === null) {
            return false;
        }

        return DB::transaction(function () use ($equipo, $pin, $tipo, $colaboradorId, $comando, $forzar) {
            // Lo último que se le mandó (o se le va a mandar) a ese PIN: si es igual, no se repite
            $ultimo = ComandoEquipoAcceso::where('equipo_acceso_id', $equipo->id)->where('pin', $pin)->orderByDesc('id')->first();
            if (! $forzar && $ultimo !== null && $ultimo->comando === $comando && $ultimo->estado !== 'error') {
                return false;
            }
            ComandoEquipoAcceso::where('equipo_acceso_id', $equipo->id)->where('pin', $pin)->where('estado', 'pendiente')->delete();
            ComandoEquipoAcceso::create(['equipo_acceso_id' => $equipo->id, 'colaborador_id' => $colaboradorId, 'tipo' => $tipo, 'pin' => $pin, 'comando' => $comando]);

            return true;
        });
    }

    /**
     * Comandos que se entregan al equipo cuando pregunta (los pendientes y
     * los enviados sin respuesta hace más de REENVIO_MINUTOS, hasta
     * MAX_INTENTOS). Se marcan «enviado».
     *
     * @return list<string> renglones «C:<id>:<comando>»
     */
    public function entregar(EquipoAcceso $equipo, int $maximo = ConectorZkteco::COMANDOS_POR_PREGUNTA): array
    {
        return DB::transaction(function () use ($equipo, $maximo) {
            $limite = now()->subMinutes(self::REENVIO_MINUTOS);
            $comandos = ComandoEquipoAcceso::where('equipo_acceso_id', $equipo->id)
                ->where(fn ($q) => $q->where('estado', 'pendiente')
                    ->orWhere(fn ($w) => $w->where('estado', 'enviado')->where('enviado_at', '<', $limite)))
                ->orderBy('id')->limit($maximo)->lockForUpdate()->get();
            $renglones = [];
            foreach ($comandos as $cmd) {
                if ($cmd->intentos >= ComandoEquipoAcceso::MAX_INTENTOS) {
                    $cmd->forceFill(['estado' => 'error', 'respondido_at' => now()])->save();

                    continue;
                }
                $cmd->forceFill(['estado' => 'enviado', 'enviado_at' => now(), 'intentos' => $cmd->intentos + 1])->save();
                $renglones[] = 'C:'.$cmd->id.':'.$cmd->comando;
            }

            return $renglones;
        });
    }

    /**
     * Respuestas del equipo: Return 0 = hecho; otro número = error (se
     * guarda el número). Solo los comandos de ESE equipo.
     *
     * @param  array<int, int>  $resultados  id => Return
     */
    public function confirmar(EquipoAcceso $equipo, array $resultados): int
    {
        $n = 0;
        foreach ($resultados as $id => $ret) {
            $n += ComandoEquipoAcceso::where('equipo_acceso_id', $equipo->id)->whereKey($id)->whereIn('estado', ['enviado', 'pendiente'])
                ->update(['estado' => $ret === 0 ? 'hecho' : 'error', 'resultado' => $ret, 'respondido_at' => now(), 'updated_at' => now()]);
        }

        return $n;
    }
}
