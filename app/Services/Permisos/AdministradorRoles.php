<?php

namespace App\Services\Permisos;

use App\Models\Auditoria;
use App\Models\ModuloAccion;
use App\Models\Rol;
use App\Models\RolPermiso;
use App\Models\Sede;
use App\Models\User;
use App\Models\UsuarioRol;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Cambios a roles y asignaciones, con las reglas contra escalamiento:
 * - nadie otorga un permiso que no tiene (ni con mayor alcance que el suyo);
 * - nadie edita roles o usuarios de nivel igual o superior al suyo;
 * - todo queda en la bitacora de auditoria.
 */
class AdministradorRoles
{
    public function __construct(private readonly Autorizador $autorizador) {}

    /**
     * Reemplaza los permisos de un rol.
     *
     * @param  array<string, Alcance|string>  $permisos  "modulo.accion" => alcance
     */
    public function sincronizarPermisos(User $actor, Rol $rol, array $permisos): void
    {
        $this->exigirPuedeAdministrarRol($actor, $rol, 'permisos.editar');

        $resueltos = [];
        foreach ($permisos as $clave => $alcance) {
            $resueltos[$clave] = $alcance instanceof Alcance ? $alcance : Alcance::from($alcance);
        }

        $antes = $this->clavesDe($rol);

        // Solo se valida lo que cambia: lo que ya tenia el rol (otorgado por
        // alguien de mayor nivel) puede quedarse aunque el actor no lo tenga.
        if (! $actor->es_superadmin) {
            $propios = $this->autorizador->permisosEfectivos($actor);

            foreach (array_unique([...array_keys($resueltos), ...array_keys($antes)]) as $clave) {
                $nuevo = $resueltos[$clave] ?? null;
                $previo = isset($antes[$clave]) ? Alcance::from($antes[$clave]) : null;

                if ($nuevo === $previo) {
                    continue;
                }

                $requerido = $nuevo !== null && $previo !== null ? Alcance::mayor($nuevo, $previo) : ($nuevo ?? $previo);
                $propio = $propios[$clave] ?? null;

                if ($propio === null || ! $propio->alcance->cubre($requerido)) {
                    throw new AuthorizationException("No puedes cambiar «{$clave}»: no tienes ese permiso con alcance «{$requerido->etiqueta()}».");
                }
            }
        }

        $ids = [];
        foreach ($resueltos as $clave => $alcance) {
            $ids[$this->resolver($clave)->id] = $alcance;
        }
        $resueltos = $ids;

        DB::transaction(function () use ($rol, $resueltos): void {
            RolPermiso::where('rol_id', $rol->id)
                ->whereNotIn('modulo_accion_id', array_keys($resueltos))
                ->delete();

            foreach ($resueltos as $moduloAccionId => $alcance) {
                RolPermiso::updateOrCreate(
                    ['rol_id' => $rol->id, 'modulo_accion_id' => $moduloAccionId],
                    ['alcance' => $alcance],
                );
            }
        });

        $this->autorizador->olvidar();
        $this->auditar($actor, 'permisos.rol_actualizado', $rol, $antes, $this->clavesDe($rol));
    }

    /**
     * @param  array{nombre: string, descripcion?: ?string, nivel_jerarquia: int, activo?: bool}  $datos
     */
    public function crearRol(User $actor, ?int $empresaId, array $datos): Rol
    {
        $rol = new Rol(['empresa_id' => $empresaId, ...$datos]);
        $this->exigirPuedeAdministrarRol($actor, $rol, 'roles.crear');

        $rol->save();
        $this->auditar($actor, 'roles.creado', $rol, null, $rol->only(['nombre', 'descripcion', 'nivel_jerarquia', 'activo']));

        return $rol;
    }

    /**
     * @param  array{nombre: string, descripcion?: ?string, nivel_jerarquia: int, activo?: bool}  $datos
     */
    public function actualizarRol(User $actor, Rol $rol, array $datos): Rol
    {
        $this->exigirPuedeAdministrarRol($actor, $rol, 'roles.editar');

        $antes = $rol->only(['nombre', 'descripcion', 'nivel_jerarquia', 'activo']);
        $rol->fill($datos);
        // Tampoco se puede subir un rol a un nivel igual o superior al propio
        $this->exigirPuedeAdministrarRol($actor, $rol, 'roles.editar');

        $rol->save();
        $this->autorizador->olvidar();
        $this->auditar($actor, 'roles.actualizado', $rol, $antes, $rol->only(array_keys($antes)));

        return $rol;
    }

