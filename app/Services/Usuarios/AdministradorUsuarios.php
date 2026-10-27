<?php

namespace App\Services\Usuarios;

use App\Models\Rol;
use App\Models\Sede;
use App\Models\SolicitudCuenta;
use App\Models\User;
use App\Models\UsuarioRol;
use App\Services\Autorizaciones\Cobertura;
use App\Services\Cuenta\AvisosCuenta;
use App\Services\Cuenta\RecuperacionContrasena;
use App\Services\Cuenta\SolicitudesCuenta;
use App\Services\DosPasos\VerificacionDosPasos;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use App\Services\Suscripciones\Limites;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Alta, edición y activación de cuentas de una empresa (réplica de
 * modules/usuarios/usuario_proceso.php de SEGCAT), con las reglas contra
 * escalamiento del motor de permisos:
 *  - solo se asignan roles de nivel inferior al propio;
 *  - solo se administran usuarios de nivel inferior (nunca la propia cuenta aquí);
 *  - todo queda en la bitácora de auditoría.
 *
 * La contraseña es de su dueño (docs/criterios-ux.md, regla 6): el alta por
 * omisión manda un enlace de bienvenida y la cuenta nace con una contraseña
 * aleatoria que nadie conoce; si el administrador escribe una contraseña (alta
 * o restablecer), la cuenta queda marcada para cambiarla en el primer acceso.
 */
class AdministradorUsuarios
{
    private const CAMPOS_AUDITADOS = ['name', 'username', 'numero_colaborador', 'colaborador_id', 'email', 'activo'];

    public function __construct(
        private readonly AdministradorRoles $roles,
        private readonly Autorizador $autorizador,
        private readonly RecuperacionContrasena $recuperacion,
    ) {}

    /**
     * Alta. Con $enlace = true la contraseña es aleatoria (nadie la conoce) y
     * se envía el enlace de bienvenida; con false se guarda la contraseña que
     * escribió el administrador y la cuenta queda marcada para cambiarla al
     * entrar. Devuelve el usuario y si el correo de bienvenida salió.
     *
     * @param  array{name: string, username: string, numero_colaborador: ?string, colaborador_id?: ?int, email: string, password?: ?string}  $datos
     * @return array{0: User, 1: bool}
     */
    public function crear(User $actor, int $empresaId, array $datos, Rol $rol, ?Sede $sede, bool $enlace = false, array $otrasSedes = []): array
    {
        $this->exigirRolYSede($actor, $empresaId, $rol, $sede, 'usuarios.crear', $otrasSedes);
        // Suscripciones: no más usuarios activos que los contratados (sin suscripción, sin límite)
        app(Limites::class)->exigirUsuario($empresaId);

        if ($enlace) {
            $datos['password'] = Str::password(40);
        }

        $usuario = DB::transaction(function () use ($actor, $empresaId, $datos, $rol, $sede, $otrasSedes) {
            $usuario = new User;
            $usuario->fill($datos + ['activo' => true]);
            $usuario->empresa_id = $empresaId;
            $usuario->forceFill(['creado_por' => $actor->id, 'actualizado_por' => $actor->id, 'debe_cambiar_contrasena' => true])->save();

            UsuarioRol::create(['user_id' => $usuario->id, 'rol_id' => $rol->id, 'sede_id' => $sede?->id]);
            $this->asignarOtrasSedes($usuario, $rol, $sede, $otrasSedes);
            $this->autorizador->olvidar($usuario);

            $this->roles->auditar($actor, 'usuarios.creado', $usuario, null, $this->foto($usuario));

            return $usuario;
        });

        $enviado = $enlace && $this->recuperacion->enviarEnlace($usuario, RecuperacionContrasena::BIENVENIDA, $actor);

        return [$usuario, $enviado];
    }

