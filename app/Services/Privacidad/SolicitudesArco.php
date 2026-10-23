<?php

namespace App\Services\Privacidad;

use App\Mail\AvisoGeneral;
use App\Models\Auditoria;
use App\Models\Empresa;
use App\Models\SolicitudArco;
use App\Models\User;
use App\Services\Cuenta\AvisosCuenta;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Support\CorreoPlataforma;
use App\Support\Entrada;
use App\Support\Tenancy\EmpresaScope;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

use function Illuminate\Support\defer;

/**
 * Solicitudes ARCO (docs/tecnico/privacidad.md):
 *
 *  1. La persona llena el formulario público (de una empresa o de la
 *     plataforma) y recibe un enlace para confirmar su correo (72 h).
 *  2. Al confirmarlo, empieza el plazo legal: 20 días hábiles (lunes a
 *     viernes) para responder. Llega a la campana y a Mis pendientes de
 *     quien tiene «Configuración → Datos personales» con alcance de empresa
 *     (las de la plataforma, al Super Administrador, que las canaliza).
 *     Sin correo configurado, quien atiende la da por verificada después de
 *     confirmar la identidad en persona.
 *  3. Reloj y color (verde / ámbar desde «recordar_dias» / rojo al vencer),
 *     recordatorio a los administradores y, vencida, aviso al Super
 *     Administrador (la tarea diaria de privacidad).
 *  4. Atender: buscar lo que la plataforma tiene de la persona, exportarlo en
 *     un ZIP, rectificar en su ficha, eliminar (anonimizar) con confirmación
 *     y responder con texto (le llega por correo). Todo se audita sin datos
 *     personales de más.
 */
class SolicitudesArco
{
    public const DIAS_HABILES = 20;

    public const HORAS_ENLACE = 72;

    public const TEXTO_ELIMINADO = 'Datos eliminados por solicitud ARCO';

    public const REFERENCIA = 'solicitud_arco';

    public const PERMISO = 'configuracion.datos_personales';

    public function __construct(
        private readonly Tenant $tenant,
        private readonly CentroNotificaciones $campana,
        private readonly CorreoPlataforma $correo,
        private readonly DatosDePersona $datos,
    ) {}

    // ------------------------------------------------------------ Llegada

