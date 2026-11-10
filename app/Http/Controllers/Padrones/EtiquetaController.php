<?php

namespace App\Http\Controllers\Padrones;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\EtiquetaPlantilla;
use App\Models\Sede;
use App\Services\Lector\EtiquetasMasivas;
use App\Services\Lector\ImpresionesEtiquetas;
use App\Services\Lector\PlantillasEtiquetas;
use App\Services\Permisos\Autorizador;
use App\Support\Entrada;
use App\Support\Identidad;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Padrones → Etiquetas QR: el gestor centralizado de impresión QR.
 *
 * Ronda 6 (LL-06): impresión masiva de todo lo identificable (EtiquetasMasivas).
 * Ronda 7: plantillas de etiqueta (PlantillasEtiquetas), filtros por fecha de
 * alta, tipo y estatus, e historial de impresiones con «Reimprimir»
 * (ImpresionesEtiquetas).
 *
 *  - GET  /etiquetas                               lista con filtros y casillas; elige la plantilla
 *  - POST /etiquetas/imprimir                      registra la impresión y abre su hoja
 *  - GET  /etiquetas/impresiones/{id}              hoja de la impresión (@page en mm según la plantilla)
 *  - POST /etiquetas/impresiones/{id}/reimprimir   otra impresión con todas o algunas de sus etiquetas
 *  - GET  /etiquetas/historial                     impresiones anteriores con filtros
 *
 * Todo pide «etiquetas_qr.ver»; cada tipo pide además sus propios permisos.
 * Las plantillas se configuran en EtiquetaPlantillaController.
 */
