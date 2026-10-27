<?php

namespace App\Services\Permisos;

use App\Models\Sede;
use App\Models\Suscripcion;
use App\Models\User;
use App\Support\DependenciasModulos;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Decide si un usuario puede ejecutar "modulo.accion", con tres filtros:
 *   1. el modulo (y su modulo padre) esta activo para la empresa del usuario;
 *   2. alguno de sus roles activos tiene la accion;
 *   3. el registro, si se indica, esta dentro de su alcance (propios, sede, empresa).
 * El Super Administrador de la plataforma pasa siempre.
 */
class Autorizador
{
    /**
     * Acciones que siguen valiendo en un módulo en SOLO LECTURA (el plan ya
     * no lo trae, DD-05) y en una suscripción en solo consulta.
     */
    public const ACCIONES_LECTURA = ['ver', 'exportar', 'imprimir'];

    /** En solo consulta el administrador aún pide la renovación desde «Tu suscripción». */
    public const PERMITIDAS_EN_SOLO_CONSULTA = ['empresas.editar'];

    /** @var array<int, array<string, PermisoEfectivo>> */
    private array $cache = [];

    /** @var array<int, array<string, bool>> módulos activos por empresa */
    private array $activos = [];

    /** @var array<int, array<string, int>> módulos en solo lectura por empresa */
    private array $soloLectura = [];

    /** @var array<string, int>|null */
    private ?array $claves = null;

    private ?bool $columnaSoloLectura = null;

    private ?bool $tablaSuscripciones = null;

    /** @var array<int, bool> suscripción en solo consulta por empresa */
    private array $soloConsulta = [];

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
        $conLectura = $this->hayColumnaSoloLectura();

