<?php

namespace App\Http\Controllers\Informes;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\Sede;
use App\Services\Informes\AlcanceInformes;
use App\Services\Informes\Indicadores;
use App\Services\Informes\Periodo;
use App\Services\Informes\Tendencias;
use App\Support\Csv;
use App\Support\Entrada;
use App\Support\HoraLocal;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Informes → Tendencias (ADR-0017): periodo contra el periodo anterior, por
 * sección, con exportación a Excel (CSV) de cada sección y hoja imprimible
 * (permiso «Imprimir»). Cada variación se lee como «mejoró» o «empeoró»
 * según Tendencias::SENTIDOS.
 */
class TendenciasController extends Controller
{
    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly AlcanceInformes $alcance,
        private readonly Tendencias $tendencias,
        private readonly HoraLocal $hora,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize(Tendencias::PERMISO);

        return $this->mostrar($request, 'informes.tendencias');
    }

    /**
     * Hoja carta con las secciones (o la sección elegida) y sus gráficas
     * (decisión del dueño, mapa de procesos): pide el permiso propio «Imprimir».
     */
    public function imprimir(Request $request): View
    {
        Gate::authorize(Tendencias::PERMISO);
        Gate::authorize('tendencias.imprimir');

        return $this->mostrar($request, 'informes.tendencias-imprimir');
    }

    private function mostrar(Request $request, string $vista): View
    {
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        if ($empresaId === null) {
            abort_if($vista !== 'informes.tendencias', 404);

            return view($vista, ['sinEmpresa' => true]);
        }

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $actor, $empresaId, $vista) {
            [$sedes, $seleccion, $periodo] = $this->filtros($request);
            $visibles = $this->tendencias->visibles($actor);
            $solo = Entrada::texto($request->query('seccion'));
            $mostrar = in_array($solo, $visibles, true) ? [$solo] : $visibles;
            $secciones = array_values(array_filter(array_map(fn ($c) => $this->tendencias->seccion($actor, $c, $periodo, $seleccion, $request->boolean('fresco')), $mostrar)));

            return view($vista, [
                'sinEmpresa' => false,
                'secciones' => $secciones,
                'visibles' => $visibles,
                'solo' => count($mostrar) === 1 && count($visibles) > 1 ? $mostrar[0] : null,
                'periodo' => $periodo,
                'sedes' => $sedes,
                'seleccion' => $seleccion ?? [],
                'textoSedes' => $this->textoSedes($sedes, $seleccion),
                'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial'),
                'puedeExportar' => $actor->can('tendencias.exportar'),
                'puedeImprimir' => $actor->can('tendencias.imprimir'),
                'generado' => now(),
            ]);
        });
    }

    /** Una sección en un archivo de Excel (CSV): cifras, series y desgloses. */
    public function exportar(Request $request): StreamedResponse
    {
        Gate::authorize('tendencias.exportar');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        $clave = Entrada::texto($request->query('seccion'));
        abort_if($empresaId === null || ! array_key_exists($clave, Tendencias::SECCIONES), 404);

        [$seccion, $periodo, $texto] = $this->tenant->conEmpresa($empresaId, function () use ($request, $actor, $clave) {
            [$sedes, $seleccion, $periodo] = $this->filtros($request);

            return [$this->tendencias->seccion($actor, $clave, $periodo, $seleccion), $periodo, $this->textoSedes($sedes, $seleccion)];
        });
        abort_if($seccion === null, 403);

        return response()->streamDownload(function () use ($seccion, $periodo, $texto) {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\xEF\xBB\xBF"); // para que Excel respete los acentos
            Csv::fila($salida, ['Tendencias: '.$seccion['titulo']]);
            Csv::fila($salida, ['Periodo', $periodo->texto(), 'Periodo anterior', $periodo->anterior()->texto()]);
            Csv::fila($salida, ['Sedes', $texto]);
            Csv::fila($salida, []);
            Csv::fila($salida, ['Indicador', 'Este periodo', 'Periodo anterior', 'Cambio (%)', 'Lectura']);
            foreach ($seccion['cifras'] as [$titulo, $actual, $anterior, $unidad, $sentido]) {
                $comparable = $anterior !== null && $unidad !== 'texto';
                $cambio = $comparable ? (Indicadores::cambio((float) $actual, (float) $anterior) ?? '') : '';
                $lectura = $comparable ? Indicadores::valorar((float) $actual, (float) $anterior, $sentido)['texto'] : '';
                Csv::fila($salida, [$titulo.($unidad !== '' && $unidad !== 'texto' ? " ({$unidad})" : ''), $actual, $anterior ?? '', $cambio, $lectura]);
            }
            foreach ($seccion['series'] as [$titulo, $etiquetas, $actual, $anterior]) {
                Csv::fila($salida, []);
                Csv::fila($salida, $anterior === null ? [$titulo, 'Total'] : [$titulo, 'Este periodo', 'Periodo anterior']);
                foreach ($etiquetas as $i => $etiqueta) {
                    Csv::fila($salida, $anterior === null ? [$etiqueta, $actual[$i]] : [$etiqueta, $actual[$i], $anterior[$i] ?? 0]);
                }
            }
            foreach ($seccion['desgloses'] as [$titulo, $datos, $unidad]) {
                Csv::fila($salida, []);
                Csv::fila($salida, [$titulo, $unidad === '' ? 'Total' : "Total ({$unidad})"]);
                foreach ($datos as $concepto => $total) {
                    Csv::fila($salida, [(string) $concepto, $total]);
                }
            }
            fclose($salida);
        }, 'tendencias-'.str_replace('_', '-', $clave).'-'.$periodo->desde->format('Y-m-d').'-al-'.$periodo->hasta->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Sedes que puede elegir, las elegidas y el periodo (en la zona de la sede si eligió una sola).
     *
     * @return array{0: Collection<int, Sede>, 1: list<int>|null, 2: Periodo}
     */
    private function filtros(Request $request): array
    {
        $sedes = $this->alcance->sedesParaElegir($request->user(), Tendencias::PERMISO);
        $seleccion = $this->alcance->seleccion($request->query('sedes'), $sedes);
        $zona = $seleccion !== null && count($seleccion) === 1 ? ($sedes->firstWhere('id', $seleccion[0])?->zona_horaria ?: $this->hora->zona()) : $this->hora->zona();
        $periodo = Periodo::crear(Entrada::texto($request->query('periodo', 'semana')), Entrada::texto($request->query('desde')) ?: null, Entrada::texto($request->query('hasta')) ?: null, $zona);

        return [$sedes, $seleccion, $periodo];
    }

    /** @param  Collection<int, Sede>  $sedes */
    private function textoSedes(Collection $sedes, ?array $seleccion): string
    {
        if ($seleccion === null) {
            return $sedes->count() === 1 ? $sedes->first()->nombre : 'Todas tus sedes';
        }

        return $sedes->whereIn('id', $seleccion)->pluck('nombre')->implode(', ');
    }
}
