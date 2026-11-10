<?php

namespace App\Http\Controllers\Padrones;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\EtiquetaPlantilla;
use App\Services\Lector\ImpresionDesdePadron;
use App\Services\Lector\PlantillasEtiquetas;
use App\Services\Permisos\Autorizador;
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
                // Mejoras padrones (ADR-0015): cómo imprimen los padrones (solo quien configura para toda la empresa)
                'impresionPadrones' => app(ImpresionDesdePadron::class)->modo($empresaId),
                'configuraEmpresa' => app(Autorizador::class)->alcanceDeEmpresa($actor, PlantillasEtiquetas::CONFIGURAR),
            ]);
        });
    }

    /**
     * Mejoras padrones (ADR-0015): ¿los padrones imprimen su hoja propia o
     * las plantillas de Etiquetas QR? Lo decide quien configura plantillas
     * para toda la empresa.
     */
    public function impresionPadrones(Request $request): RedirectResponse
    {
        Gate::authorize(PlantillasEtiquetas::CONFIGURAR);
        $actor = $request->user();
        abort_unless(app(Autorizador::class)->alcanceDeEmpresa($actor, PlantillasEtiquetas::CONFIGURAR), 403);
        $empresaId = $this->empresaDeTrabajo($request);
        $modo = (string) $request->input('impresion');
        if (! isset(ImpresionDesdePadron::OPCIONES[$modo])) {
            return redirect()->route('etiquetas.plantillas')->withErrors(['impresion' => 'Elige una de las dos formas de imprimir.']);
        }
        $this->tenant->conEmpresa($empresaId, fn () => app(ImpresionDesdePadron::class)->guardar($actor, Empresa::findOrFail($empresaId), $modo));

        return redirect()->route('etiquetas.plantillas')->with('ok', $modo === ImpresionDesdePadron::PLANTILLAS
            ? 'Listo: los botones de imprimir de Llaves, Gafetes, Vehículos y Equipos usarán estas plantillas y quedarán en el Historial.'
            : 'Listo: cada padrón vuelve a imprimir su hoja propia.');
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
     * ni registro en el historial) para calibrar la impresora. La imprime
     * quien configura plantillas, también las de toda la empresa que no puede
     * cambiar (el jefe de una sede).
     */
    public function prueba(Request $request, int $plantilla): View
    {
        Gate::authorize(PlantillasEtiquetas::CONFIGURAR);
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $plantilla, $empresaId) {
            $modelo = $this->plantillas->paraProbar($request->user(), $plantilla);
            $qr = (string) preg_replace('/^<\?xml[^>]*>\s*/', '', (new Writer(new ImageRenderer(
                new RendererStyle(160, 1), new SvgImageBackEnd)))->writeString(route('etiquetas.index')));
            // Títulos de distinto largo, como los reales (una llave corta, un nombre de persona, un vehículo)
            $titulos = ['EJEMPLO-01', 'Llave 101', 'María Fernanda Canul Pech', 'ABC-123-D Nissan Versa blanco', 'Gafete visitante 12'];
            $ejemplo = fn (int $i) => [
                'clave' => 'prueba-'.$i, 'tipo' => 'llave', 'tipo_nombre' => 'Llaves', 'titulo' => $titulos[($i - 1) % count($titulos)],
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