class EtiquetaController extends Controller
{
    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly EtiquetasMasivas $etiquetas,
        private readonly PlantillasEtiquetas $plantillas,
        private readonly ImpresionesEtiquetas $impresiones,
        private readonly Autorizador $autorizador,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('etiquetas_qr.ver');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        if ($empresaId === null) {
            return view('padrones.etiquetas.index', ['sinEmpresa' => true]);
        }

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $actor, $empresaId) {
            $tipos = $this->etiquetas->tipos($actor);
            $tipo = Entrada::texto($request->query('tipo'));
            $estado = Entrada::texto($request->query('estado'));
            $filtros = [
                'tipo' => isset($tipos[$tipo]) ? $tipo : null,
                'sede' => ctype_digit(Entrada::texto($request->query('sede'))) ? (int) $request->query('sede') : null,
                'estado' => in_array($estado, ['todos', 'baja'], true) ? $estado : 'activos',
                'q' => mb_substr(trim(Entrada::texto($request->query('q'))), 0, 100),
                'desde' => $this->dia($request->query('desde')),
                'hasta' => $this->dia($request->query('hasta')),
            ];
            $filas = $this->etiquetas->lista($actor, $filtros);
            $plantillas = $this->plantillas->disponibles($actor);

            return view('padrones.etiquetas.index', [
                'sinEmpresa' => false,
                'tipos' => $tipos,
                'filtros' => $filtros,
                'filas' => $filas,
                'conteo' => $filas->countBy('tipo'),
                'sedes' => $this->sedesFiltro($actor, $tipos),
                'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial'),
                'plantillas' => $plantillas,
                'preelegida' => $this->plantillas->preelegida($actor, $plantillas, $filtros['tipo']),
                'puedeConfigurar' => $actor->can(PlantillasEtiquetas::CONFIGURAR),
            ]);
        });
    }

    /**
     * Registra la impresión (historial y auditoría) y abre su hoja. Con
     * ?tamano= (Ronda 6) se usa la plantilla de siempre de esa medida.
     */
    public function imprimir(Request $request): RedirectResponse
    {
        Gate::authorize('etiquetas_qr.ver');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        abort_if($empresaId === null, 404);
        $seleccion = collect((array) $request->input('sel', []))->filter(fn ($v) => is_string($v))->values()->all();
        if ($seleccion === []) {
            return redirect()->route('etiquetas.index')->with('aviso', 'Marca al menos un registro para imprimir sus etiquetas.');
        }

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $actor, $seleccion) {
            $plantilla = $this->plantillaElegida($request, $actor);
            $etiquetas = $this->etiquetas->paraImprimir($actor, $seleccion);
            abort_if($etiquetas->isEmpty(), 404);
            $impresion = $this->impresiones->registrar($actor, $plantilla, $etiquetas);

            return redirect()->route('etiquetas.impresion', array_filter([
                'impresion' => $impresion->id,
                'recortadas' => count(array_unique($seleccion)) > EtiquetasMasivas::MAXIMO ? 1 : null,
            ]));
        });
    }

    /** Hoja de una impresión: las etiquetas que todavía puede imprimir, con su plantilla. */
    public function impresion(Request $request, int $impresion): View
    {
        Gate::authorize('etiquetas_qr.ver');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        abort_if($empresaId === null, 404);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $actor, $impresion, $empresaId) {
            $modelo = $this->impresiones->buscar($actor, $impresion);
            $modelo->load('items', 'plantilla');
            $plantilla = $modelo->plantilla ?? $this->plantillas->disponibles($actor)->first();
            abort_if($plantilla === null, 404);
            $etiquetas = $this->etiquetas->conUbicacion($this->etiquetas->paraImprimir($actor, $modelo->items->map->clave()->all()));
            abort_if($etiquetas->isEmpty(), 404);

            return view('padrones.etiquetas.imprimir', $this->datosHoja($empresaId, $plantilla, $etiquetas) + [
                'impresion' => $modelo,
                'recortadas' => $request->boolean('recortadas'),
                'faltan' => $modelo->items->count() - $etiquetas->count(),
                'prueba' => false,
            ]);
        });
    }

    /** «Reimprimir»: otra impresión con todas sus etiquetas o solo las marcadas, con la misma u otra plantilla. */
    public function reimprimir(Request $request, int $impresion): RedirectResponse
    {
        Gate::authorize('etiquetas_qr.ver');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        abort_if($empresaId === null, 404);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $actor, $impresion) {
            $original = $this->impresiones->buscar($actor, $impresion);
            $original->load('items', 'plantilla');
            $todas = $original->items->map->clave()->all();
            $marcadas = collect((array) $request->input('sel', []))->filter(fn ($v) => is_string($v) && in_array($v, $todas, true))->values()->all();
            if ($request->has('sel') && $marcadas === []) {
                return redirect()->route('etiquetas.historial')->with('aviso', 'Marca al menos una etiqueta para reimprimir.');
            }
            $plantilla = $request->filled('plantilla') ? $this->plantillaElegida($request, $actor) : ($original->plantilla?->activo ? $original->plantilla : $this->plantillaElegida($request, $actor));
            $etiquetas = $this->etiquetas->paraImprimir($actor, $marcadas === [] ? $todas : $marcadas);
            abort_if($etiquetas->isEmpty(), 404);
            $nueva = $this->impresiones->registrar($actor, $plantilla, $etiquetas, $original);

            return redirect()->route('etiquetas.impresion', $nueva->id);
        });
    }

    public function historial(Request $request): View
    {
        Gate::authorize('etiquetas_qr.ver');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        if ($empresaId === null) {
            return view('padrones.etiquetas.historial', ['sinEmpresa' => true]);
        }

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $actor, $empresaId) {
            $filtros = [
                'desde' => $this->dia($request->query('desde')),
                'hasta' => $this->dia($request->query('hasta')),
                'usuario' => ctype_digit(Entrada::texto($request->query('usuario'))) ? (int) $request->query('usuario') : null,
                'plantilla' => ctype_digit(Entrada::texto($request->query('plantilla'))) ? (int) $request->query('plantilla') : null,
                'q' => mb_substr(trim(Entrada::texto($request->query('q'))), 0, 100),
            ];
            $plantillas = $this->plantillas->disponibles($actor);

            return view('padrones.etiquetas.historial', [
                'sinEmpresa' => false,
                'filtros' => $filtros,
                'impresiones' => $this->impresiones->historial($actor, $filtros),
                'autores' => $this->impresiones->autores($actor),
                'plantillas' => $plantillas,
                'todasLasPlantillas' => EtiquetaPlantilla::whereIn('id', $this->impresiones->visibles($actor)->whereNotNull('plantilla_id')->distinct()->pluck('plantilla_id'))
                    ->orderBy('nombre')->pluck('nombre', 'id'),
                'resumen' => $this->impresiones,
                'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial'),
                'puedeConfigurar' => $actor->can(PlantillasEtiquetas::CONFIGURAR),
            ]);
        });
    }

    // ------------------------------------------------------------------ Apoyo

    /**
     * Datos de la hoja de impresión (también la usa la «Hoja de prueba» de una plantilla).
     *
     * @param  Collection<int, array<string, mixed>>  $etiquetas
     * @return array<string, mixed>
     */
    public function datosHoja(int $empresaId, EtiquetaPlantilla $plantilla, Collection $etiquetas): array
    {
        $empresa = Empresa::whereKey($empresaId)->first(['id', 'nombre_comercial', 'logo_ruta']);

        return [
            'etiquetas' => $etiquetas,
            'paginas' => $etiquetas->chunk($plantilla->porPagina()),
            'plantilla' => $plantilla,
            'texto' => $this->plantillas->medidasTexto($plantilla),
            'empresaNombre' => $empresa?->nombre_comercial,
            'logo' => $plantilla->mostrar_logo && $empresa ? $this->logo($empresa) : null,
        ];
    }

    /** La plantilla elegida (id) o la de siempre de la medida (?tamano= de la Ronda 6); si no, la preelegida. */
    private function plantillaElegida(Request $request, $actor): EtiquetaPlantilla
    {
        $disponibles = $this->plantillas->disponibles($actor);
        $id = Entrada::texto($request->input('plantilla'));
        $tamano = Entrada::texto($request->input('tamano'));
        $plantilla = (ctype_digit($id) ? $disponibles->firstWhere('id', (int) $id) : null)
            ?? ($tamano !== '' ? $disponibles->firstWhere('clave', $tamano) : null)
            ?? $this->plantillas->preelegida($actor, $disponibles, null);
        abort_if($plantilla === null, 404);

        return $plantilla;
    }

    /** Logo de la empresa guardado en public/ (como en el gafete); si no hay, el nombre. */
    private function logo(Empresa $empresa): ?string
    {
        foreach ([$empresa->logo_ruta, app(Identidad::class)->get('simbolo')] as $ruta) {
            if (is_string($ruta) && $ruta !== '' && ! str_contains($ruta, '..') && is_file(public_path($ruta))) {
                return asset($ruta);
            }
        }

        return null;
    }

    /**
     * Sedes del filtro: las que alcanza con alguno de los permisos de ver.
     *
     * @param  array<string, array{permisos: list<string>}>  $tipos
     * @return Collection<int, Sede>
     */
    private function sedesFiltro($actor, array $tipos): Collection
    {
        $permitidas = null;
        if (! $actor->es_superadmin) {
            $listas = collect($tipos)->map(fn ($t) => $this->autorizador->sedesPermitidas($actor, $t['permisos'][0]));
            $permitidas = $listas->contains(null) ? null : $listas->flatten()->unique()->values()->all();
        }

        return Sede::where('activo', true)->when($permitidas !== null, fn ($q) => $q->whereIn('id', $permitidas))->orderBy('nombre')->get(['id', 'nombre']);
    }

    private function dia(mixed $valor): ?string
    {
        $texto = Entrada::texto($valor);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $texto) ? $texto : null;
    }
}
