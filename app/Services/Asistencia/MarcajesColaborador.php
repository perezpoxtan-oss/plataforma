<?php

namespace App\Services\Asistencia;

use App\Models\Acceso;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Punto de extensión de Asistencia (ADR-0024): de dónde salen las entradas
 * y salidas de los colaboradores. Es la MISMA fuente que la Bitácora de
 * accesos: la tabla `accesos` con tipo «colaborador». Todo lo que registra
 * una entrada o salida de colaborador ahí —la caseta, el lector, la
 * credencial y, cuando existan, los equipos de acceso— entra solo a
 * Asistencia, sin que nadie haga un paso nuevo.
 *
 * Contrato para quien agregue otra forma de registrar (docs/tecnico/asistencia.md,
 * «Punto de extensión»): una fila de `accesos` con tipo = colaborador,
 * colaborador_id, sede_id y entrada_at; la salida cierra esa misma fila
 * (salida_at). Si una fuente futura no pudiera escribir en `accesos`, se
 * suma aquí, en marcajes(), y nada más cambia.
 */
class MarcajesColaborador
{
    /**
     * Entradas (con su salida, si ya la hubo) de esos colaboradores que
     * empezaron entre $desde y $hasta, agrupadas por colaborador y en orden.
     *
     * @param  list<int>  $colaboradores
     * @return Collection<int, list<array{entrada: CarbonImmutable, salida: ?CarbonImmutable, sede_id: ?int, acceso_id: int}>>
     */
    public function marcajes(array $colaboradores, CarbonInterface $desde, CarbonInterface $hasta): Collection
    {
        if ($colaboradores === []) {
            return collect();
        }

        return Acceso::query()
            ->where('tipo', 'colaborador')
            ->whereIn('colaborador_id', $colaboradores)
            ->where('entrada_at', '>=', $desde->copy()->utc())
            ->where('entrada_at', '<', $hasta->copy()->utc())
            ->orderBy('entrada_at')->orderBy('id')
            ->get(['id', 'colaborador_id', 'sede_id', 'entrada_at', 'salida_at'])
            ->groupBy('colaborador_id')
            ->map(fn (Collection $filas) => $filas->map(fn (Acceso $a) => [
                'entrada' => CarbonImmutable::parse($a->entrada_at)->utc(),
                'salida' => $a->salida_at ? CarbonImmutable::parse($a->salida_at)->utc() : null,
                'sede_id' => $a->sede_id ? (int) $a->sede_id : null,
                'acceso_id' => (int) $a->id,
            ])->values()->all());
    }
}