    /**
     * @param  array<string, mixed>  $entrada
     * @return array{0: SolicitudArco, 1: bool} solicitud y si se mandó el correo para confirmarla
     */
    public function crear(?Empresa $empresa, array $entrada, string $ip): array
    {
        $d = Validator::make($entrada, [
            'derecho' => ['required', Rule::in(array_keys(SolicitudArco::DERECHOS))],
            'nombre' => ['required', 'string', 'min:5', 'max:150'],
            'correo' => ['required', 'email:rfc', 'max:150'],
            'telefono' => ['nullable', 'string', 'max:20', 'regex:/^[0-9 +()-]{7,20}$/'],
            'relacion' => ['nullable', Rule::in(array_keys(SolicitudArco::RELACIONES))],
            'detalle' => ['required', 'string', 'min:10', 'max:3000'],
            'acepta' => ['accepted'],
        ], [
            'acepta.accepted' => 'Confirma que los datos son tuyos (o que tienes la representación legal de la persona).',
            'telefono.regex' => 'Escribe solo números en el teléfono.',
            'detalle.min' => 'Cuéntanos con un poco más de detalle qué necesitas.',
        ], [
            'derecho' => 'qué quieres hacer', 'nombre' => 'nombre completo', 'correo' => 'correo', 'telefono' => 'teléfono', 'detalle' => 'detalle',
        ])->validate();

        $token = Str::random(48);
        $solicitud = $this->tenant->conEmpresa($empresa?->id, function () use ($empresa, $d, $token, $ip) {
            $s = new SolicitudArco([
                'folio' => $this->folio($empresa?->id),
                'derecho' => $d['derecho'],
                'estado' => SolicitudArco::POR_VERIFICAR,
                'nombre' => Entrada::texto($d['nombre']),
                'correo' => mb_strtolower(trim($d['correo'])),
                'telefono' => isset($d['telefono']) ? preg_replace('/\D/', '', $d['telefono']) : null,
                'relacion' => $d['relacion'] ?? null,
                'detalle' => trim(str_replace("\r\n", "\n", (string) $d['detalle'])),
                'ip' => mb_substr($ip, 0, 45),
            ]);
            $s->forceFill(['empresa_id' => $empresa?->id, 'token_hash' => hash('sha256', $token), 'token_vence_en' => now()->addHours(self::HORAS_ENLACE)])->save();

            return $s;
        });
        $this->registrar($solicitud, null, 'recibida', ['derecho' => $solicitud->derecho, 'correo_confirmacion' => $this->correo->configurado()]);

        if (! $this->correo->configurado()) {
            // Sin correo no se puede confirmar en línea: llega igual a quien atiende para verificar en persona
            $this->avisarAdministradores($solicitud, 'Solicitud ARCO por confirmar: '.$solicitud->folio,
                'Llegó una solicitud de '.$solicitud->etiquetaDerecho().'. La plataforma no tiene correo para confirmarla: verifica la identidad de la persona y dala por verificada.');

            return [$solicitud, false];
        }
        $enlace = route('privacidad.verificar', $token);
        $mensaje = new AvisoGeneral('Confirma tu solicitud de privacidad '.$solicitud->folio, [
            'Recibimos tu solicitud de '.mb_strtolower($solicitud->etiquetaDerecho()).' de datos personales. Para atenderla, confirma que este correo es tuyo con el botón.',
            'El enlace sirve '.self::HORAS_ENLACE.' horas. Si no fuiste tú, ignora este correo.',
        ], [['Confirmar mi solicitud', $enlace]]);
        $correo = $solicitud->correo;
        defer(fn () => $this->correo->enviar([$correo], $mensaje));

        return [$solicitud, true];
    }

    public function porToken(string $token): ?SolicitudArco
    {
        if (! preg_match('/^[A-Za-z0-9]{48}$/', $token)) {
            return null;
        }

        return SolicitudArco::query()->withoutGlobalScope(EmpresaScope::class)->where('token_hash', hash('sha256', $token))
            ->where('estado', SolicitudArco::POR_VERIFICAR)->where('token_vence_en', '>', now())->first();
    }

    /** La persona confirmó su correo: empieza el plazo legal. */
    public function confirmarCorreo(SolicitudArco $s): void
    {
        $this->verificar($s, 'correo', null);
    }

    /** Quien atiende confirmó la identidad en persona (sin correo, o el enlace venció). */
    public function aprobarVerificacion(User $actor, SolicitudArco $s): void
    {
        if ($s->estado !== SolicitudArco::POR_VERIFICAR) {
            throw ValidationException::withMessages(['solicitud' => 'Esta solicitud ya estaba verificada.']);
        }
        $this->verificar($s, 'en_persona', $actor);
    }

    private function verificar(SolicitudArco $s, string $como, ?User $actor): void
    {
        $vence = self::venceEn(now());
        SolicitudArco::query()->withoutGlobalScope(EmpresaScope::class)->whereKey($s->id)->update([
            'estado' => SolicitudArco::PENDIENTE, 'verificada_en' => now(), 'verificada_como' => $como, 'vence_en' => $vence->toDateString(),
            'token_hash' => null, 'token_vence_en' => null, 'updated_at' => now(),
        ] + ($actor ? ['actualizado_por' => $actor->id] : []));
        $s->refresh();
        $this->registrar($s, $actor, 'verificada', ['como' => $como, 'vence_en' => $s->vence_en?->toDateString()]);
        $this->avisarAdministradores($s, 'Solicitud ARCO por atender: '.$s->folio,
            'Solicitud de '.$s->etiquetaDerecho().'. Plazo legal: responder a más tardar el '.$s->vence_en?->format('d/m/Y').' ('.self::DIAS_HABILES.' días hábiles).');
    }

