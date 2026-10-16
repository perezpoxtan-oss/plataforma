<?php

namespace App\Services\Reservas;

use App\Models\Espacio;
use App\Models\ReservaArea;
use Illuminate\Support\Collection;

/**
 * Niveles de las áreas reservables (docs/tecnico/reservas.md, «Espacios
 * interiores»). Las áreas salen de Zonas y edificios, así que su jerarquía es
 * la del árbol de espacios (ruta materializada «/1/5/12/»): una zona marcada
 * «por sus espacios interiores» agrupa a las áreas que cuelgan de ella.
 *
 * Solo lee: no cambia nada en Zonas y edificios.
 */
class NivelesReservas
{
    /**
     * Para cada área, el id del área reservable más cercana que la contiene
     * (null = primer nivel de la sede). Las áreas deben traer su espacio (ruta).
     *
     * @param  Collection<int, ReservaArea>  $areas
     * @return array<int, int|null> área => área contenedora
     */
    public function padres(Collection $areas): array
    {
        $porEspacio = $areas->keyBy(fn (ReservaArea $a) => (int) $a->espacio_id);
        $padres = [];
        foreach ($areas as $a) {
            $padres[(int) $a->id] = null;
            foreach (array_reverse($a->espacio?->idsAncestros() ?? []) as $ancestro) {
                if ($porEspacio->has($ancestro)) {
                    $padres[(int) $a->id] = (int) $porEspacio[$ancestro]->id;
                    break;
                }
            }
        }

        return $padres;
    }

    /**
     * Áreas que cuelgan directamente de $padre (null = primer nivel).
     *
     * @param  Collection<int, ReservaArea>  $areas
     * @param  array<int, int|null>  $padres
     * @return Collection<int, ReservaArea>
     */
    public function hijas(Collection $areas, array $padres, ?int $padre): Collection
    {
        return $areas->filter(fn (ReservaArea $a) => ($padres[(int) $a->id] ?? null) === $padre)->values();
    }

    /**
     * Todas las áreas que cuelgan de $id, a cualquier profundidad.
     *
     * @param  array<int, int|null>  $padres
     * @return list<int>
     */
    public function descendientes(array $padres, int $id): array
    {
        $r = [];
        $pendientes = [$id];
        while ($pendientes !== []) {
            $actual = array_shift($pendientes);
            foreach ($padres as $hija => $padre) {
                if ($padre === $actual) {
                    $r[] = $hija;
                    $pendientes[] = $hija;
                }
            }
        }

        return $r;
    }

    /** Área de primer nivel que contiene a $id (o ella misma). */
    public function raiz(array $padres, int $id): int
    {
        $visto = [];
        while (($padres[$id] ?? null) !== null && ! isset($visto[$id])) {
            $visto[$id] = true;
            $id = (int) $padres[$id];
        }

        return $id;
    }

    /**
     * Espacios que comparten lugar con éste: él mismo, los que lo contienen y
     * los que tiene dentro. Reservar uno ocupa a los demás en ese horario
     * (bloqueo cruzado entre una zona completa y sus espacios interiores).
     *
     * @return list<int>
     */
    public function espaciosRelacionados(Espacio $espacio): array
    {
        $dentro = Espacio::where('ruta', 'like', $espacio->ruta.'%')->pluck('id')->map(fn ($id) => (int) $id)->all();

        return array_values(array_unique([(int) $espacio->id, ...$espacio->idsAncestros(), ...$dentro]));
    }

    /**
     * Espacios interiores que se marcan cuando una zona se reserva «por sus
     * espacios interiores»: las habitaciones o salas (nivel área específica)
     * que tenga a cualquier profundidad; si no tiene, sus espacios del nivel
     * de abajo (los pisos de una zona sin habitaciones, o las áreas de una
     * habitación). Así una zona con pisos y habitaciones marca las
     * habitaciones, no los pisos ni las áreas internas de cada habitación.
     *
     * @return Collection<int, Espacio>
     */
    public function interioresFinales(Espacio $zona): Collection
    {
        $dentro = Espacio::where('ruta', 'like', $zona->ruta.'%')->whereKeyNot($zona->id)
            ->where('activo', true)->whereIn('nivel', CatalogosReservas::NIVELES)
            ->orderBy('ruta')->get(['id', 'empresa_id', 'sede_id', 'padre_id', 'nivel', 'nombre', 'ruta', 'activo']);
        $habitaciones = $dentro->where('nivel', Espacio::AREA_ESPECIFICA);
        if ($zona->nivel !== Espacio::AREA_ESPECIFICA && $habitaciones->isNotEmpty()) {
            return $habitaciones->values();
        }

        return $dentro->where('padre_id', $zona->id)->values();
    }

    /** ¿Tiene espacios interiores de nivel permitido? */
    public function tieneInteriores(Espacio $zona): bool
    {
        return Espacio::where('ruta', 'like', $zona->ruta.'%')->whereKeyNot($zona->id)
            ->where('activo', true)->whereIn('nivel', CatalogosReservas::NIVELES)->exists();
    }
}
