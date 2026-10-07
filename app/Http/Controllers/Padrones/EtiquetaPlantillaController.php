<?php

namespace App\Http\Controllers\Padrones;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\EtiquetaPlantilla;
use App\Services\Lector\PlantillasEtiquetas;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Ronda 7: Etiquetas QR → Plantillas (permiso «etiquetas_qr.configurar»).
 * Medidas de las etiquetas para rollo térmico u hoja carta/A4, qué datos
 * llevan y una «Hoja de prueba» para calibrar la impresora.
 * Reglas y alcance en App\Services\Lector\PlantillasEtiquetas.
 */
class EtiquetaPlantillaController extends Controller
{
    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly PlantillasEtiquetas $plantillas,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize(PlantillasEtiquetas::CONFIGURAR);
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        if ($empresaId === null) {
            return view('padrones.etiquetas.plantillas.index', ['sinEmpresa' => true]);
        }

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $empresaId) {
            $lista = $this->plantillas->paraConfigurar($actor);

            return view('padrones.etiquetas.plantillas.index', [
                'sinEmpresa' => false,
                'plantillas' => $lista,
                'editables' => $lista->filter(fn (EtiquetaPlantilla $p) => $this->plantillas->puedeEditar($actor, $p))->pluck('id')->all(),
                'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial'),
            ]);
        });
    }

    public function create(Request $request): View
    {
        Gate::authorize(PlantillasEtiquetas::CONFIGURAR);
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, fn () => $this->formulario($request, new EtiquetaPlantilla(
            ['nombre' => '', 'activo' => true] + array_diff_key(PlantillasEtiquetas::PREDETERMINADAS['etiqueta'], ['nombre' => true])
        )));
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(PlantillasEtiquetas::CONFIGURAR);
        $empresaId = $this->empresaDeTrabajo($request);
        $plantilla = $this->tenant->conEmpresa($empresaId, fn () => $this->plantillas->guardar($request->user(), $request->all()));

        return redirect()->route('etiquetas.plantillas')->with('ok', "Plantilla «{$plantilla->nombre}» creada. Imprime una hoja de prueba para revisar que las medidas coincidan con tu papel.");
    }

    public function edit(Request $request, int $plantilla): View
    {
        Gate::authorize(PlantillasEtiquetas::CONFIGURAR);
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, fn () => $this->formulario($request, $this->plantillas->paraEditar($request->user(), $plantilla)));
    }

    public function update(Request $request, int $plantilla): RedirectResponse
    {
        Gate::authorize(PlantillasEtiquetas::CONFIGURAR);
        $empresaId = $this->empresaDeTrabajo($request);
        $modelo = $this->tenant->conEmpresa($empresaId, function () use ($request, $plantilla) {
            $modelo = $this->plantillas->paraEditar($request->user(), $plantilla);

            return $this->plantillas->guardar($request->user(), $request->all(), $modelo);
        });

        return redirect()->route('etiquetas.plantillas')->with('ok', "Plantilla «{$modelo->nombre}» actualizada.");
    }

    public function estado(Request $request, int $plantilla): RedirectResponse
    {
        Gate::authorize(PlantillasEtiquetas::CONFIGURAR);
        $empresaId = $this->empresaDeTrabajo($request);
        $activo = $request->boolean('activo');
        $modelo = $this->tenant->conEmpresa($empresaId, function () use ($request, $plantilla, $activo) {
            $modelo = $this->plantillas->paraEditar($request->user(), $plantilla);
            $this->plantillas->cambiarEstado($request->user(), $modelo, $activo);

            return $modelo;
        });

        return redirect()->route('etiquetas.plantillas')->with($activo ? 'ok' : 'aviso', $activo
            ? "Plantilla «{$modelo->nombre}» activada: ya se puede elegir al imprimir."
            : "Plantilla «{$modelo->nombre}» desactivada: ya no aparece al imprimir. Puedes activarla con el mismo botón.");
    }

    /**
     * «Hoja de prueba»: una página con etiquetas de ejemplo (sin datos reales
     * ni registro en el historial) para calibrar la impresora.
     */
    public function prueba(Request $request, int $plantilla): View
    {
        Gate::authorize(PlantillasEtiquetas::CONFIGURAR);
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $plantilla, $empresaId) {
            $modelo = $this->plantillas->paraEditar($request->user(), $plantilla);
            $qr = (string) preg_replace('/^<\?xml[^>]*>\s*/', '', (new Writer(new ImageRenderer(
                new RendererStyle(160, 1), new SvgImageBackEnd)))->writeString(route('etiquetas.index')));
            $ejemplo = fn (int $i) => [
                'clave' => 'prueba-'.$i, 'tipo' => 'llave', 'tipo_nombre' => 'Llaves', 'titulo' => 'EJEMPLO-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'detalle' => '', 'activo' => true, 'sede_id' => null, 'codigo' => 'ABCD EFGH JKLM', 'ubicacion' => 'Departamento · Sede', 'qr' => $qr,
            ];
            $etiquetas = collect(range(1, $modelo->porPagina()))->map($ejemplo);

            return view('padrones.etiquetas.imprimir', app(EtiquetaController::class)->datosHoja($empresaId, $modelo, $etiquetas) + [
                'impresion' => null,
                'recortadas' => false,
                'faltan' => 0,
                'prueba' => true,
            ]);
        });
    }

    // ------------------------------------------------------------------ Apoyo

    private function formulario(Request $request, EtiquetaPlantilla $plantilla): View
    {
        [$deEmpresa, $sedes] = $this->plantillas->sedesParaElegir($request->user());

        return view('padrones.etiquetas.plantillas.formulario', [
            'plantilla' => $plantilla,
            'deEmpresa' => $deEmpresa,
            'sedes' => $sedes,
        ]);
    }

    private function empresaDeTrabajo(Request $request): int
    {
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        return $empresaId;
    }
}