    // ------------------------------------------------------------ Atender

    /**
     * @param  array<string, list<int>>  $seleccion
     */
    public function exportar(User $actor, SolicitudArco $s, array $seleccion): string
    {
        $this->exigirAbierta($s);
        $seleccion = $this->limpiarSeleccion($seleccion);
        if (array_sum(array_map('count', $seleccion)) === 0) {
            throw ValidationException::withMessages(['seleccion' => 'Marca al menos un registro de la lista.']);
        }
        if ($s->archivo) {
            Storage::disk('local')->delete($s->archivo);
        }
        $ruta = 'privacidad/arco/'.($s->empresa_id ?? 'plataforma').'/'.$s->folio.'-'.Str::lower(Str::random(8)).'.zip';
        $r = $this->tenant->conEmpresa($s->empresa_id, fn () => $this->datos->exportar($seleccion, $ruta, $s->folio));
        $this->actualizar($s, ['archivo' => $ruta], $actor, 'exportado', ['registros' => $r['registros'], 'archivos' => $r['archivos'], 'tipos' => array_map('count', $seleccion)]);

        return $ruta;
    }

    /**
     * @param  array<string, list<int>>  $seleccion
     * @return array{persona: int, acceso: int, candidato: int, omitidos: int}
     */
    public function eliminar(User $actor, SolicitudArco $s, array $seleccion): array
    {
        $this->exigirAbierta($s);
        $seleccion = $this->limpiarSeleccion($seleccion);
        unset($seleccion['colaborador']);
        if (array_sum(array_map('count', $seleccion)) === 0) {
            throw ValidationException::withMessages(['seleccion' => 'Marca al menos un registro del padrón, la bitácora o candidatos.']);
        }
        $hecho = $this->tenant->conEmpresa($s->empresa_id, fn () => $this->datos->eliminar($seleccion, self::TEXTO_ELIMINADO));
        $this->actualizar($s, [], $actor, 'eliminado', $hecho);

        return $hecho;
    }

    /** Respuesta final (Atender o Rechazar): le llega a la persona por correo y la solicitud se cierra. */
    public function responder(User $actor, SolicitudArco $s, string $respuesta, bool $procede): void
    {
        $this->exigirAbierta($s);
        $respuesta = trim(str_replace("\r\n", "\n", $respuesta));
        if (mb_strlen($respuesta) < 10 || mb_strlen($respuesta) > 5000) {
            throw ValidationException::withMessages(['respuesta' => 'Escribe la respuesta para la persona (de 10 a 5,000 caracteres).']);
        }
        $estado = $procede ? SolicitudArco::ATENDIDA : SolicitudArco::RECHAZADA;
        $this->actualizar($s, ['estado' => $estado, 'respuesta' => $respuesta, 'respondida_en' => now(), 'respondida_por' => $actor->id], $actor,
            $procede ? 'atendida' : 'rechazada', ['a_tiempo' => $s->vence_en === null || ! now()->startOfDay()->gt($s->vence_en)]);
        $this->campana->resolver(self::REFERENCIA, $s->id);

        if ($this->correo->configurado()) {
            $lineas = ['Respuesta a tu solicitud '.$s->folio.' ('.mb_strtolower($s->etiquetaDerecho()).'):', $respuesta];
            if ($s->archivo && $procede) {
                $lineas[] = 'Si pediste tus datos, te los entregaremos por el medio que acordamos contigo.';
            }
            $mensaje = new AvisoGeneral('Respuesta a tu solicitud '.$s->folio, $lineas);
            $correo = $s->correo;
            defer(fn () => $this->correo->enviar([$correo], $mensaje));
        }
    }

