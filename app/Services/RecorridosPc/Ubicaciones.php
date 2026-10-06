<?php

namespace App\Services\RecorridosPc;

use App\Models\Espacio;
use Illuminate\Support\Collection;

/**
 * Ubicaciones de Zonas y áreas para Protección Civil (SEGCAT: edificios,
 * secciones y áreas específicas): zona (edificio), piso y área específica.
 * Todo con una sola consulta por pantalla.
 */
class Ubicaciones
{
    /** Niveles donde se instala un equipo. */
    public const NIVELES = [Espacio::EDIFICIO, Espacio::AREA, Espacio::AREA_ESPECIFICA];

    /**
     * Nodos de las sedes indicadas (null = todas las de la empresa), con su
     * texto completo: "Torre A · Piso 1 · Cocina".
     *
     * @param  list<int>|null  $sedes
     * @return Collection<int, array{id: int, sede_id: int, nivel: string, texto: string, nombre: string, ancestros: list<int>, activo: bool}>
     */
    public function nodos(?array $sedes): Collection
    {
        if ($sedes === []) {
            return collect();
        }
        $filas = Espacio::whereIn('nivel', self::NIVELES)
            ->when($sedes !== null, fn ($q) => $q->whereIn('sede_id', $sedes))
            ->orderBy('profundidad')->orderBy('orden')->orderBy('nombre')
            ->get(['id', 'sede_id', 'padre_id', 'nivel', 'nombre', 'ruta', 'activo', 'orden', 'profundidad']);
        $nombres = $filas->pluck('nombre', 'id');

        return $filas->mapWithKeys(function (Espacio $e) use ($nombres) {
            $ancestros = $e->idsAncestros();
            $partes = [...array_map(fn ($id) => $nombres[$id] ?? null, $ancestros), $e->nombre];

            return [$e->id => [
                'id' => $e->id, 'sede_id' => (int) $e->sede_id, 'nivel' => $e->nivel, 'nombre' => (string) $e->nombre,
                'texto' => implode(' · ', array_filter($partes, fn ($p) => $p !== null && $p !== '')),
                'ancestros' => $ancestros, 'activo' => (bool) $e->activo,
            ]];
        })->sortBy('texto', SORT_NATURAL | SORT_FLAG_CASE);
    }

    /**
     * Texto completo de un nodo suelto (lector, ticket).
     */
    public function texto(Espacio $espacio): string
    {
        $ancestros = $espacio->idsAncestros();
        $nombres = $ancestros === [] ? collect() : Espacio::whereIn('id', $ancestros)->pluck('nombre', 'id');
        $partes = [...array_map(fn ($id) => $nombres[$id] ?? null, $ancestros), $espacio->nombre];

        return implode(' · ', array_filter($partes, fn ($p) => $p !== null && $p !== ''));
    }

    /**
     * Ids del nodo y de todo lo que cuelga de él (para "equipos de esta zona").
     *
     * @return list<int>
     */
    public function subarbol(Espacio $espacio): array
    {
        return Espacio::where(fn ($q) => $q->whereKey($espacio->id)->orWhere('ruta', 'like', $espacio->ruta.'%'))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
