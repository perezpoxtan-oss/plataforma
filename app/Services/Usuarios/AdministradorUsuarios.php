<?php

namespace App\Services\Usuarios;

use App\Models\Rol;
use App\Models\Sede;
use App\Models\User;
use App\Models\UsuarioRol;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Alta, edición y activación de cuentas de una empresa (réplica de
 * modules/usuarios/usuario_proceso.php de SEGCAT), con las reglas contra
 * escalamiento del motor de permisos:
 *  - solo se asignan roles de nivel inferior al propio;
 *  - solo se administran usuarios de nivel inferior (nunca la propia cuenta aquí);
 *  - todo queda en la bitácora de auditoría.
 */
class AdministradorUsuarios
{
    private const CAMPOS_AUDITADOS = ['name', 'username', 'numero_colaborador', 'colaborador_id', 'email', 'activo'];

    public function __construct(
        private readonly AdministradorRoles $roles,
        private readonly Autorizador $autorizador,
    ) {}

    /**
     * @param  array{name: string, username: string, numero_colaborador: ?string, colaborador_id?: ?int, email: string, password: string}  $datos
     */
    public function crear(User $actor, int $empresaId, array $datos, Rol $rol, ?Sede $sede): User
    {
        $this->exigirRolYSede($actor, $empresaId, $rol, $sede, 'usuarios.crear');

        return DB::transaction(function () use ($actor, $empresaId, $datos, $rol, $sede) {
            $usuario = new User;
            $usuario->fill($datos + ['activo' => true]);
            $usuario->empresa_id = $empresaId;
            $usuario->forceFill(['creado_por' => $actor->id, 'actualizado_por' => $actor->id])->save();

            UsuarioRol::create(['user_id' => $usuario->id, 'rol_id' => $rol->id, 'sede_id' => $sede?->id]);
            $this->autorizador->olvidar($usuario);

            $this->roles->auditar($actor, 'usuarios.creado', $usuario, null, $this->foto($usuario));

            return $usuario;
        });
    }

    /**
     * @param  array{name: string, username: string, numero_colaborador: ?string, colaborador_id?: ?int, email: string, password?: ?string, activo: bool}  $datos
     */
    public function actualizar(User $actor, User $usuario, array $datos, Rol $rol, ?Sede $sede): User
    {
        $this->exigirPuedeAdministrar($actor, $usuario, 'usuarios.editar');
        $this->exigirRolYSede($actor, (int) $usuario->empresa_id, $rol, $sede, 'usuarios.editar');

        $antes = $this->foto($usuario);

        DB::transaction(function () use ($actor, $usuario, $datos, $rol, $sede) {
            if (empty($datos['password'])) {
                unset($datos['password']);
            }

            $usuario->fill($datos);
            $usuario->forceFill(['actualizado_por' => $actor->id])->save();

            // Un rol y una sede por cuenta, como en SEGCAT
            UsuarioRol::where('user_id', $usuario->id)->delete();
            UsuarioRol::create(['user_id' => $usuario->id, 'rol_id' => $rol->id, 'sede_id' => $sede?->id]);
        });

        $this->autorizador->olvidar($usuario);

        if (! $usuario->activo || array_key_exists('password', $datos)) {
            $this->cerrarSesiones($usuario);
        }

        $this->roles->auditar($actor, 'usuarios.actualizado', $usuario, $antes, $this->foto($usuario->fresh()));

        return $usuario;
    }

    public function cambiarEstado(User $actor, User $usuario, bool $activo): void
    {
        $this->exigirPuedeAdministrar($actor, $usuario, 'usuarios.eliminar');

        $usuario->forceFill(['activo' => $activo, 'actualizado_por' => $actor->id])->save();

        if (! $activo) {
            $this->cerrarSesiones($usuario);
        }

        $this->roles->auditar($actor, $activo ? 'usuarios.reactivado' : 'usuarios.desactivado', $usuario, ['activo' => ! $activo], ['activo' => $activo]);
    }