    /**
     * Restablecer la contraseña de otra cuenta. Con $enlace = true se envía el
     * enlace de restablecer (60 min) y la contraseña actual deja de servir;
     * con false se guarda la que escribió el administrador y la persona la
     * cambia al entrar. En ambos casos se cierran sus sesiones.
     *
     * @return bool si el correo salió (solo con enlace)
     */
    public function restablecer(User $actor, User $usuario, ?string $contrasena, bool $enlace): bool
    {
        $this->exigirPuedeAdministrar($actor, $usuario, 'usuarios.editar');
        if (! $this->limitarAlcance(User::query()->whereKey($usuario->id), $actor, 'usuarios.editar')->exists()) {
            throw new AuthorizationException('Ese usuario está fuera de tu alcance.');
        }

        $usuario->forceFill([
            'password' => $enlace ? Str::password(40) : (string) $contrasena,
            'debe_cambiar_contrasena' => true,
            'actualizado_por' => $actor->id,
        ])->save();
        $this->cerrarSesiones($usuario);

        $enviado = $enlace && $this->recuperacion->enviarEnlace($usuario, RecuperacionContrasena::RESTABLECER, $actor);
        if (! $enlace) {
            $this->roles->auditar($actor, 'usuarios.actualizado', $usuario, null, ['contrasena' => 'cambiada', 'debe_cambiar_contrasena' => true]);
        }
        // C-36: la petición «Pedir a mi administrador» de esa cuenta queda atendida
        app(SolicitudesCuenta::class)->atendidas($usuario, SolicitudCuenta::CONTRASENA, $actor);

        return $enviado;
    }

    /**
     * @param  array{name: string, username: string, numero_colaborador: ?string, colaborador_id?: ?int, email: string, password?: ?string, activo: bool}  $datos
     */
    public function actualizar(User $actor, User $usuario, array $datos, Rol $rol, ?Sede $sede, array $otrasSedes = []): User
    {
        $this->exigirPuedeAdministrar($actor, $usuario, 'usuarios.editar');
        $this->exigirRolYSede($actor, (int) $usuario->empresa_id, $rol, $sede, 'usuarios.editar', $otrasSedes);

        $antes = $this->foto($usuario);
        if (! empty($datos['activo']) && ! $usuario->activo) {
            app(Limites::class)->exigirUsuario((int) $usuario->empresa_id);
        }

        // Varias sedes (ADR-0016): las sedes que el actor no administra se conservan tal cual
        $permitidas = $actor->es_superadmin ? null : $this->autorizador->sedesPermitidas($actor, 'usuarios.editar');
        if ($permitidas !== null && $sede !== null) {
            $fuera = UsuarioRol::where('user_id', $usuario->id)->whereNotNull('sede_id')->whereNotIn('sede_id', $permitidas)->pluck('sede_id')->all();
            $otrasSedes = [...$otrasSedes, ...Sede::withoutGlobalScopes()->where('empresa_id', $usuario->empresa_id)->whereIn('id', $fuera)->get()->all()];
        }

        DB::transaction(function () use ($actor, $usuario, $datos, $rol, $sede, $otrasSedes) {
            if (empty($datos['password'])) {
                unset($datos['password']);
            }

            $usuario->fill($datos);
            // Contraseña escrita por el administrador: la persona la cambia al entrar
            $usuario->forceFill(['actualizado_por' => $actor->id] + (isset($datos['password']) ? ['debe_cambiar_contrasena' => true] : []))->save();

            // Un rol por cuenta; una sede (o todas) y, si hace falta, otras sedes que también atiende (ADR-0016)
            UsuarioRol::where('user_id', $usuario->id)->delete();
            UsuarioRol::create(['user_id' => $usuario->id, 'rol_id' => $rol->id, 'sede_id' => $sede?->id]);
            $this->asignarOtrasSedes($usuario, $rol, $sede, $otrasSedes);
        });

        $this->autorizador->olvidar($usuario);

        if (! $usuario->activo || array_key_exists('password', $datos)) {
            $this->cerrarSesiones($usuario);
        }

        // La bitácora anota que la contraseña cambió, nunca su valor ni su hash
        $despues = $this->foto($usuario->fresh()) + (! empty($datos['password']) ? ['contrasena' => 'cambiada'] : []);
        $this->roles->auditar($actor, 'usuarios.actualizado', $usuario, $antes, $despues);
        // C-08: un cambio de rol, de sedes o una baja puede dejar un departamento sin quien autorice
        Cobertura::revisarDespues((int) $usuario->empresa_id ?: null);

        return $usuario;
    }

