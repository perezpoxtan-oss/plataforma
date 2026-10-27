<?php

namespace App\Services\Espacios;

use App\Models\Colaborador;
use App\Models\Espacio;
use Illuminate\Support\Collection;

/**
 * C-30 (regla 2: un dato se captura una vez): los lugares se eligen del árbol
 * de Zonas y edificios con sugerencias, en lugar de escribirse a mano. Una
 * sola búsqueda para la caseta (habitación, vivienda y área de trabajo), los
 * incidentes (dónde pasó), los residentes (su vivienda) y las reservas.
 *
 * Cada lugar se devuelve con su camino legible: «Torre A › Piso 1 › 101».
 */
class Lugares
{
    /** Niveles que se ofrecen como «lugar» (todo el árbol). */
    public const TODOS = [Espacio::EDIFICIO, Espacio::AREA, Espacio::AREA_ESPECIFICA, Espacio::SUBAREA, Espacio::ELEMENTO];

    /** Habitación / oficina / vivienda: el nivel «área específica». */
    public const ESPECIFICOS = [Espacio::AREA_ESPECIFICA];

    /** Separador del camino. */
    public const SEPARADOR = ' › ';

    /**
     * Lugares activos de unas sedes (para listas cortas, p. ej. las viviendas).
     *
     * @param  list<int>|null  $sedes  null = todas las de la empresa activa
     * @param  list<string>  $niveles
     * @return Collection<int, array{id: int, sede_id: int, nivel: string, nombre: string, ruta: string}>
     */
    public function opciones(?array $sedes, array $niveles = self::ESPECIFICOS, int $limite = 3000): Collection
    {
        $lugares = Espacio::where('activo', true)->whereIn('nivel', $niveles)
            ->when($sedes !== null, fn ($q) => $q->whereIn('sede_id', $sedes === [] ? [0] : $sedes))
            ->orderBy('ruta')->limit($limite)->get(['id', 'sede_id', 'nivel', 'nombre', 'ruta']);

        return $this->conCamino($lugares)->sortBy('ruta', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    /**
     * Sugerencias al escribir: por nombre o código, en las sedes dadas.
     *
     * @param  list<int>|null  $sedes
     * @param  list<string>  $niveles
     * @return Collection<int, array{id: int, sede_id: int, nivel: string, nombre: string, ruta: string}>
     */
    public function buscar(string $texto, ?array $sedes, array $niveles = self::TODOS, int $limite = 10): Collection
    {
        $texto = trim((string) preg_replace('/\s+/u', ' ', $texto));
        if (mb_strlen($texto) < 1) {
            return collect();
        }
        $comodin = '%'.addcslashes(mb_strtolower($texto), '%_\\').'%';
        $lugares = Espacio::where('activo', true)->whereIn('nivel', $niveles)
            ->when($sedes !== null, fn ($q) => $q->whereIn('sede_id', $sedes === [] ? [0] : $sedes))
            ->where(fn ($q) => $q->whereRaw('LOWER(nombre) LIKE ?', [$comodin])->orWhereRaw('LOWER(codigo) LIKE ?', [$comodin]))
            // Primero lo que empieza igual («10» → 101 antes que 210)
            ->orderByRaw('CASE WHEN LOWER(nombre) LIKE ? THEN 0 ELSE 1 END', [addcslashes(mb_strtolower($texto), '%_\\').'%'])
            ->orderBy('nombre')->limit($limite)->get(['id', 'sede_id', 'nivel', 'nombre', 'ruta']);

        return $this->conCamino($lugares);
    }

    /** Un lugar activo de esas sedes y niveles (o null). */
    public function valido(mixed $id, ?array $sedes, array $niveles = self::TODOS): ?Espacio
    {
        if ($id === null || $id === '' || ! is_numeric($id)) {
            return null;
        }

        return Espacio::where('activo', true)->whereIn('nivel', $niveles)
            ->when($sedes !== null, fn ($q) => $q->whereIn('sede_id', $sedes === [] ? [0] : $sedes))
            ->find((int) $id);
    }

    /** Camino legible de un lugar: «Torre A › Piso 1 › 101». */
    public function camino(Espacio $espacio): string
    {
        return $this->conCamino(collect([$espacio]))->first()['ruta'] ?? $espacio->nombre;
    }

    /**
     * Un lugar del texto que capturó alguien (conversión de datos de antes):
     * la única área específica de la sede con ese nombre o código.
     */
    public function porTexto(?string $texto, int $sedeId): ?Espacio
    {
        $clave = mb_strtolower(trim((string) $texto));
        if ($clave === '') {
            return null;
        }
        $coinciden = Espacio::where('sede_id', $sedeId)->where('nivel', Espacio::AREA_ESPECIFICA)
            ->where(fn ($q) => $q->whereRaw('LOWER(nombre) = ?', [$clave])->orWhereRaw('LOWER(codigo) = ?', [$clave]))->limit(2)->get();

        return $coinciden->count() === 1 ? $coinciden->first() : null;
    }

    /**
     * Residentes activos de una vivienda (quien autoriza a sus visitas, C-09).
     *
     * @return Collection<int, Colaborador>
     */
    public function residentesDe(int $viviendaId): Collection
    {
        return Colaborador::residentes()->where('activo', true)->whereNull('fusionado_en_id')->where('espacio_id', $viviendaId)
            ->orderBy('id')->get(['id', 'empresa_id', 'tipo', 'sede_id', 'espacio_id', 'nombre', 'apellido_paterno', 'apellido_materno', 'telefono']);
    }

    /**
     * @param  Collection<int, Espacio>  $lugares
     * @return Collection<int, array{id: int, sede_id: int, nivel: string, nombre: string, ruta: string}>
     */
    private function conCamino(Collection $lugares): Collection
    {
        $ancestros = $lugares->flatMap(fn (Espacio $e) => $e->idsAncestros())->unique()->values();
        $nombres = $ancestros->isEmpty() ? collect() : Espacio::whereIn('id', $ancestros)->pluck('nombre', 'id');

        return $lugares->map(fn (Espacio $e) => [
            'id' => (int) $e->id, 'sede_id' => (int) $e->sede_id, 'nivel' => (string) $e->nivel, 'nombre' => (string) $e->nombre,
            'ruta' => collect($e->idsAncestros())->map(fn ($id) => $nombres[$id] ?? null)->filter()->push($e->nombre)->join(self::SEPARADOR),
        ])->values();
    }
}