    public function eliminarRol(User $actor, Rol $rol): void
    {
        $this->exigirPuedeAdministrarRol($actor, $rol, 'roles.eliminar');

        if (UsuarioRol::where('rol_id', $rol->id)->exists()) {
            throw new \DomainException('No puedes eliminar un rol que todavía tiene usuarios asignados; reasígnalos primero.');
        }

        $antes = $rol->only(['nombre', 'descripcion', 'nivel_jerarquia', 'activo']);
        $rol->delete();
        $this->autorizador->olvidar();
        $this->auditar($actor, 'roles.eliminado', $rol, $antes, null);
    }

    public function asignarRol(User $actor, User $usuario, Rol $rol, ?Sede $sede = null): UsuarioRol
    {
        $this->exigirPuedeAdministrarRol($actor, $rol, 'usuarios.editar');
        $this->exigirPuedeAdministrarUsuario($actor, $usuario);

        if ($rol->empresa_id !== null && (int) $rol->empresa_id !== (int) $usuario->empresa_id) {
            throw new AuthorizationException('El rol pertenece a otra empresa.');
        }

        if ($sede !== null && (int) $sede->empresa_id !== (int) $usuario->empresa_id) {
            throw new AuthorizationException('La sede pertenece a otra empresa.');
        }

        $asignacion = UsuarioRol::firstOrCreate([
            'user_id' => $usuario->id,
            'rol_id' => $rol->id,
            'sede_id' => $sede?->id,
        ]);

        $this->autorizador->olvidar($usuario);
        $this->auditar($actor, 'usuarios.rol_asignado', $usuario, null, [
            'rol_id' => $rol->id, 'sede_id' => $sede?->id,
        ]);

        return $asignacion;
    }

    public function exigirPuedeAdministrarRol(User $actor, Rol $rol, string $permiso): void
    {
        if ($actor->es_superadmin) {
            return;
        }

        if (! $this->autorizador->puede($actor, $permiso)) {
            throw new AuthorizationException("No tienes el permiso «{$permiso}».");
        }

        if ($rol->esPlantilla() || (int) $rol->empresa_id !== (int) $actor->empresa_id) {
            throw new AuthorizationException('Solo puedes administrar roles de tu empresa.');
        }

        $nivelPropio = $actor->nivelJerarquia();
        if ($rol->nivel_jerarquia <= $nivelPropio) {
            throw new AuthorizationException(sprintf(
                'Tu nivel es %d: solo puedes crear o administrar roles de nivel %d en adelante (número mayor = menos autoridad). El nivel %d es igual o superior al tuyo.',
                $nivelPropio, $nivelPropio + 1, $rol->nivel_jerarquia,
            ));
        }
    }

    public function exigirPuedeAdministrarUsuario(User $actor, User $usuario): void
    {
        if ($actor->es_superadmin) {
            return;
        }

        if ($usuario->es_superadmin || (int) $usuario->empresa_id !== (int) $actor->empresa_id) {
            throw new AuthorizationException('No puedes administrar ese usuario.');
        }

        if ($usuario->id !== $actor->id && $usuario->nivelJerarquia() <= $actor->nivelJerarquia()) {
            throw new AuthorizationException('No puedes administrar a un usuario de nivel igual o superior al tuyo.');
        }
    }

    private function resolver(string $clave): ModuloAccion
    {
        [$modulo, $accion] = array_pad(explode('.', $clave, 2), 2, null);

        $moduloAccion = ModuloAccion::query()
            ->whereHas('modulo', fn ($q) => $q->where('clave', $modulo))
            ->whereHas('accion', fn ($q) => $q->where('clave', $accion))
            ->first();

        if ($moduloAccion === null) {
            throw new \InvalidArgumentException("El permiso «{$clave}» no existe en el catalogo.");
        }

        return $moduloAccion;
    }

    /**
     * @return array<string, string>
     */
    private function clavesDe(Rol $rol): array
    {
        return RolPermiso::with('moduloAccion.modulo', 'moduloAccion.accion')
            ->where('rol_id', $rol->id)
            ->get()
            ->mapWithKeys(fn (RolPermiso $p) => [$p->moduloAccion->clave() => $p->alcance->value])
            ->sort()
            ->all();
    }

    public function auditar(User $actor, string $evento, Model $sujeto, ?array $antes, ?array $despues): void
    {
        Auditoria::create([
            'empresa_id' => $sujeto->getAttributes()['empresa_id'] ?? $actor->empresa_id,
            'user_id' => $actor->id,
            'evento' => $evento,
            'auditable_type' => $sujeto::class,
            'auditable_id' => $sujeto->getKey(),
            'antes' => $antes,
            'despues' => $despues,
            'ip' => request()?->ip(),
        ]);
    }
}
