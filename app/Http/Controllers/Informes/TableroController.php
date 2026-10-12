<?php

namespace App\Http\Controllers\Informes;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Services\Informes\AlcanceInformes;
use App\Services\Informes\Tablero;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Informes → Tablero (ADR-0017): la «Consola de Monitoreo» del sistema
 * original, como pantalla propia enlazada desde Inicio (el Inicio sigue
 * siendo «lo que espera tu respuesta»).
 */
class TableroController extends Controller
{
    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly AlcanceInformes $alcance,
        private readonly Tablero $tablero,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize(Tablero::PERMISO);

        return $this->mostrar($request, 'informes.tablero');
    }

    /**
     * Hoja carta con las fichas del momento (decisión del dueño, mapa de
     * procesos): pide el permiso propio «Imprimir» del Tablero.
     */
    public function imprimir(Request $request): View
    {
        Gate::authorize(Tablero::PERMISO);
        Gate::authorize('dashboard.imprimir');

        return $this->mostrar($request, 'informes.tablero-imprimir');
    }

    private function mostrar(Request $request, string $vista): View
    {
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        if ($empresaId === null) {
            abort_if($vista !== 'informes.tablero', 404);

            return view($vista, ['sinEmpresa' => true]);
        }

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $actor, $empresaId, $vista) {
            $sedes = $this->alcance->sedesParaElegir($actor, Tablero::PERMISO);
            $seleccion = $this->alcance->seleccion($request->query('sede'), $sedes);

            return view($vista, [
                'sinEmpresa' => false,
                'fichas' => $this->tablero->fichas($actor, $seleccion),
                'sedes' => $sedes,
                'sede' => $seleccion[0] ?? null,
                'textoSedes' => $seleccion === null ? ($sedes->count() === 1 ? $sedes->first()->nombre : 'Todas tus sedes') : $sedes->whereIn('id', $seleccion)->pluck('nombre')->implode(', '),
                'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial'),
                'generado' => now(),
                'puedeImprimir' => $actor->can('dashboard.imprimir'),
            ]);
        });
    }

    /**
     * Detalle de una ficha: dentro del diálogo (petición del navegador con
     * «parcial») o como página propia si no hay JavaScript.
     */
    public function detalle(Request $request): View
    {
        Gate::authorize(Tablero::PERMISO);
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        $clave = (string) $request->query('ficha', '');
        abort_if($empresaId === null || ! array_key_exists($clave, Tablero::FICHAS), 404);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $actor, $clave) {
            $sedes = $this->alcance->sedesParaElegir($actor, Tablero::PERMISO);
            $seleccion = $this->alcance->seleccion($request->query('sede'), $sedes);
            $detalle = $this->tablero->detalle($actor, $clave, $seleccion);
            abort_if($detalle === null, 404);

            $parcial = $request->boolean('parcial') || $request->ajax();

            return view($parcial ? 'informes._tablero-detalle' : 'informes.tablero-detalle', [
                'detalle' => $detalle,
                'sede' => $seleccion[0] ?? null,
            ]);
        });
    }
}