    public function cambiarEstado(User $actor, User $usuario, bool $activo): void
    {
        $this->exigirPuedeAdministrar($actor, $usuario, 'usuarios.eliminar');
        if ($activo && ! $usuario->activo) {
            app(Limites::class)->exigirUsuario((int) $usuario->empresa_id);
        }

        $usuario->forceFill(['activo' => $activo, 'actualizado_por' => $actor->id] + ($activo ? ['desactivado_por_baja_en' => null] : []))->save();

        if (! $activo) {
            $this->cerrarSesiones($usuario);
        }

        $this->roles->auditar($actor, $activo ? 'usuarios.reactivado' : 'usuarios.desactivado', $usuario, ['activo' => ! $activo], ['activo' => $activo]);
        Cobertura::revisarDespues((int) $usuario->empresa_id ?: null); // C-08
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

    /**
     * Mejoras estructura (ADR-0016): cuentas activas sin colaborador vinculado
     * cuyo rol pide pases de salida a su nombre («crear» con «Solo los
     * propios», como Solicitante o Jefe de departamento). Sin el vínculo no
     * pueden pedir pases ni firmar los de su departamento.
     *
     * @param  Builder<User>  $consulta
     * @return Builder<User>
     */
    /**
     * «Cuentas por revisar» de quien edita usuarios (criterio 1), la MISMA
     * consulta para la lista de Usuarios y para Mis pendientes (A-03): las
     * que ve (`usuarios.ver`) y puede editar (`usuarios.editar`), bloqueadas
     * ahora, dadas de baja junto con su colaborador en los últimos 7 días o
     * que piden pases sin colaborador vinculado. Las peticiones de «Pedir a
     * mi administrador» se cuentan aparte con SolicitudesCuenta::porAtender().
     */
    public function consultaPorRevisar(User $actor, int $empresaId): Builder
    {
        $visibles = $this->limitarAlcance(User::query()->where('users.empresa_id', $empresaId)->where('users.es_superadmin', false), $actor, 'usuarios.ver');

        return $this->limitarAlcance($visibles, $actor, 'usuarios.editar')
            ->where(fn ($q) => $q->where('users.bloqueado_hasta', '>', now())
                ->orWhere(fn ($b) => $b->where('users.activo', false)->where('users.desactivado_por_baja_en', '>=', now()->subDays(7)))
                // Mejoras estructura (ADR-0016): piden pases a su nombre y no tienen colaborador vinculado
                ->orWhere(fn ($v) => self::sinVinculoQuePidePases($v)));
    }

    public static function sinVinculoQuePidePases(Builder $consulta): Builder
    {
        return $consulta->where('users.activo', true)->whereNull('users.colaborador_id')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('usuario_roles as ur')
                ->join('roles as r', 'r.id', '=', 'ur.rol_id')
                ->join('rol_permisos as rp', 'rp.rol_id', '=', 'ur.rol_id')
                ->join('modulo_acciones as ma', 'ma.id', '=', 'rp.modulo_accion_id')
                ->join('modulos as m', 'm.id', '=', 'ma.modulo_id')
                ->join('acciones as a', 'a.id', '=', 'ma.accion_id')
                ->whereColumn('ur.user_id', 'users.id')->where('r.activo', true)
                ->where('m.clave', 'pases_salida')->where('a.clave', 'crear')->where('rp.alcance', Alcance::Propios->value));
    }

    /**
     * C-36 (DD-21): ¿el rol administra algo? (los mismos permisos que la
     * política de dos pasos llama «administradores»). Las cuentas de un rol
     * que no administra nada —las de caseta— pueden no tener correo: se
     * recuperan con «Pedir a mi administrador».
     */
    public static function rolAdministrativo(Rol $rol): bool
    {
        $claves = array_map(fn (string $p) => explode('.', $p, 2), VerificacionDosPasos::PERMISOS_ADMINISTRADOR);

        return DB::table('rol_permisos as rp')
            ->join('modulo_acciones as ma', 'ma.id', '=', 'rp.modulo_accion_id')
            ->join('acciones as a', 'a.id', '=', 'ma.accion_id')
            ->join('modulos as m', 'm.id', '=', 'ma.modulo_id')
            ->where('rp.rol_id', $rol->id)
            ->where(function ($q) use ($claves) {
                foreach ($claves as [$modulo, $accion]) {
                    $q->orWhere(fn ($w) => $w->where('m.clave', $modulo)->where('a.clave', $accion));
                }
            })->exists();
    }

