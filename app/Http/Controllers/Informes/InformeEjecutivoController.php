<?php

namespace App\Http\Controllers\Informes;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Services\Informes\AlcanceInformes;
use App\Services\Informes\InformeEjecutivo;
use App\Support\Csv;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Informes → Informe ejecutivo (ADR-0017): pantalla, versión imprimible
 * (hoja carta; «Guardar como PDF» es el diálogo de impresión del navegador)
 * y archivo de Excel (CSV) con las cifras.
 */
class InformeEjecutivoController extends Controller
{
    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly AlcanceInformes $alcance,
        private readonly InformeEjecutivo $informe,
    ) {}

    public function index(Request $request): View
    {
        return $this->mostrar($request, 'informes.ejecutivo');
    }

    /** Hoja carta sin menús: basta el permiso de ver el informe (como en el sistema original). */
    public function imprimir(Request $request): View
    {
        return $this->mostrar($request, 'informes.ejecutivo-imprimir');
    }

    private function mostrar(Request $request, string $vista): View
    {
        Gate::authorize(InformeEjecutivo::PERMISO);
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        if ($empresaId === null) {
            abort_if($vista !== 'informes.ejecutivo', 404);

            return view($vista, ['sinEmpresa' => true]);
        }

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $actor, $empresaId, $vista) {
            $sedes = $this->alcance->sedesParaElegir($actor, InformeEjecutivo::PERMISO);
            $seleccion = $this->alcance->seleccion($request->query('sedes'), $sedes);

            return view($vista, [
                'sinEmpresa' => false,
                'bloques' => $this->informe->bloques($actor, $empresaId, $seleccion, $request->boolean('fresco')),
                'sedes' => $sedes,
                'seleccion' => $seleccion ?? [],
                'textoSedes' => $seleccion === null ? ($sedes->count() === 1 ? $sedes->first()->nombre : 'Todas tus sedes') : $sedes->whereIn('id', $seleccion)->pluck('nombre')->implode(', '),
                'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial'),
                'generado' => now(),
                'puedeExportar' => $actor->can('informe_ejecutivo.exportar'),
            ]);
        });
    }

    public function exportar(Request $request): StreamedResponse
    {
        Gate::authorize('informe_ejecutivo.exportar');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        abort_if($empresaId === null, 404);

        $bloques = $this->tenant->conEmpresa($empresaId, function () use ($request, $actor, $empresaId) {
            $sedes = $this->alcance->sedesParaElegir($actor, InformeEjecutivo::PERMISO);

            return $this->informe->bloques($actor, $empresaId, $this->alcance->seleccion($request->query('sedes'), $sedes));
        });

        return response()->streamDownload(function () use ($bloques) {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\xEF\xBB\xBF"); // para que Excel respete los acentos
            Csv::fila($salida, ['Bloque', 'Indicador', 'Valor', 'Nota']);
            foreach ($bloques as $b) {
                foreach ($b['fichas'] as $f) {
                    Csv::fila($salida, [$b['titulo'], $f['titulo'], $f['valor'], $f['nota']]);
                }
            }
            fclose($salida);
        }, 'informe-ejecutivo-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