    /**
     * Quita el bloqueo por intentos fallidos (réplica del desbloqueo manual
     * que en SEGCAT se hacía directo en la base). Mismas reglas que editar:
     * solo usuarios de nivel inferior y dentro del alcance del permiso.
     */
    public function desbloquear(User $actor, User $usuario): void
    {
        $this->exigirPuedeAdministrar($actor, $usuario, 'usuarios.desbloquear');

        if (! $this->limitarAlcance(User::query()->whereKey($usuario->id), $actor, 'usuarios.desbloquear')->exists()) {
            throw new AuthorizationException('Ese usuario está fuera de tu alcance.');
        }

        $antes = [
            'bloqueado_hasta' => $usuario->bloqueado_hasta?->toIso8601String(),
            'intentos_fallidos' => (int) $usuario->intentos_fallidos,
        ];

        $usuario->forceFill(['bloqueado_hasta' => null, 'intentos_fallidos' => 0, 'actualizado_por' => $actor->id])->save();

        $this->roles->auditar($actor, 'usuarios.desbloqueado', $usuario, $antes, ['bloqueado_hasta' => null, 'intentos_fallidos' => 0]);
    }

    /**
     * Limita una consulta de usuarios al alcance que el actor tiene en un permiso:
     * toda la empresa, los asignados a sus sedes o solo los que él dio de alta.
     *
     * @param  Builder<User>  $consulta
     * @return Builder<User>
     */
    public function limitarAlcance(Builder $consulta, User $actor, string $permiso): Builder
    {
        if ($actor->es_superadmin) {
            return $consulta;
        }

        $efectivo = $this->autorizador->permisosEfectivos($actor)[$permiso] ?? null;

        return match (true) {
            $efectivo === null => $consulta->whereRaw('1 = 0'),
            $efectivo->alcance === Alcance::Empresa, $efectivo->alcance === Alcance::Sede && $efectivo->sedes === null => $consulta,
            $efectivo->alcance === Alcance::Sede => $consulta->whereHas('roles', fn ($q) => $q->whereIn('usuario_roles.sede_id', $efectivo->sedes)),
            default => $consulta->where('users.creado_por', $actor->id),
        };
    }

    public function exigirPuedeAdministrar(User $actor, User $usuario, string $permiso): void
    {
        if ($usuario->id === $actor->id) {
            throw new AuthorizationException('Tu propia cuenta no se modifica desde aquí.');
        }

        if ($usuario->es_superadmin) {
            throw new AuthorizationException('El Super Administrador no se administra desde aquí.');
        }

        if (! $actor->es_superadmin && ! $this->autorizador->puede($actor, $permiso)) {
            throw new AuthorizationException("No tienes el permiso «{$permiso}».");
        }

        $this->roles->exigirPuedeAdministrarUsuario($actor, $usuario);
    }

    private function exigirRolYSede(User $actor, int $empresaId, Rol $rol, ?Sede $sede, string $permiso): void
    {
        if ((int) $rol->empresa_id !== $empresaId) {
            throw new AuthorizationException('El rol pertenece a otra empresa.');
        }

        if ($sede !== null && (int) $sede->empresa_id !== $empresaId) {
            throw new AuthorizationException('La sede pertenece a otra empresa.');
        }

        if (! $actor->es_superadmin && (int) $actor->empresa_id !== $empresaId) {
            throw new AuthorizationException('Solo puedes administrar usuarios de tu empresa.');
        }

        // Asignar un rol exige poder administrarlo (nivel inferior al propio)
        $this->roles->exigirPuedeAdministrarRol($actor, $rol, $permiso);

        // Seguridad (AZ-01): con alcance limitado a sus sedes, la cuenta debe
        // quedar en una de ellas; nunca en otra sede ni en "todas las sedes"
        // (tendría más alcance que quien la da de alta).
        $sedes = $actor->es_superadmin ? null : $this->autorizador->sedesPermitidas($actor, $permiso);
        if ($sedes !== null && ($sede === null || ! in_array((int) $sede->id, $sedes, true))) {
            throw new AuthorizationException('Solo puedes asignar cuentas a tus sedes: elige una de ellas.');
        }
    }

    /**
     * Desactivar o cambiar la contraseña corta las sesiones abiertas de inmediato.
     */
    private function cerrarSesiones(User $usuario): void
    {
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $usuario->id)->delete();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function foto(User $usuario): array
    {
        $asignacion = UsuarioRol::where('user_id', $usuario->id)->first(['rol_id', 'sede_id']);

        return $usuario->only(self::CAMPOS_AUDITADOS) + [
            'rol_id' => $asignacion?->rol_id,
            'sede_id' => $asignacion?->sede_id,
        ];
    }
}
