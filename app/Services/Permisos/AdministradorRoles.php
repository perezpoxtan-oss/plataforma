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
            $alcance = $alcance instanceof Alcance ? $alcance : Alcance::from($alcance);
            $moduloAccion = $this->resolver($clave);

            if (! $actor->es_superadmin) {
                $propio = $this->autorizador->permisosEfectivos($actor)[$clave] ?? null;

                if ($propio === null || ! $propio->alcance->cubre($alcance)) {
                    throw new AuthorizationException("No puedes otorgar «{$clave}» con alcance «{$alcance->value}»: no lo tienes.");
                }
            }

            $resueltos[$moduloAccion->id] = $alcance;
        }

        $antes = $this->clavesDe($rol);

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

        if ($rol->nivel_jerarquia <= $actor->nivelJerarquia()) {
            throw new AuthorizationException('No puedes administrar un rol de nivel igual o superior al tuyo.');
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

    private function auditar(User $actor, string $evento, Model $sujeto, ?array $antes, ?array $despues): void
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