    /**
     * A-06: cuentas activas sin correo cuyo rol ya administra algo (por
     * ejemplo, porque después de crearlas se le dieron permisos de
     * administración). Sin correo no pueden recuperar su cuenta solas ni
     * reciben avisos: la Matriz y Usuarios lo avisan. $rol limita a un rol.
     *
     * @return Collection<int, User>
     */
    public static function administrativasSinCorreo(int $empresaId, ?Rol $rol = null): Collection
    {
        $claves = array_map(fn (string $p) => explode('.', $p, 2), VerificacionDosPasos::PERMISOS_ADMINISTRADOR);
        $roles = DB::table('rol_permisos as rp')
            ->join('roles as r', 'r.id', '=', 'rp.rol_id')
            ->join('modulo_acciones as ma', 'ma.id', '=', 'rp.modulo_accion_id')
            ->join('acciones as a', 'a.id', '=', 'ma.accion_id')
            ->join('modulos as m', 'm.id', '=', 'ma.modulo_id')
            ->where('r.empresa_id', $empresaId)
            ->when($rol !== null, fn ($q) => $q->where('r.id', $rol->id))
            ->where(function ($q) use ($claves) {
                foreach ($claves as [$modulo, $accion]) {
                    $q->orWhere(fn ($w) => $w->where('m.clave', $modulo)->where('a.clave', $accion));
                }
            })->distinct()->pluck('r.id');
        if ($roles->isEmpty()) {
            return collect();
        }

        return User::query()->where('empresa_id', $empresaId)->where('activo', true)->where('es_superadmin', false)
            ->where(fn ($q) => $q->whereNull('email')->orWhere('email', ''))
            ->whereIn('id', DB::table('usuario_roles')->whereIn('rol_id', $roles)->select('user_id'))
            ->orderBy('name')->get(['id', 'name', 'username']);
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

    /**
     * Otras sedes que la cuenta también atiende (ADR-0016): el mismo rol en
     * cada sede. Con «todas las sedes» no hacen falta.
     *
     * @param  list<Sede>  $otrasSedes
     */
    private function asignarOtrasSedes(User $usuario, Rol $rol, ?Sede $sede, array $otrasSedes): void
    {
        if ($sede === null) {
            return;
        }
        foreach (collect($otrasSedes)->unique('id')->reject(fn (Sede $s) => $s->id === $sede->id) as $otra) {
            UsuarioRol::firstOrCreate(['user_id' => $usuario->id, 'rol_id' => $rol->id, 'sede_id' => $otra->id]);
        }
    }

    /**
     * @param  list<Sede>  $otrasSedes
     */
    private function exigirRolYSede(User $actor, int $empresaId, Rol $rol, ?Sede $sede, string $permiso, array $otrasSedes = []): void
    {
        foreach ($otrasSedes as $otra) {
            if ((int) $otra->empresa_id !== $empresaId) {
                throw new AuthorizationException('La sede pertenece a otra empresa.');
            }
        }

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
        foreach ($otrasSedes as $otra) {
            if ($sedes !== null && ! in_array((int) $otra->id, $sedes, true)) {
                throw new AuthorizationException('Solo puedes asignar cuentas a tus sedes: «'.$otra->nombre.'» no es una de ellas.');
            }
        }
    }

    /**
     * Desactivar o cambiar la contraseña corta las sesiones abiertas de inmediato.
     */
    private function cerrarSesiones(User $usuario): void
    {
        AvisosCuenta::cerrarSesiones($usuario);
    }

    /**
     * @return array<string, mixed>
     */
    private function foto(User $usuario): array
    {
        $asignaciones = UsuarioRol::where('user_id', $usuario->id)->orderBy('id')->get(['rol_id', 'sede_id']);
        $asignacion = $asignaciones->first();
        $otras = $asignaciones->slice(1)->pluck('sede_id')->filter()->map(fn ($id) => (int) $id)->values()->all();

        return $usuario->only(self::CAMPOS_AUDITADOS) + [
            'rol_id' => $asignacion?->rol_id,
            'sede_id' => $asignacion?->sede_id,
        ] + ($otras === [] ? [] : ['otras_sedes' => $otras]);
    }
}
