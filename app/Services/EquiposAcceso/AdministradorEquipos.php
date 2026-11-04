<?php

namespace App\Services\EquiposAcceso;

use App\Models\EquipoAcceso;
use App\Models\Sede;
use App\Models\User;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Autorizador;
use App\Services\Relojes\Relojes\EquiposSinConexion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Configuración → Equipos de acceso (decisión 10): alta, edición, baja y
 * reactivación, token y «Sincronizar». Permisos: ver con
 * `configuracion.ver` (o `accesos.aprobar`, para el jefe de seguridad que
 * recibe el aviso de «sin conexión») y cambiar con `configuracion.editar`,
 * cada uno en las sedes de su alcance. Todo dentro de la empresa de trabajo.
 */
class AdministradorEquipos
{
    public const LARGO_TOKEN = 40;

    public function __construct(
        private readonly Autorizador $autorizador,
        private readonly AdministradorRoles $auditoria,
    ) {}

    /** @return list<int>|null sedes que puede ver (null = todas) */
    public function sedesVisibles(User $actor): ?array
    {
        $listas = [];
        foreach (['configuracion.ver', 'accesos.aprobar'] as $permiso) {
            if (! $actor->can($permiso)) {
                continue;
            }
            $sedes = $this->autorizador->sedesPermitidas($actor, $permiso);
            if ($sedes === null) {
                return null;
            }
            $listas[] = $sedes;
        }

        return array_values(array_unique(array_merge([], ...$listas)));
    }

    /** @return list<int>|null sedes donde puede cambiar equipos (null = todas; [] = ninguna) */
    public function sedesEditables(User $actor): ?array
    {
        return $actor->can('configuracion.editar') ? $this->autorizador->sedesPermitidas($actor, 'configuracion.editar') : [];
    }

    public function puedeVer(User $actor): bool
    {
        return $actor->can('configuracion.ver') || $actor->can('accesos.aprobar');
    }

    public function puedeEditar(User $actor, EquipoAcceso $equipo): bool
    {
        $sedes = $this->sedesEditables($actor);

        return $sedes === null || in_array((int) $equipo->sede_id, $sedes, true);
    }

    /** @return Collection<int, Sede> sedes activas donde puede dar de alta */
    public function sedesParaAlta(User $actor): Collection
    {
        $sedes = $this->sedesEditables($actor);

        return Sede::where('activo', true)->when($sedes !== null, fn ($q) => $q->whereIn('id', $sedes))->orderBy('nombre')->get(['id', 'nombre']);
    }

    /**
     * @param  array<string, mixed>  $entrada
     * @return array{0: EquipoAcceso, 1: string} el equipo y su token (se muestra una sola vez)
     */
    public function crear(User $actor, array $entrada): array
    {
        $d = $this->validar($actor, $entrada, null);
        $token = Str::random(self::LARGO_TOKEN);
        $equipo = new EquipoAcceso($d);
        $equipo->forceFill(['token_hash' => EquipoAcceso::hashToken($token), 'token_pista' => substr($token, -4)])->save();
        $this->auditoria->auditar($actor, 'equipos_acceso.creado', $equipo, null, self::foto($equipo));

        return [$equipo, $token];
    }

    /** @param  array<string, mixed>  $entrada */
    public function actualizar(User $actor, EquipoAcceso $equipo, array $entrada): EquipoAcceso
    {
        $antes = self::foto($equipo);
        $d = $this->validar($actor, $entrada + ['marca' => $equipo->marca], $equipo);
        unset($d['marca']); // la marca no cambia: es otro equipo
        $equipo->fill($d)->save();
        $this->auditoria->auditar($actor, 'equipos_acceso.actualizado', $equipo, $antes, self::foto($equipo));

        return $equipo;
    }

    public function cambiarEstado(User $actor, EquipoAcceso $equipo, bool $activo): void
    {
        if ($equipo->activo === $activo) {
            return;
        }
        $equipo->forceFill(['activo' => $activo])->save();
        $this->auditoria->auditar($actor, $activo ? 'equipos_acceso.reactivado' : 'equipos_acceso.baja', $equipo, ['activo' => ! $activo], ['activo' => $activo]);
        if (! $activo) {
            app(CentroNotificaciones::class)->resolver(EquiposSinConexion::REFERENCIA, (int) $equipo->id);
        }
    }

