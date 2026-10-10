<?php

namespace App\Http\Controllers\RecursosHumanos;

use App\Http\Controllers\Controller;
use App\Models\Candidato;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Puesto;
use App\Models\Turno;
use App\Models\Vacante;
use App\Services\Candidatos\CambioNoPermitido;
use App\Services\Permisos\Autorizador;
use App\Services\Vacantes\AdministradorVacantes;
use App\Services\Vacantes\BolsaTrabajo;
use App\Support\Entrada;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Vacantes (bolsa de trabajo, lección 36): fichas con contadores por
 * estado, alta y edición, Publicar / Pausar / Cerrar / Reabrir, cartel con
 * QR para la entrada o la caseta, enlace para compartir y ajustes de la
 * bolsa pública. Otra empresa u otra sede fuera del alcance → 404.
 */
class VacanteController extends Controller
{
    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly AdministradorVacantes $vacantes,
        private readonly BolsaTrabajo $bolsa,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('vacantes.ver');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        if ($empresaId === null) {
            return view('rh.vacantes.index', ['sinEmpresa' => true]);
        }
        $filtros = $this->filtros($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $filtros, $empresaId) {
            $empresa = Empresa::findOrFail($empresaId);
            $hoy = AdministradorVacantes::hoy($empresa);
            $base = $this->vacantes->limitar(Vacante::query(), $actor);
            $conteos = (clone $base)->selectRaw('estado, COUNT(*) as total')->groupBy('estado')->pluck('total', 'estado');
            $lista = (clone $base)
                ->when($filtros['q'] !== '', fn ($q) => $q->where('vacantes.titulo', 'like', '%'.addcslashes($filtros['q'], '%_\\').'%'))
                ->when($filtros['estado'] !== '', fn ($q) => $q->where('vacantes.estado', $filtros['estado']))
                ->when($filtros['sede'] > 0, fn ($q) => $q->aplicanEn([$filtros['sede']]))
                ->when($filtros['departamento'] > 0, fn ($q) => $q->where('vacantes.departamento_id', $filtros['departamento']))
                ->with(['sedes:id,nombre', 'puesto:id,nombre', 'departamento:id,nombre', 'turno:id,nombre,hora_inicio,hora_fin', 'registradoPor:id,name', 'editadoPor:id,name'])
                ->withCount(['postulaciones as candidatos_count', 'postulaciones as en_proceso_count' => fn ($q) => $q->whereIn('etapa', Candidato::ABIERTAS)])
                ->orderByRaw("CASE estado WHEN 'publicada' THEN 0 WHEN 'pausada' THEN 1 WHEN 'borrador' THEN 2 ELSE 3 END")
                ->orderByDesc('id')->paginate(24)->withQueryString();
            $sedesAlta = $actor->can('vacantes.crear') ? $this->vacantes->sedesParaElegir($actor, 'vacantes.crear') : collect();
            $autorizador = app(Autorizador::class);
            $configura = $actor->can('vacantes.configurar') && $autorizador->alcanceDeEmpresa($actor, 'vacantes.configurar');

            return view('rh.vacantes.index', [
                'sinEmpresa' => false,
                'empresa' => $empresa,
                'hoy' => $hoy,
                'lista' => $lista,
                'conteos' => $conteos,
                'filtros' => $filtros,
                'sedes' => $this->vacantes->sedesParaElegir($actor, 'vacantes.ver'),
                'sedesAlta' => $sedesAlta,
                'sedesEditar' => $actor->can('vacantes.editar') ? $this->vacantes->sedesParaElegir($actor, 'vacantes.editar') : collect(),
                'todasLasSedes' => $actor->can('vacantes.crear') && $autorizador->alcanceDeEmpresa($actor, 'vacantes.crear'),
                'sedeUsuario' => $sedesAlta->count() === 1 ? $sedesAlta->first()->id : null,
                'departamentos' => Departamento::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
                'puestos' => Puesto::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
                'turnos' => Turno::where('activo', true)->orderBy('hora_inicio')->get(['id', 'nombre', 'hora_inicio', 'hora_fin']),
                'bolsa' => $this->bolsa->ajustes($empresa) + ['lista' => $this->bolsa->activa($empresa)],
                'modificables' => $lista->getCollection()->mapWithKeys(fn (Vacante $v) => [$v->id => [
                    'editar' => $this->vacantes->puedeModificar($actor, $v, 'vacantes.editar'),
                    'eliminar' => $this->vacantes->puedeModificar($actor, $v, 'vacantes.eliminar'),
                ]])->all(),
                'puede' => [
                    'crear' => $actor->can('vacantes.crear') && $sedesAlta->isNotEmpty(),
                    'editar' => $actor->can('vacantes.editar'),
                    'eliminar' => $actor->can('vacantes.eliminar'),
                    'configurar' => $configura,
                    'candidatos' => $actor->can('candidatos.ver'),
                ],
            ]);
        });
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('vacantes.crear');
        $empresaId = $this->empresaDeTrabajo($request);
        $publicar = $request->boolean('publicar');
        $vacante = $this->tenant->conEmpresa($empresaId, function () use ($request, $publicar, $empresaId) {
            $v = $this->vacantes->crear($request->user(), $this->entrada($request));
            if ($publicar && $request->user()->can('vacantes.editar')) {
                $v = $this->vacantes->cambiarEstado($request->user(), $v, 'publicada', null, AdministradorVacantes::hoy(Empresa::findOrFail($empresaId)));
            }

            return $v;
        });

        return redirect()->route('vacantes.index')->with('ok', match (true) {
            $vacante->estado === 'publicada' => "Vacante «{$vacante->titulo}» publicada. Ya aparece en la bolsa de trabajo y puedes imprimir su cartel.",
            // Quien pide vacantes sin poder publicarlas (Jefe de departamento): la revisa Recursos Humanos
            ! $request->user()->can('vacantes.editar') => "Vacante «{$vacante->titulo}» enviada como borrador: Recursos Humanos la revisará y la publicará.",
            default => "Vacante «{$vacante->titulo}» guardada como borrador. Publícala cuando esté lista.",
        });
    }

    public function update(Request $request, int $vacante): RedirectResponse
    {
        Gate::authorize('vacantes.editar');
        $this->existe($request, $vacante, 'vacantes.editar');

        return $this->conVacante($request, $vacante, 'vacantes.editar', function (Vacante $v) use ($request) {
            $this->vacantes->actualizar($request->user(), $v, $this->entrada($request));

            return "Vacante «{$v->titulo}» actualizada.";
        });
    }

    public function estado(Request $request, int $vacante): RedirectResponse
    {
        Gate::authorize('vacantes.editar');
        $this->existe($request, $vacante, 'vacantes.editar');
        $estado = Entrada::texto($request->input('estado'));
        if (! array_key_exists($estado, Vacante::ESTADOS)) {
            throw ValidationException::withMessages(['estado' => 'Elige un estado válido.']);
        }
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->conVacante($request, $vacante, 'vacantes.editar', function (Vacante $v) use ($request, $estado, $empresaId) {
            $v = $this->vacantes->cambiarEstado($request->user(), $v, $estado, Entrada::texto($request->input('cierre_motivo')) ?: null,
                AdministradorVacantes::hoy(Empresa::findOrFail($empresaId)));

            return match ($estado) {
                'publicada' => "«{$v->titulo}» está publicada: aparece en la bolsa de trabajo y en la caseta.",
                'pausada' => "«{$v->titulo}» está en pausa: ya no aparece en la bolsa de trabajo.",
                'cerrada' => "«{$v->titulo}» quedó cerrada (".($v->cierre_motivo === 'cubierta' ? 'cubierta' : 'cancelada').').',
                'borrador' => "«{$v->titulo}» regresó a borrador: edítala y publícala de nuevo.",
            };
        });
    }

    public function destroy(Request $request, int $vacante): RedirectResponse
    {
        Gate::authorize('vacantes.eliminar');

        return $this->conVacante($request, $vacante, 'vacantes.eliminar', function (Vacante $v) use ($request) {
            $this->vacantes->eliminar($request->user(), $v);

            return "Se eliminó la vacante «{$v->titulo}».";
        });
    }

    /**
     * Cartel para la entrada o la caseta (hoja carta): logo, título, sedes,
     * horario, sueldo, requisitos y el QR que abre la vacante en internet.
     */
    public function cartel(Request $request, int $vacante): View
    {
        Gate::authorize('vacantes.ver');
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $vacante, $empresaId) {
            $v = $this->buscar($request->user(), $vacante, 'vacantes.ver');
            $v->load(['sedes:id,nombre', 'puesto:id,nombre', 'departamento:id,nombre', 'turno:id,nombre,hora_inicio,hora_fin']);
            $empresa = Empresa::findOrFail($empresaId);
            $logo = $empresa->logo_ruta;
            $url = $this->urlPublica($empresa, $v);

            return view('rh.vacantes.cartel', [
                'v' => $v,
                'empresa' => $empresa,
                'url' => $url,
                'qr' => $url ? $this->qr($url) : null,
                'logo' => is_string($logo) && $logo !== '' && ! str_contains($logo, '..') && is_file(public_path($logo)) ? asset($logo) : null,
            ]);
        });
    }

    /** Ajustes de la bolsa de trabajo pública (de toda la empresa). */
    public function ajustes(Request $request): RedirectResponse
    {
        Gate::authorize('vacantes.configurar');
        abort_unless(app(Autorizador::class)->alcanceDeEmpresa($request->user(), 'vacantes.configurar'), 403,
            'La bolsa de trabajo es de toda la empresa: hace falta «configurar» con alcance de empresa.');
        $empresa = Empresa::findOrFail($this->empresaDeTrabajo($request));
        $this->bolsa->guardarAjustes($request->user(), $empresa, $request->only(['bolsa_activa', 'bolsa_presentacion', 'bolsa_indexar']));
        $empresa->refresh();

        return back()->with('ok', $this->bolsa->activa($empresa)
            ? 'Bolsa de trabajo encendida. Su dirección: '.route('empleos.index', $empresa->bolsa_slug)
            : 'Bolsa de trabajo apagada: la página pública ya no se muestra.');
    }

    // ------------------------------------------------------------------ Ayudas

    /** Dirección pública de la vacante (null si la bolsa está apagada o la vacante no está publicada). */
    public function urlPublica(Empresa $empresa, Vacante $v): ?string
    {
        return $this->bolsa->activa($empresa) && $v->estado === 'publicada' ? route('empleos.show', [$empresa->bolsa_slug, $v->codigo]) : null;
    }

    /** @return array<string, mixed> */
    private function entrada(Request $request): array
    {
        return $request->only(['titulo', 'puesto_id', 'departamento_id', 'plazas', 'tipo_contrato', 'jornada', 'turno_id', 'horario', 'sueldo_min', 'sueldo_max',
            'sueldo_periodo', 'sueldo_a_tratar', 'descripcion', 'requisitos', 'prestaciones', 'escolaridad_minima', 'experiencia', 'fecha_publicacion', 'fecha_cierre',
            'contacto_nombre', 'contacto_telefono', 'contacto_correo', 'todas_las_sedes', 'sedes']);
    }

    /** @return array{q: string, estado: string, sede: int, departamento: int} */
    private function filtros(Request $request): array
    {
        $estado = Entrada::texto($request->query('estado'));

        return [
            'q' => mb_substr(trim(Entrada::texto($request->query('q'))), 0, 100),
            'estado' => array_key_exists($estado, Vacante::ESTADOS) ? $estado : '',
            'sede' => (int) Entrada::texto($request->query('sede'), '0'),
            'departamento' => (int) Entrada::texto($request->query('departamento'), '0'),
        ];
    }

    private function buscar($actor, int $id, string $permiso): Vacante
    {
        $v = $this->vacantes->limitar(Vacante::query(), $actor, $permiso)->find($id);
        abort_if($v === null, 404);

        return $v;
    }

    private function existe(Request $request, int $id, string $permiso): void
    {
        $this->tenant->conEmpresa($this->empresaDeTrabajo($request), fn () => $this->buscar($request->user(), $id, $permiso));
    }

    private function conVacante(Request $request, int $id, string $permiso, \Closure $accion): RedirectResponse
    {
        $empresaId = $this->empresaDeTrabajo($request);
        try {
            $mensaje = $this->tenant->conEmpresa($empresaId, fn () => DB::transaction(fn () => $accion($this->buscar($request->user(), $id, $permiso))));
        } catch (CambioNoPermitido $e) {
            return redirect()->route('vacantes.index')->with('error', $e->getMessage());
        }

        return redirect()->route('vacantes.index')->with('ok', $mensaje);
    }

    private function empresaDeTrabajo(Request $request): int
    {
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        return $empresaId;
    }

    private function qr(string $texto): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(320, 1), new SvgImageBackEnd)))->writeString($texto);

        return (string) preg_replace('/^<\?xml[^>]*>\s*/', '', $svg);
    }
}