        $filas = DB::table('usuario_roles as ur')
            ->join('roles as r', 'r.id', '=', 'ur.rol_id')
            ->join('rol_permisos as rp', 'rp.rol_id', '=', 'r.id')
            ->join('modulo_acciones as ma', 'ma.id', '=', 'rp.modulo_accion_id')
            ->join('acciones as a', 'a.id', '=', 'ma.accion_id')
            ->join('modulos as m', 'm.id', '=', 'ma.modulo_id')
            ->join('empresa_modulos as em', function ($join) use ($empresaId, $conLectura) {
                $join->on('em.modulo_id', '=', 'm.id')
                    ->where('em.empresa_id', '=', $empresaId)
                    ->where(fn ($q) => $this->activoOLectura($q, 'em', $conLectura));
            })
            ->leftJoin('modulos as mp', 'mp.id', '=', 'm.padre_id')
            ->leftJoin('empresa_modulos as emp', function ($join) use ($empresaId, $conLectura) {
                $join->on('emp.modulo_id', '=', 'm.padre_id')
                    ->where('emp.empresa_id', '=', $empresaId)
                    ->where(fn ($q) => $this->activoOLectura($q, 'emp', $conLectura));
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

        // Acciones que se venden con otro módulo (C-01): el expediente y los datos personales son de RR. HH.
        foreach (DependenciasModulos::ACCIONES_CON_MODULO as $accion => $requiere) {
            if (isset($acumulado[$accion]) && ! $this->moduloActivo((int) $empresaId, $requiere)) {
                unset($acumulado[$accion]);
            }
        }
        // Suscripción en solo consulta (A-17): solo quedan las acciones de consulta, para que no se
        // muestren botones de alta y edición que luego fallan. El middleware SoloConsulta sigue siendo la regla.
        if ($this->empresaEnSoloConsulta($usuario)) {
            $acumulado = array_filter($acumulado, fn ($_, $clave) => in_array(substr($clave, strrpos($clave, '.') + 1), self::ACCIONES_LECTURA, true)
                || in_array($clave, self::PERMITIDAS_EN_SOLO_CONSULTA, true), ARRAY_FILTER_USE_BOTH);
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

    /**
     * ¿Usa el permiso sobre TODA la empresa? Alcance de empresa, o de sede
     * sin limitarse a ninguna. "Solo los propios" nunca cuenta como toda la
     * empresa, aunque su asignación no tenga sede (sedesPermitidas() da null
     * en ese caso porque solo habla de sedes).
     */
    public function alcanceDeEmpresa(User $usuario, string $permiso): bool
    {
        if ($usuario->es_superadmin) {
            return true;
        }

        $efectivo = $this->permisosEfectivos($usuario)[$permiso] ?? null;

        return $efectivo !== null
            && $efectivo->alcance !== Alcance::Propios
            && $this->sedesPermitidas($usuario, $permiso) === null;
    }

    /**
     * ¿El permiso es "Solo los propios"?
     */
    public function soloPropios(User $usuario, string $permiso): bool
    {
        return ! $usuario->es_superadmin
            && ($this->permisosEfectivos($usuario)[$permiso] ?? null)?->alcance === Alcance::Propios;
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

    /**
     * ¿El módulo está activo (contratado) para la empresa? Un submódulo
     * necesita también a su padre. Solo lectura no cuenta como activo.
     */
    public function moduloActivo(int $empresaId, string $clave): bool
    {
        if (! isset($this->activos[$empresaId])) {
            $filas = DB::table('empresa_modulos as em')->join('modulos as m', 'm.id', '=', 'em.modulo_id')
                ->where('em.empresa_id', $empresaId)->where('em.activo', true)->where('m.activo', true)
                ->get(['m.clave', 'm.padre_id', 'm.id']);
            $ids = $filas->pluck('id')->map(fn ($id) => (int) $id)->all();
            $this->activos[$empresaId] = $filas->filter(fn ($f) => $f->padre_id === null || in_array((int) $f->padre_id, $ids, true))
                ->mapWithKeys(fn ($f) => [$f->clave => true])->all();
        }

        return isset($this->activos[$empresaId][$clave]);
    }

    /**
     * ¿El módulo está en solo lectura para la empresa (su plan ya no lo trae)?
     */
    public function moduloEnSoloLectura(int $empresaId, string $clave): bool
    {
        if (! $this->hayColumnaSoloLectura()) {
            return false;
        }
        $this->soloLectura[$empresaId] ??= DB::table('empresa_modulos as em')->join('modulos as m', 'm.id', '=', 'em.modulo_id')
            ->where('em.empresa_id', $empresaId)->where('em.activo', false)->where('em.solo_lectura', true)->pluck('m.clave')->flip()->all();

        return isset($this->soloLectura[$empresaId][$clave]);
    }

    /** ¿Existe el módulo en el catálogo? (para no confundir habilidades que no son de un módulo) */
    public function esModulo(string $clave): bool
    {
        $this->claves ??= DB::table('modulos')->pluck('clave')->flip()->all();

        return isset($this->claves[$clave]);
    }

    /** @param  Builder  $q */
    private function activoOLectura($q, string $alias, bool $conLectura): void
    {
        $q->where($alias.'.activo', '=', true);
        if ($conLectura) {
            $q->orWhere(fn ($l) => $l->where($alias.'.solo_lectura', '=', true)->whereIn('a.clave', self::ACCIONES_LECTURA));
        }
    }

    public function empresaEnSoloConsulta(User $usuario): bool
    {
        // Sin suscripción (o sin la tabla, en instalaciones viejas): nunca
        $empresa = $usuario->relationLoaded('empresa') ? $usuario->empresa : null;
        if ($empresa !== null && array_key_exists('suscripcion_id', $empresa->getAttributes()) && $empresa->getAttributes()['suscripcion_id'] === null) {
            return false;
        }
        if ($usuario->empresa_id === null || ! ($this->tablaSuscripciones ??= Schema::hasTable('suscripciones'))) {
            return false;
        }

        // Una consulta por empresa y petición (se olvida con olvidar())
        return $this->soloConsulta[(int) $usuario->empresa_id] ??= Suscripcion::query()->where('empresa_id', $usuario->empresa_id)->first()?->soloConsulta() ?? false;
    }

    private function hayColumnaSoloLectura(): bool
    {
        return $this->columnaSoloLectura ??= Schema::hasColumn('empresa_modulos', 'solo_lectura');
    }

    public static function olvidarEsquema(): void
    {
        $yo = app(self::class);
        $yo->columnaSoloLectura = null;
        $yo->tablaSuscripciones = null;
    }

    public function olvidar(?User $usuario = null): void
    {
        $this->activos = [];
        $this->soloLectura = [];
        $this->soloConsulta = [];
        if ($usuario === null) {
            $this->cache = [];

            return;
        }

        unset($this->cache[$usuario->id]);
    }
}
