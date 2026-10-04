<?php

namespace App\Services\Permisos;

use App\Models\Sede;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Decide si un usuario puede ejecutar "modulo.accion", con tres filtros:
 *   1. el modulo (y su modulo padre) esta activo para la empresa del usuario;
 *   2. alguno de sus roles activos tiene la accion;
 *   3. el registro, si se indica, esta dentro de su alcance (propios, sede, empresa).
 * El Super Administrador de la plataforma pasa siempre.
 */
class Autorizador
{
    /** @var array<int, array<string, PermisoEfectivo>> */
    private array $cache = [];

    public function puede(User $usuario, string $permiso, ?Model $registro = null): bool
    {
        if (! $usuario->activo) {
            return false;
        }

        if ($usuario->es_superadmin) {
            return true;
        }

        $efectivo = $this->permisosEfectivos($usuario)[$permiso] ?? null;

        if ($efectivo === null) {
            return false;
        }

        return $registro === null || $this->dentroDeAlcance($usuario, $efectivo, $registro);
    }

    /**
     * Todos los permisos del usuario, clave "modulo.accion".
     *
     * @return array<string, PermisoEfectivo>
     */
    public function permisosEfectivos(User $usuario): array
    {
        if (isset($this->cache[$usuario->id])) {
            return $this->cache[$usuario->id];
        }

        if ($usuario->empresa_id === null) {
            return $this->cache[$usuario->id] = [];
        }

        $empresaId = $usuario->empresa_id;

        $filas = DB::table('usuario_roles as ur')
            ->join('roles as r', 'r.id', '=', 'ur.rol_id')
            ->join('rol_permisos as rp', 'rp.rol_id', '=', 'r.id')
            ->join('modulo_acciones as ma', 'ma.id', '=', 'rp.modulo_accion_id')
            ->join('acciones as a', 'a.id', '=', 'ma.accion_id')
            ->join('modulos as m', 'm.id', '=', 'ma.modulo_id')
            ->join('empresa_modulos as em', function ($join) use ($empresaId) {
                $join->on('em.modulo_id', '=', 'm.id')
                    ->where('em.empresa_id', '=', $empresaId)
                    ->where('em.activo', '=', true);
            })
            ->leftJoin('modulos as mp', 'mp.id', '=', 'm.padre_id')
            ->leftJoin('empresa_modulos as emp', function ($join) use ($empresaId) {
                $join->on('emp.modulo_id', '=', 'm.padre_id')
                    ->where('emp.empresa_id', '=', $empresaId)
                    ->where('emp.activo', '=', true);
            })
            ->where('ur.user_id', $usuario->id)
            ->where('r.activo', true)
            ->where('m.activo', true)
            ->where(fn ($q) => $q->where('r.empresa_id', $empresaId)->orWhereNull('r.empresa_id'))
            // Un submodulo solo vale si su modulo padre tambien esta activo
            ->where(fn ($q) => $q->whereNull('m.padre_id')
                ->orWhere(fn ($q2) => $q2->where('mp.activo', true)->whereNotNull('emp.id')))
            ->select(['m.clave as modulo', 'a.clave as accion', 'rp.alcance', 'ur.sede_id'])
            ->get();

        /** @var array<string, array{alcance: Alcance, todas: bool, sedes: array<int, true>}> $acumulado */
        $acumulado = [];

        foreach ($filas as $fila) {
            $clave = $fila->modulo.'.'.$fila->accion;
            $alcance = Alcance::from($fila->alcance);
            $actual = $acumulado[$clave] ?? ['alcance' => $alcance, 'todas' => false, 'sedes' => []];

            $actual['alcance'] = Alcance::mayor($actual['alcance'], $alcance);

            if ($fila->sede_id === null) {
                $actual['todas'] = true;
            } else {
                $actual['sedes'][(int) $fila->sede_id] = true;
            }

            $acumulado[$clave] = $actual;
        }

        $resultado = [];
        foreach ($acumulado as $clave => $datos) {
            $resultado[$clave] = new PermisoEfectivo(
                $datos['alcance'],
                $datos['todas'] ? null : array_keys($datos['sedes']),
            );
        }

        return $this->cache[$usuario->id] = $resultado;
    }

    /**
     * Sedes en las que el usuario puede usar un permiso: null = todas las de
     * su empresa; [] = ninguna.
     *
     * @return list<int>|null
     */
    public function sedesPermitidas(User $usuario, string $permiso): ?array
    {
        if ($usuario->es_superadmin) {
            return null;
        }

        $efectivo = $this->permisosEfectivos($usuario)[$permiso] ?? null;

        return match (true) {
            $efectivo === null => [],
            $efectivo->alcance === Alcance::Empresa, $efectivo->sedes === null => null,
            default => array_values($efectivo->sedes),
        };
    }

    public function dentroDeAlcance(User $usuario, PermisoEfectivo $efectivo, Model $registro): bool
    {
        $empresaRegistro = $this->atributo($registro, 'empresa_id');

        // Nunca fuera de su empresa, sin importar el alcance
        if ($empresaRegistro !== null && (int) $empresaRegistro !== (int) $usuario->empresa_id) {
            return false;
        }

        return match ($efectivo->alcance) {
            Alcance::Empresa => true,
            Alcance::Sede => $this->enSusSedes($efectivo, $this->sedeDe($registro)),
            Alcance::Propios => (int) $this->atributo($registro, 'creado_por') === (int) $usuario->id
                && $this->enSusSedes($efectivo, $this->sedeDe($registro)),
        };
    }

    private function enSusSedes(PermisoEfectivo $efectivo, ?int $sedeId): bool
    {
        if ($efectivo->sedes === null) {
            return true;
        }

        return $sedeId !== null && in_array($sedeId, $efectivo->sedes, true);
    }

    private function sedeDe(Model $registro): ?int
    {
        if ($registro instanceof Sede) {
            return (int) $registro->getKey();
        }

        $sede = $this->atributo($registro, 'sede_id');

        return $sede === null ? null : (int) $sede;
    }

    /**
     * Lee un atributo solo si el modelo lo tiene (compatible con el modo estricto).
     */
    private function atributo(Model $registro, string $clave): mixed
    {
        return array_key_exists($clave, $registro->getAttributes()) ? $registro->getAttributes()[$clave] : null;
    }

    public function olvidar(?User $usuario = null): void
    {
        if ($usuario === null) {
            $this->cache = [];

            return;
        }

        unset($this->cache[$usuario->id]);
    }
}