    /** Super Administrador: una solicitud de la página de la plataforma pasa a la empresa que corresponde. */
    public function canalizar(User $actor, SolicitudArco $s, Empresa $empresa): void
    {
        if ($s->empresa_id !== null || ! $actor->es_superadmin) {
            throw ValidationException::withMessages(['empresa_id' => 'Solo las solicitudes de la página de la plataforma se canalizan.']);
        }
        SolicitudArco::query()->withoutGlobalScope(EmpresaScope::class)->whereKey($s->id)
            ->update(['empresa_id' => $empresa->id, 'actualizado_por' => $actor->id, 'updated_at' => now()]);
        $this->campana->resolver(self::REFERENCIA, $s->id);
        $s->refresh();
        $this->registrar($s, $actor, 'canalizada', ['empresa_id' => $empresa->id]);
        if ($s->estado === SolicitudArco::PENDIENTE) {
            $this->avisarAdministradores($s, 'Solicitud ARCO por atender: '.$s->folio,
                'Te canalizaron una solicitud de '.$s->etiquetaDerecho().'. Responder a más tardar el '.$s->vence_en?->format('d/m/Y').'.');
        }
    }

    // ------------------------------------------------------------ Plazos

    /** Fecha límite: N días hábiles (lunes a viernes) después de $desde. */
    public static function venceEn(Carbon $desde, int $dias = self::DIAS_HABILES): Carbon
    {
        $fecha = $desde->copy()->startOfDay();
        $cuenta = 0;
        while ($cuenta < $dias) {
            $fecha->addDay();
            if (! $fecha->isWeekend()) {
                $cuenta++;
            }
        }

        return $fecha;
    }

    /** Días hábiles que faltan (0 = vence hoy; negativo = vencida). */
    public static function diasRestantes(SolicitudArco $s): ?int
    {
        if ($s->vence_en === null) {
            return null;
        }
        $hoy = now()->startOfDay();
        $vence = $s->vence_en->copy()->startOfDay();
        if ($hoy->equalTo($vence)) {
            return 0;
        }
        $signo = $hoy->lt($vence) ? 1 : -1;
        [$a, $b] = $signo > 0 ? [$hoy, $vence] : [$vence, $hoy];
        $n = 0;
        for ($f = $a->copy()->addDay(); $f->lte($b); $f->addDay()) {
            if (! $f->isWeekend()) {
                $n++;
            }
        }

        return $signo * $n;
    }

    /** verde | ambar | roja (solo las abiertas y verificadas). */
    public static function nivel(SolicitudArco $s, int $recordarDias): ?string
    {
        $restan = $s->estado === SolicitudArco::PENDIENTE ? self::diasRestantes($s) : null;

        return match (true) {
            $restan === null => null,
            $restan <= 0 => 'roja',
            $restan <= $recordarDias => 'ambar',
            default => 'verde',
        };
    }

    /**
     * Tarea diaria: recordatorio a quien atiende cuando faltan «recordar_dias»
     * hábiles y, ya vencida, aviso al Super Administrador (una vez cada uno).
     *
     * @return array{recordatorios: int, vencidas: int}
     */
    public function revisarPlazos(?Empresa $empresa, int $recordarDias): array
    {
        $r = ['recordatorios' => 0, 'vencidas' => 0];
        $q = SolicitudArco::query()->withoutGlobalScope(EmpresaScope::class)->where('estado', SolicitudArco::PENDIENTE)
            ->when($empresa, fn ($w) => $w->where('empresa_id', $empresa->id), fn ($w) => $w->whereNull('empresa_id'));
        foreach ($q->orderBy('id')->limit(500)->get() as $s) {
            $restan = self::diasRestantes($s);
            if ($restan === null) {
                continue;
            }
            if ($restan < 0 && $s->escalada_en === null) {
                $texto = 'La solicitud de '.$s->etiquetaDerecho().' venció el '.$s->vence_en->format('d/m/Y').' sin respuesta'.($empresa ? ' en '.$empresa->nombre_comercial : '').'.';
                $this->campana->avisarPlataforma(User::where('es_superadmin', true)->where('activo', true)->pluck('id'), 'arco_plazo', [
                    'titulo' => 'Solicitud ARCO vencida: '.$s->folio, 'texto' => $texto, 'url' => route('arco.show', $s->id), 'referencia_tipo' => self::REFERENCIA, 'referencia_id' => $s->id,
                ]);
                $this->avisarAdministradores($s, 'Solicitud ARCO vencida: '.$s->folio, $texto.' Respóndela hoy.', 'arco_plazo');
                SolicitudArco::query()->withoutGlobalScope(EmpresaScope::class)->whereKey($s->id)->update(['escalada_en' => now(), 'recordada_en' => $s->recordada_en ?? now()]);
                $r['vencidas']++;
            } elseif ($restan >= 0 && $restan <= $recordarDias && $s->recordada_en === null) {
                $this->avisarAdministradores($s, 'Solicitud ARCO por vencer: '.$s->folio,
                    'Faltan '.$restan.' día(s) hábil(es): responder a más tardar el '.$s->vence_en->format('d/m/Y').'.', 'arco_plazo');
                SolicitudArco::query()->withoutGlobalScope(EmpresaScope::class)->whereKey($s->id)->update(['recordada_en' => now()]);
                $r['recordatorios']++;
            }
        }

        return $r;
    }

