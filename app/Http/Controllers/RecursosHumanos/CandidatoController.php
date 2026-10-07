<?php

namespace App\Http\Controllers\RecursosHumanos;

use App\Http\Controllers\Controller;
use App\Models\Candidato;
use App\Models\CandidatoDocumento;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Puesto;
use App\Services\Candidatos\AdministradorCandidatos;
use App\Services\Candidatos\CambioNoPermitido;
use App\Services\Candidatos\DocumentosCandidato;
use App\Services\Candidatos\Kiosco;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Recepcion\AjustesRecepcion;
use App\Support\Csv;
use App\Support\Entrada;
use App\Support\HoraLocal;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Candidatos (Recursos Humanos): lista con filtros, ficha / CV digital,
 * etapas, documentos privados, contratar y exportación.
 *
 * El CV es dato personal: solo con candidatos.ver y dentro de las sedes del
 * usuario. Otra empresa u otra sede → 404.
 */
class CandidatoController extends Controller
{
    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly AdministradorCandidatos $candidatos,
        private readonly DocumentosCandidato $documentos,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('candidatos.ver');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        if ($empresaId === null) {
            return view('rh.candidatos.index', ['sinEmpresa' => true]);
        }
        $filtros = $this->filtros($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $filtros, $empresaId) {
            $base = $this->candidatos->limitar(Candidato::query(), $actor, 'candidatos.ver');
            $conteos = (clone $base)->selectRaw('etapa, COUNT(*) as total')->groupBy('etapa')->pluck('total', 'etapa');
            $lista = $this->filtrar(clone $base, $filtros)
                ->with(['sede:id,nombre', 'departamento:id,nombre', 'puesto:id,nombre', 'registradoPor:id,name', 'editadoPor:id,name'])
                ->orderByRaw("CASE WHEN etapa IN ('registrado','revision','aprobado_rh','entrevista','seleccionado') THEN 0 ELSE 1 END")
                ->orderByDesc('id')->paginate(24)->withQueryString();
            $sedes = $this->candidatos->sedesParaElegir($actor, 'candidatos.ver');

            return view('rh.candidatos.index', [
                'sinEmpresa' => false,
                'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial'),
                'lista' => $lista,
                'conteos' => $conteos,
                'filtros' => $filtros,
                'sedes' => $sedes,
                'sedesAlta' => $actor->can('candidatos.crear') ? $this->candidatos->sedesParaElegir($actor, 'candidatos.crear') : collect(),
                'departamentos' => Departamento::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
                'puestos' => Puesto::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
                'privacidad' => app(AjustesRecepcion::class)->textoPrivacidad(Empresa::findOrFail($empresaId)),
                'puede' => [
                    'crear' => $actor->can('candidatos.crear'),
                    'exportar' => $actor->can('candidatos.exportar'),
                ],
            ]);
        });
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('candidatos.crear');
        $empresaId = $this->empresaDeTrabajo($request);
        $candidato = $this->tenant->conEmpresa($empresaId, fn () => $this->candidatos->crear($request->user(), $request->except(['_token', '_dialogo']), (string) $request->ip()));

        return redirect()->route('candidatos.show', $candidato->id)->with('ok', "Ficha de {$candidato->nombre_completo} creada. Completa su CV y sus documentos.");
    }

    public function show(Request $request, int $candidato): View
    {
        Gate::authorize('candidatos.ver');
        $actor = $request->user();
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $candidato, $empresaId) {
            $c = $this->buscar($actor, $candidato, 'candidatos.ver');
            $c->load(['sede:id,nombre', 'departamento:id,nombre', 'puesto:id,nombre', 'persona:id,nombre_completo,categoria', 'colaborador:id,num_empleado,nombre,apellido_paterno,apellido_materno',
                'acceso:id,sede_id,entrada_at,estado,foto_persona,foto_identificacion,gafete_texto,creado_por', 'acceso.registradoPor:id,name',
                'documentos.registradoPor:id,name', 'eventos.usuario:id,name', 'autorizaciones.respondidaPor:id,name', 'autorizaciones.departamento:id,nombre',
                'registradoPor:id,name', 'editadoPor:id,name', 'decisionPor:id,name']);
            $enlace = $c->enlaces()->whereNull('revocado_en')->where('expira_en', '>', now())->first();
            $partes = $this->partirNombre($c->nombre_completo);

            return view('rh.candidatos.show', [
                'c' => $c,
                'enlace' => $enlace?->vigente() ? $enlace : null,
                'departamentos' => Departamento::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
                'puestos' => Puesto::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
                'sedesContratar' => $actor->can('candidatos.contratar') ? $this->candidatos->sedesParaElegir($actor, 'colaboradores.crear') : collect(),
                'partes' => $partes,
                'privacidad' => app(AjustesRecepcion::class)->textoPrivacidad(Empresa::findOrFail($empresaId)),
                'puede' => [
                    'editar' => $actor->can('candidatos.editar'),
                    'contratar' => $actor->can('candidatos.contratar') && $actor->can('colaboradores.crear'),
                    'eliminar' => $actor->can('candidatos.eliminar'),
                    'kiosco' => $actor->can('candidatos.editar'),
                ],
            ]);
        });
    }

    public function update(Request $request, int $candidato): RedirectResponse
    {
        Gate::authorize('candidatos.editar');

        return $this->conCandidato($request, $candidato, 'candidatos.editar', function (Candidato $c) use ($request) {
            $this->candidatos->actualizar($request->user(), $c, $request->except(['_token', '_method', '_dialogo']), (string) $request->ip());

            return 'CV actualizado.';
        });
    }

    public function etapa(Request $request, int $candidato): RedirectResponse
    {
        Gate::authorize('candidatos.editar');
        // Primero el registro (otra empresa o sede → 404) y después la validación
        $this->existe($request, $candidato, 'candidatos.editar');
        $datos = $request->validate([
            'etapa' => ['required', Rule::in(array_keys(Candidato::ETAPAS))],
            'comentario' => ['nullable', 'string', 'max:500'],
            'volver' => ['nullable', Rule::in(['ficha', 'recepcion'])],
        ], ['etapa.*' => 'Elige una etapa válida.', 'comentario.max' => 'El comentario admite máximo 500 caracteres.']);

        return $this->conCandidato($request, $candidato, 'candidatos.editar', function (Candidato $c) use ($request, $datos) {
            $this->candidatos->cambiarEtapa($request->user(), $c, $datos['etapa'], $datos['comentario'] ?? null);

            return $datos['etapa'] === 'aprobado_rh'
                ? "{$c->nombre_completo}: aprobado por RR. HH. Se avisó al responsable del departamento."
                : "{$c->nombre_completo} ahora está «".Candidato::ETAPAS[$datos['etapa']].'».';
        }, ($datos['volver'] ?? null) === 'recepcion' ? 'recepcion.index' : null);
    }

    public function revisado(Request $request, int $candidato): RedirectResponse
    {
        Gate::authorize('candidatos.editar');

        return $this->conCandidato($request, $candidato, 'candidatos.editar', function (Candidato $c) use ($request) {
            $this->candidatos->autocapturaRevisada($request->user(), $c);

            return 'Marcado como revisado.';
        });
    }

    public function contratar(Request $request, int $candidato): RedirectResponse
    {
        Gate::authorize('candidatos.contratar');
        Gate::authorize('colaboradores.crear');
        $empresaId = $this->empresaDeTrabajo($request);

        try {
            [$c, $col] = $this->tenant->conEmpresa($empresaId, function () use ($request, $candidato) {
                $c = $this->buscar($request->user(), $candidato, 'candidatos.contratar');

                return [$c, $this->candidatos->contratar($request->user(), $c, $request->except(['_token', '_dialogo']))];
            });
        } catch (CambioNoPermitido $e) {
            return redirect()->route('candidatos.show', $candidato)->with('error', $e->getMessage());
        }

        return redirect()->route('candidatos.show', $c->id)->with('ok', "¡Contratado! {$c->nombre_completo} ya está en Colaboradores con el número {$col->num_empleado}.");
    }

    public function destroy(Request $request, int $candidato): RedirectResponse
    {
        Gate::authorize('candidatos.eliminar');
        $empresaId = $this->empresaDeTrabajo($request);
        $nombre = $this->tenant->conEmpresa($empresaId, function () use ($request, $candidato) {
            $c = $this->buscar($request->user(), $candidato, 'candidatos.eliminar');
            $this->candidatos->eliminar($request->user(), $c);

            return $c->nombre_completo;
        });

        return redirect()->route('candidatos.index')->with('ok', "Se eliminó la ficha de {$nombre} y sus documentos.");
    }

    // ------------------------------------------------------------- Documentos

    public function subirDocumento(Request $request, int $candidato): RedirectResponse
    {
        Gate::authorize('candidatos.editar');
        $this->existe($request, $candidato, 'candidatos.editar');
        $request->validate([
            'tipo' => ['required', Rule::in(array_keys(CandidatoDocumento::TIPOS))],
            'documento' => ['required', 'file', 'max:'.DocumentosCandidato::MAXIMO_KB, 'mimes:pdf,jpg,jpeg,png'],
        ], [
            'tipo.*' => 'Elige qué documento es.',
            'documento.required' => 'Elige el archivo (PDF, JPG o PNG).',
            'documento.max' => 'El archivo pesa más de 5 MB.',
            'documento.mimes' => 'El archivo debe ser PDF, JPG o PNG.',
            'documento.*' => 'No se pudo recibir el archivo.',
        ]);

        return $this->conCandidato($request, $candidato, 'candidatos.editar', function (Candidato $c) use ($request) {
            $doc = $this->documentos->subir($c, $request->file('documento'), (string) $request->input('tipo'), 'rh');
            app(AdministradorRoles::class)->auditar($request->user(), 'candidatos.documento_agregado', $c, null,
                ['tipo' => $doc->tipo, 'nombre' => $doc->nombre_original]);

            return 'Documento agregado.';
        });
    }

    public function documento(Request $request, int $candidato, int $documento): StreamedResponse
    {
        Gate::authorize('candidatos.ver');
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $candidato, $documento) {
            $c = $this->buscar($request->user(), $candidato, 'candidatos.ver');
            $doc = CandidatoDocumento::where('candidato_id', $c->id)->find($documento);
            abort_if($doc === null, 404);

            return $this->documentos->respuesta($doc->ruta, $doc->nombre_original, ! $doc->esImagen());
        });
    }

    public function borrarDocumento(Request $request, int $candidato, int $documento): RedirectResponse
    {
        Gate::authorize('candidatos.editar');

        return $this->conCandidato($request, $candidato, 'candidatos.editar', function (Candidato $c) use ($request, $documento) {
            $doc = CandidatoDocumento::where('candidato_id', $c->id)->find($documento);
            abort_if($doc === null, 404);
            $this->documentos->borrar($doc->ruta);
            $doc->delete();
            app(AdministradorRoles::class)->auditar($request->user(), 'candidatos.documento_eliminado', $c, ['tipo' => $doc->tipo, 'nombre' => $doc->nombre_original], null);

            return 'Documento eliminado.';
        });
    }

    public function revocarEnlace(Request $request, int $candidato): RedirectResponse
    {
        Gate::authorize('candidatos.editar');

        return $this->conCandidato($request, $candidato, 'candidatos.editar', function (Candidato $c) use ($request) {
            $total = app(Kiosco::class)->revocar($request->user(), $c);

            return $total > 0 ? 'Enlace del kiosco anulado: ya no se puede usar.' : 'No había un enlace vigente.';
        });
    }

    // ------------------------------------------------------------- Exportar

    public function exportar(Request $request, HoraLocal $hora): StreamedResponse
    {
        Gate::authorize('candidatos.exportar');
        $empresaId = $this->empresaDeTrabajo($request);
        $filtros = $this->filtros($request);
        $filas = $this->tenant->conEmpresa($empresaId, fn () => $this->filtrar($this->candidatos->limitar(Candidato::query(), $request->user(), 'candidatos.exportar'), $filtros)
            ->with(['sede:id,nombre', 'departamento:id,nombre', 'puesto:id,nombre'])->orderByDesc('id')->limit(5000)->get());

        return response()->streamDownload(function () use ($filas, $hora) {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\xEF\xBB\xBF");
            Csv::fila($salida, ['Folio', 'Sede', 'Nombre', 'Puesto', 'Departamento', 'Etapa', 'Origen', 'Escolaridad', 'Años de experiencia', 'Disponibilidad', 'Llegada', 'Decisión']);
            foreach ($filas as $c) {
                Csv::fila($salida, [$c->id, $c->sede?->nombre, $c->nombre_completo, $c->puestoVisible(), $c->departamento?->nombre, $c->etiquetaEtapa(),
                    Candidato::ORIGENES[$c->origen] ?? $c->origen, $c->escolaridadMaxima(), $c->anosExperiencia(), Candidato::DISPONIBILIDAD[$c->disponibilidad] ?? null,
                    $hora->formatear($c->llegada_en), $hora->formatear($c->decision_en)]);
            }
            fclose($salida);
        }, 'candidatos-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ------------------------------------------------------------------ Ayudas

    /** @return array<string, mixed> */
    private function filtros(Request $request): array
    {
        $etapa = Entrada::texto($request->query('etapa'));

        return [
            'q' => mb_substr(trim(Entrada::texto($request->query('q'))), 0, 100),
            'etapa' => array_key_exists($etapa, Candidato::ETAPAS) || $etapa === 'en_proceso' ? $etapa : '',
            'sede' => (int) Entrada::texto($request->query('sede'), '0'),
            'departamento' => (int) Entrada::texto($request->query('departamento'), '0'),
        ];
    }

    /**
     * @param  Builder<Candidato>  $q
     * @param  array<string, mixed>  $f
     * @return Builder<Candidato>
     */
    private function filtrar($q, array $f)
    {
        return $q
            ->when($f['q'] !== '', fn ($x) => $x->where(fn ($w) => $w->where('candidatos.nombre_completo', 'like', '%'.addcslashes($f['q'], '%_\\').'%')
                ->orWhere('candidatos.vacante', 'like', '%'.addcslashes($f['q'], '%_\\').'%')))
            ->when($f['etapa'] === 'en_proceso', fn ($x) => $x->whereIn('candidatos.etapa', Candidato::ABIERTAS))
            ->when($f['etapa'] !== '' && $f['etapa'] !== 'en_proceso', fn ($x) => $x->where('candidatos.etapa', $f['etapa']))
            ->when($f['sede'] > 0, fn ($x) => $x->where('candidatos.sede_id', $f['sede']))
            ->when($f['departamento'] > 0, fn ($x) => $x->where('candidatos.departamento_id', $f['departamento']));
    }

    private function buscar($actor, int $id, string $permiso): Candidato
    {
        $c = $this->candidatos->limitar(Candidato::query(), $actor, $permiso)->find($id);
        abort_if($c === null, 404);

        return $c;
    }

    private function existe(Request $request, int $id, string $permiso): void
    {
        $this->tenant->conEmpresa($this->empresaDeTrabajo($request), fn () => $this->buscar($request->user(), $id, $permiso));
    }

    private function conCandidato(Request $request, int $id, string $permiso, \Closure $accion, ?string $volver = null): RedirectResponse
    {
        $empresaId = $this->empresaDeTrabajo($request);
        $destino = fn () => $volver ? redirect()->route($volver) : redirect()->route('candidatos.show', $id);
        try {
            $mensaje = $this->tenant->conEmpresa($empresaId, fn () => $accion($this->buscar($request->user(), $id, $permiso)));
        } catch (CambioNoPermitido $e) {
            return $destino()->with('error', $e->getMessage());
        }

        return $destino()->with('ok', $mensaje);
    }

    private function empresaDeTrabajo(Request $request): int
    {
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        return $empresaId;
    }

    /**
     * Sugerencia de nombre y apellidos para el alta como colaborador (RR. HH. lo confirma).
     *
     * @return array{nombre: string, paterno: string, materno: string}
     */
    private function partirNombre(string $completo): array
    {
        $p = preg_split('/\s+/u', trim($completo)) ?: [];
        if (count($p) >= 3) {
            $materno = array_pop($p);
            $paterno = array_pop($p);

            return ['nombre' => implode(' ', $p), 'paterno' => $paterno, 'materno' => $materno];
        }

        return ['nombre' => $p[0] ?? '', 'paterno' => $p[1] ?? '', 'materno' => ''];
    }
}