    /** «Generar otro token»: el anterior deja de servir al instante. */
    public function nuevoToken(User $actor, EquipoAcceso $equipo): string
    {
        $token = Str::random(self::LARGO_TOKEN);
        $equipo->forceFill(['token_hash' => EquipoAcceso::hashToken($token), 'token_pista' => substr($token, -4)])->save();
        $this->auditoria->auditar($actor, 'equipos_acceso.token', $equipo, null, ['token' => '••••'.substr($token, -4)]);

        return $token;
    }

    /**
     * @param  array<string, mixed>  $entrada
     * @return array<string, mixed>
     */
    private function validar(User $actor, array $entrada, ?EquipoAcceso $actual): array
    {
        $entrada = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $entrada);
        $d = Validator::make($entrada, [
            'sede_id' => ['required', 'integer'],
            'nombre' => ['required', 'string', 'max:80'],
            'marca' => ['required', Rule::in(array_keys(Conectores::MARCAS))],
            'modelo' => ['nullable', 'string', 'max:60'],
            'numero_serie' => ['required', 'string', 'regex:'.ConectorZkteco::SN],
            'puerta' => ['nullable', 'string', 'max:80'],
            'sentido' => ['required', Rule::in(array_keys(EquipoAcceso::SENTIDOS))],
            'ips_permitidas' => ['nullable', 'string', 'max:255'],
        ], [
            'sede_id.required' => 'Elige la sede.',
            'nombre.required' => 'Escribe un nombre para reconocer el equipo (ej. «Torniquete personal»).',
            'numero_serie.required' => 'Escribe el número de serie del equipo (viene en su etiqueta o en Menú → Información del sistema).',
            'numero_serie.regex' => 'El número de serie solo lleva letras, números, guion o guion bajo (de 4 a 40).',
            'marca.in' => 'Elige la marca de la lista.',
            'sentido.in' => 'Elige el sentido de la lista.',
            'nombre.max' => 'El nombre admite máximo 80 caracteres.',
            'puerta.max' => 'La puerta admite máximo 80 caracteres.',
        ])->validate();

        if (! $this->sedesParaAlta($actor)->contains('id', (int) $d['sede_id'])) {
            throw ValidationException::withMessages(['sede_id' => 'Elige una sede activa de la lista: esa sede no existe, está desactivada o no está a tu cargo.']);
        }
        $d['numero_serie'] = strtoupper($d['numero_serie']);
        $repetido = EquipoAcceso::withoutGlobalScopes()->where('marca', $d['marca'])->where('numero_serie', $d['numero_serie'])
            ->when($actual !== null, fn ($q) => $q->whereKeyNot($actual->id))->exists();
        if ($repetido) {
            // Sin decir de qué empresa es (podría ser de otro cliente)
            throw ValidationException::withMessages(['numero_serie' => 'Ese número de serie ya está registrado. Si es este mismo equipo, búscalo en la lista (puede estar dado de baja) y reactívalo.']);
        }
        $ips = array_values(array_filter(preg_split('/[\s,;]+/', (string) ($d['ips_permitidas'] ?? '')) ?: []));
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                throw ValidationException::withMessages(['ips_permitidas' => "«{$ip}» no es una dirección IP válida. Escribe una o varias separadas por coma."]);
            }
        }
        if (count($ips) > 10) {
            throw ValidationException::withMessages(['ips_permitidas' => 'Máximo 10 direcciones IP.']);
        }
        $d['ips_permitidas'] = $ips === [] ? null : implode(', ', $ips);

        return $d;
    }

    /** @return array<string, mixed> */
    public static function foto(EquipoAcceso $e): array
    {
        return $e->only(['sede_id', 'nombre', 'marca', 'modelo', 'numero_serie', 'puerta', 'sentido', 'ips_permitidas', 'activo']);
    }
}