    // ------------------------------------------------------------- Consultas

    /**
     * Bandeja: las de la empresa de trabajo; el Super Administrador sin
     * empresa elegida ve todas, y con «plataforma» las que llegaron de la
     * página pública (sin empresa).
     *
     * @return Builder<SolicitudArco>
     */
    public function consulta(User $actor, ?int $empresaId, bool $plataforma = false): Builder
    {
        if ($plataforma && $actor->es_superadmin) {
            return SolicitudArco::query()->withoutGlobalScope(EmpresaScope::class)->whereNull('empresa_id');
        }
        if ($empresaId === null) {
            abort_unless($actor->es_superadmin, 404);

            return SolicitudArco::query()->withoutGlobalScope(EmpresaScope::class);
        }

        return SolicitudArco::query()->withoutGlobalScope(EmpresaScope::class)->where('empresa_id', $empresaId);
    }

    public function porAtender(int $empresaId): int
    {
        return SolicitudArco::query()->withoutGlobalScope(EmpresaScope::class)->where('empresa_id', $empresaId)
            ->whereIn('estado', [SolicitudArco::POR_VERIFICAR, SolicitudArco::PENDIENTE])->count();
    }

    /** @return array<string, list<array{id: int, titulo: string, detalle: string, url: ?string}>> */
    public function buscarDatos(SolicitudArco $s, array $criterios): array
    {
        if ($s->empresa_id === null) {
            return array_fill_keys(array_keys(DatosDePersona::TIPOS), []);
        }

        return $this->tenant->conEmpresa($s->empresa_id, fn () => $this->datos->buscar($criterios));
    }

    // ------------------------------------------------------------- Ayudas

    /**
     * Folio único por empresa: «ARCO-AAAA-NNNN» en la empresa y
     * «ARCO-P-AAAA-NNNN» en las de la página de la plataforma. El contador de
     * la plataforma cuenta todas las «ARCO-P» (también las ya canalizadas), así
     * que al canalizar la solicitud conserva el folio que la persona conoce sin
     * chocar con los de la empresa. Se toma el número mayor + 1 (no el conteo).
     */
    private function folio(?int $empresaId): string
    {
        $prefijo = ($empresaId === null ? 'ARCO-P-' : 'ARCO-').now()->format('Y').'-';
        $mayor = SolicitudArco::query()->withoutGlobalScope(EmpresaScope::class)
            ->when($empresaId, fn ($q) => $q->where('empresa_id', $empresaId))
            ->where('folio', 'like', $prefijo.'%')->pluck('folio')
            ->map(fn ($f) => (int) substr($f, strlen($prefijo)))->max() ?? 0;

        return $prefijo.str_pad((string) ($mayor + 1), 4, '0', STR_PAD_LEFT);
    }

    private function exigirAbierta(SolicitudArco $s): void
    {
        if ($s->estado !== SolicitudArco::PENDIENTE) {
            throw ValidationException::withMessages(['solicitud' => $s->estado === SolicitudArco::POR_VERIFICAR
                ? 'Primero confirma la identidad de la persona (la solicitud aún no está verificada).'
                : 'Esta solicitud ya se respondió.']);
        }
        if ($s->empresa_id === null) {
            throw ValidationException::withMessages(['solicitud' => 'Primero canaliza la solicitud a la empresa que corresponde.']);
        }
    }

    /**
     * @param  array<string, mixed>  $seleccion
     * @return array<string, list<int>>
     */
    private function limpiarSeleccion(array $seleccion): array
    {
        $limpia = [];
        foreach (array_keys(DatosDePersona::TIPOS) as $tipo) {
            $ids = is_array($seleccion[$tipo] ?? null) ? $seleccion[$tipo] : [];
            $limpia[$tipo] = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($i) => $i > 0)));
        }

        return $limpia;
    }

    /**
     * @param  array<string, mixed>  $cambios
     * @param  array<string, mixed>  $detalle
     */
    private function actualizar(SolicitudArco $s, array $cambios, User $actor, string $accion, array $detalle): void
    {
        $acciones = (array) ($s->acciones ?? []);
        $acciones[] = ['accion' => $accion, 'en' => now()->toIso8601String(), 'por' => $actor->id, 'detalle' => $detalle];
        $s->forceFill($cambios + ['acciones' => array_slice($acciones, -50), 'actualizado_por' => $actor->id]);
        $this->tenant->conEmpresa($s->empresa_id, fn () => $s->save());
        $this->registrar($s, $actor, $accion, $detalle);
    }

    /** Bitácora de auditoría: folio, derecho y lo que se hizo (sin el nombre ni el correo de la persona). */
    private function registrar(SolicitudArco $s, ?User $actor, string $accion, array $detalle): void
    {
        $evento = match ($accion) {
            'recibida' => 'privacidad.arco_recibida',
            'verificada' => 'privacidad.arco_verificada',
            'exportado' => 'privacidad.arco_exportado',
            'eliminado' => 'privacidad.arco_eliminado',
            'atendida' => 'privacidad.arco_atendida',
            'rechazada' => 'privacidad.arco_rechazada',
            default => 'privacidad.arco_canalizada',
        };
        Auditoria::create([
            'empresa_id' => $s->empresa_id, 'user_id' => $actor?->id, 'evento' => $evento,
            'auditable_type' => SolicitudArco::class, 'auditable_id' => $s->id, 'antes' => null,
            'despues' => ['folio' => $s->folio, 'derecho' => $s->derecho] + $detalle, 'ip' => request()?->ip(),
        ]);
    }

    /** Campana de quien atiende (Datos personales con alcance de empresa) o, si es de la plataforma, del Super Administrador. */
    private function avisarAdministradores(SolicitudArco $s, string $titulo, string $texto, string $tipo = 'arco_solicitud'): void
    {
        $datos = ['titulo' => $titulo, 'texto' => $texto, 'url' => route('arco.show', $s->id), 'referencia_tipo' => self::REFERENCIA, 'referencia_id' => $s->id];
        if ($s->empresa_id === null) {
            $this->campana->avisarPlataforma(User::where('es_superadmin', true)->where('activo', true)->pluck('id'), $tipo, $datos);

            return;
        }
        $usuarios = $this->responsables((int) $s->empresa_id);
        if ($usuarios->isNotEmpty()) {
            $this->campana->avisar((int) $s->empresa_id, $usuarios->pluck('id'), $tipo, $datos);
        }
    }

    /** @return Collection<int, User> */
    public function responsables(int $empresaId): Collection
    {
        return app(AvisosCuenta::class)->usuariosConPermiso($empresaId, self::PERMISO, null);
    }
}
