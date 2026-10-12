<?php

namespace App\Http\Controllers\RecursosHumanos;

use App\Http\Controllers\Controller;
use App\Models\Candidato;
use App\Models\CandidatoDocumento;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Postulacion;
use App\Models\Puesto;
use App\Models\Vacante;
use App\Services\Candidatos\AdministradorCandidatos;
use App\Services\Candidatos\CaducidadCandidatos;
use App\Services\Candidatos\CambioNoPermitido;
use App\Services\Candidatos\DocumentosCandidato;
use App\Services\Candidatos\DocumentosParaContratar;
use App\Services\Candidatos\Entrevistas;
use App\Services\Candidatos\Kiosco;
use App\Services\Candidatos\PlazosCandidatos;
use App\Services\Candidatos\Postulaciones;
use App\Services\Firmas\Firmas;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Autorizador;
use App\Services\Recepcion\AjustesRecepcion;
use App\Support\CorreoPlataforma;
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
        // Fase 3: si ya tocaba recordar, archivar o borrar algo en esta empresa, se hace ahora (candado de 60 s)
        app(PlazosCandidatos::class)->correrSiToca($empresaId);

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $filtros, $empresaId) {
            $base = $this->candidatos->limitar(Candidato::query(), $actor, 'candidatos.ver');
            // Fase 3: las postulaciones archivadas por caducidad no cuentan ni se listan (salvo el filtro «Archivadas»)
            $archivadas = (clone $base)->whereNotNull('candidatos.archivada_en')->count();
            $base->whereNull('candidatos.archivada_en');
            $conteos = (clone $base)->selectRaw('etapa, COUNT(*) as total')->groupBy('etapa')->pluck('total', 'etapa');
            $porRevisar = (clone $base)->where('autocaptura_pendiente', true)->count();
            // Fase 3: «Por caducar» = fichas con fecha de borrado avisada, archivadas o no (RR. HH. puede conservarlas)
            $todas = fn () => $this->candidatos->limitar(Candidato::query(), $actor, 'candidatos.ver');
            $porCaducar = $todas()->whereNotNull('candidatos.borrar_en')->count();
            $lista = $this->filtrar(match (true) {
                $filtros['archivadas'] => $todas()->whereNotNull('candidatos.archivada_en'),
                $filtros['caducar'] => $todas(),
                default => clone $base,
            }, $filtros)
                ->with(['sede:id,nombre', 'departamento:id,nombre', 'puesto:id,nombre', 'registradoPor:id,name', 'editadoPor:id,name', 'vacantePublicada:id,titulo,estado'])
                ->orderByRaw("CASE WHEN etapa IN ('".implode("','", Candidato::ABIERTAS)."') THEN 0 ELSE 1 END")
                ->orderByDesc('id')->paginate(24)->withQueryString();
            $sedes = $this->candidatos->sedesParaElegir($actor, 'candidatos.ver');

            return view('rh.candidatos.index', [
                'sinEmpresa' => false,
                'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial'),
                'lista' => $lista,
                'conteos' => $conteos,
                'porRevisar' => $porRevisar,
                'filtros' => $filtros,
                'sedes' => $sedes,
                'sedesAlta' => $actor->can('candidatos.crear') ? $this->candidatos->sedesParaElegir($actor, 'candidatos.crear') : collect(),
                'departamentos' => Departamento::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
                'puestos' => Puesto::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
                // Vacantes (lección 36): filtro y vacantes para ligar
                'vacantesFiltro' => Vacante::where('estado', '!=', 'borrador')->orderByRaw("CASE estado WHEN 'publicada' THEN 0 WHEN 'pausada' THEN 1 ELSE 2 END")
                    ->orderBy('titulo')->get(['id', 'titulo', 'estado']),
                'vacantesElegibles' => Vacante::whereIn('estado', ['publicada', 'pausada'])->orderBy('titulo')->get(['id', 'titulo', 'estado']),
                'privacidad' => app(AjustesRecepcion::class)->textoPrivacidad(Empresa::findOrFail($empresaId)),
                'puede' => [
                    'crear' => $actor->can('candidatos.crear'),
                    'exportar' => $actor->can('candidatos.exportar'),
                    'configurar' => $actor->can('candidatos.configurar'), // Fase 3: Documentos y plazos
                ],
                'archivadas' => $archivadas, // Fase 3
                'porCaducar' => $porCaducar, // Fase 3
            ]);
        });
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('candidatos.crear');
        $empresaId = $this->empresaDeTrabajo($request);
        [$candidato, $yaExistia] = $this->tenant->conEmpresa($empresaId, fn () => $this->candidatos->crearOLigar($request->user(), $request->except(['_token', '_dialogo']), (string) $request->ip()));

        // Una ficha por persona: si ya tenía, se abre la suya (no se crea otra)
        return redirect()->route('candidatos.show', $candidato->id)->with('ok', $yaExistia
            ? "{$candidato->nombre_completo} ya tenía ficha: no se creó otra. Revisa su solicitud y su postulación actual."
            : "Ficha de {$candidato->nombre_completo} creada. Completa su CV y sus documentos.");
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
                'registradoPor:id,name', 'editadoPor:id,name', 'decisionPor:id,name', 'firmaCapturadaPor:id,name', 'vacantePublicada:id,titulo,estado']);
            $enlace = $c->enlaces()->whereNull('revocado_en')->where('expira_en', '>', now())->first();
            $partes = $c->partesNombre();
            // Postulaciones: la activa (la que refleja la ficha) y las anteriores
            $postulaciones = app(Postulaciones::class);
            $activa = $postulaciones->activa($c)?->load(['vacantePublicada:id,titulo,estado,departamento_id', 'accesos:id,postulacion_id,entrada_at,viene_a',
                'evaluaciones.evaluador:id,name', 'entrevistador:id,name', 'canalizadoPor:id,name', 'departamento:id,nombre', 'decisionPor:id,name']);
            $anteriores = $postulaciones->anteriores($c, $activa);
            // Fase 2: evaluaciones, canalizar (entrevistador y cita) y criterios
            $entrevistas = app(Entrevistas::class);
            $sedeActiva = (int) ($activa->sede_id ?? $c->sede_id);
            $departamentoCanalizar = $activa?->departamento_id ?? $activa?->vacantePublicada?->departamento_id;
            // Fase 3: documentos para contratar, contrato y caducidad
            $documentosParaContratar = app(DocumentosParaContratar::class);
            $activa?->load(['contratoRecibidoPor:id,name', 'contratoDocumento:id,nombre_original,mime,bytes']);
            $estadoDocumentos = $documentosParaContratar->estado($c, $activa);
            $empresa = Empresa::findOrFail($empresaId);
            $plazos = app(PlazosCandidatos::class)->plazos($empresa);
            $reloj = $activa ? app(PlazosCandidatos::class)->relojDe($empresa, $activa, $c) : null;

            return view('rh.candidatos.show', [
                'estadoDocumentos' => $estadoDocumentos,
                'listaDocumentos' => $documentosParaContratar->lista($empresaId),
                'opcionesDocumento' => $documentosParaContratar->opciones($empresaId),
                'nombresDocumento' => $documentosParaContratar->nombres($empresaId),
                'faltantesContratar' => $activa && $activa->etapa === 'elegido' ? $documentosParaContratar->faltantesParaContratar($c, $activa) : [],
                'mesesConservar' => CaducidadCandidatos::MESES_CONSERVAR,
                'plazoDocumentos' => $plazos['documentos_dias'],
                'reloj' => $reloj, // Fase 3: cuánto lleva en su etapa y su color según los plazos
                'c' => $c,
                'activa' => $activa,
                'anteriores' => $anteriores,
                'enlace' => $enlace?->vigente() ? $enlace : null,
                'departamentos' => Departamento::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
                'puestos' => Puesto::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
                'vacantesElegibles' => Vacante::where(fn ($q) => $q->whereIn('estado', ['publicada', 'pausada'])->orWhere('id', $c->vacante_id))
                    ->orderBy('titulo')->get(['id', 'titulo', 'estado']),
                'sedesContratar' => $actor->can('candidatos.contratar') ? $this->candidatos->sedesParaElegir($actor, 'colaboradores.crear') : collect(),
                'partes' => $partes,
                'privacidad' => app(AjustesRecepcion::class)->textoPrivacidad(Empresa::findOrFail($empresaId)),
                'criterios' => $entrevistas->criterios($empresaId),
                'ultimaRh' => $activa?->evaluaciones->where('tipo', 'rh')->last(),
                'elegibles' => $actor->can('candidatos.editar') ? $entrevistas->elegibles($empresaId, $sedeActiva, $departamentoCanalizar) : ['responsables' => collect(), 'otros' => collect()],
                'departamentoCanalizar' => $departamentoCanalizar,
                'departamentosSede' => Departamento::where('activo', true)->aplicanEn([$sedeActiva])->orderBy('nombre')->get(['id', 'nombre']),
                'textoCita' => $activa ? $entrevistas->textoCita($activa) : '',
                'correoConfigurado' => app(CorreoPlataforma::class)->configurado(),
                'abrirDialogo' => session('abrir_dialogo'),
                'puede' => [
                    'editar' => $actor->can('candidatos.editar'),
                    'contratar' => $actor->can('candidatos.contratar') && $actor->can('colaboradores.crear'),
                    'eliminar' => $actor->can('candidatos.eliminar'),
                    'kiosco' => $actor->can('candidatos.editar'),
                    'expediente' => $c->colaborador_id !== null && $actor->can('colaboradores.ver'), // Fase 3
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
            'etapa' => ['required', Rule::in(Candidato::MANUALES)],
            'comentario' => ['nullable', 'string', 'max:500'],
            'volver' => ['nullable', Rule::in(['ficha', 'recepcion'])],
        ], ['etapa.*' => 'Elige una etapa válida. Para canalizar, reprogramar o contratar usa su botón.', 'comentario.max' => 'El comentario admite máximo 500 caracteres.']);

        return $this->conCandidato($request, $candidato, 'candidatos.editar', function (Candidato $c) use ($request, $datos) {
            $this->candidatos->cambiarEtapa($request->user(), $c, $datos['etapa'], $datos['comentario'] ?? null);

            return $datos['etapa'] === 'con_rh'
                ? "{$c->nombre_completo} está contigo («Con RR. HH.»). Cuando lo entrevistes, toca «Entrevistar» para calificarlo."
                : "{$c->nombre_completo} ahora está «".Candidato::ETAPAS[$datos['etapa']].'».';
        }, ($datos['volver'] ?? null) === 'recepcion' ? 'recepcion.index' : null);
    }

    // ------------------------------------------------ Entrevistas (fase 2)

    /** Evaluación de la entrevista de RR. HH. (estrellas, comentario y resultado). */
    public function evaluacionRh(Request $request, int $candidato): RedirectResponse
    {
        Gate::authorize('candidatos.editar');
        $this->existe($request, $candidato, 'candidatos.editar');

        return $this->conCandidato($request, $candidato, 'candidatos.editar', function (Candidato $c) use ($request) {
            $e = app(Entrevistas::class)->evaluarRh($request->user(), $c, $request->only(['criterios', 'resultado', 'comentario', 'entrevista_en', 'entrevista_fecha', 'entrevista_hora']));
            if ($e->resultado === 'canalizar') {
                session()->flash('abrir_dialogo', 'canalizar'); // sigue: elegir entrevistador y cita

                return "Evaluación guardada (promedio {$e->promedioTexto()}). Ahora canalízalo al departamento: elige quién lo entrevista y la cita.";
            }

            return "Evaluación guardada: {$c->nombre_completo} quedó «".Candidato::ETAPAS[$c->fresh()->etapa].'». El departamento no recibe aviso.';
        });
    }

    /** Canalizar al departamento, Segunda entrevista o Reprogramar (cita y entrevistador). */
    public function canalizar(Request $request, int $candidato): RedirectResponse
    {
        Gate::authorize('candidatos.editar');
        $this->existe($request, $candidato, 'candidatos.editar');

        return $this->conCandidato($request, $candidato, 'candidatos.editar', function (Candidato $c) use ($request) {
            $antes = app(Postulaciones::class)->activa($c)?->etapa;
            $p = app(Entrevistas::class)->canalizar($request->user(), $c, $request->only(['vacante_id', 'departamento_id', 'entrevistador_id', 'cuando', 'fecha', 'hora', 'lugar', 'avisar_candidato']));

            return ($antes === 'canalizado' || $antes === 'no_se_presento' ? 'Entrevista reprogramada' : 'Listo: canalizado al departamento')
                .": {$p->entrevistador?->name} ya tiene el aviso con la cita ({$c->nombre_completo}).";
        });
    }

    /** «No se presentó» (ya pasó la hora de su cita). */
    public function noSePresento(Request $request, int $candidato): RedirectResponse
    {
        Gate::authorize('candidatos.editar');

        return $this->conCandidato($request, $candidato, 'candidatos.editar', function (Candidato $c) use ($request) {
            app(Entrevistas::class)->noSePresento($request->user(), $c);

            return "{$c->nombre_completo} quedó «No se presentó». Usa «Reprogramar» si vuelve a agendar.";
        });
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

    // ------------------------------------------------- Solicitud de empleo (lección 36)

    /**
     * Hoja impresa «Solicitud de empleo» para el expediente: logo, folio,
     * fecha, foto de caseta, todas las secciones y la firma.
     */
    public function solicitud(Request $request, int $candidato): View
    {
        Gate::authorize('candidatos.ver');
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $candidato, $empresaId) {
            $c = $this->buscar($request->user(), $candidato, 'candidatos.ver');
            $c->load(['sede:id,nombre,direccion', 'departamento:id,nombre', 'puesto:id,nombre', 'acceso:id,foto_persona', 'firmaCapturadaPor:id,name']);
            $empresa = Empresa::whereKey($empresaId)->first(['id', 'nombre_comercial', 'razon_social', 'logo_ruta']);
            $logo = $empresa->logo_ruta;

            return view('rh.candidatos.solicitud', [
                'c' => $c,
                'empresa' => $empresa,
                'logo' => is_string($logo) && $logo !== '' && ! str_contains($logo, '..') && is_file(public_path($logo)) ? asset($logo) : null,
            ]);
        });
    }

    /** Firma de la solicitud (disco privado): solo con candidatos.ver y dentro del alcance. */
    public function firma(Request $request, int $candidato): StreamedResponse
    {
        Gate::authorize('candidatos.ver');
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $candidato) {
            $c = $this->buscar($request->user(), $candidato, 'candidatos.ver');

            return app(Firmas::class)->respuesta($c->firma_ruta);
        });
    }

    // ------------------------------------------------------------- Documentos

    public function subirDocumento(Request $request, int $candidato): RedirectResponse
    {
        Gate::authorize('candidatos.editar');
        $this->existe($request, $candidato, 'candidatos.editar');
        // Fase 3: el tipo es un renglón de «Documentos para contratar» de la empresa (más CV y Otro)
        $opciones = $this->tenant->conEmpresa($this->empresaDeTrabajo($request), fn () => app(DocumentosParaContratar::class)->opciones($this->empresaDeTrabajo($request)));
        $request->validate([
            'tipo' => ['required', Rule::in(array_keys($opciones))],
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
            // Fase 3: el mensaje dice cuánto falta del expediente
            $estado = app(DocumentosParaContratar::class)->estado($c->fresh()->load('documentos'), app(Postulaciones::class)->activa($c));

            return 'Documento agregado. '.app(DocumentosParaContratar::class)->resumen($estado).($estado['faltan'] === [] ? ': completo.' : '; falta: '.implode(', ', $estado['faltan']).'.');
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

    /**
     * «Enviar enlace por correo» (ficha): el candidato recibe un enlace del
     * kiosco de varias horas para llenar su solicitud completa desde su casa
     * (postulación corta por internet, ADR-0010).
     */
    public function enviarEnlace(Request $request, int $candidato): RedirectResponse
    {
        Gate::authorize('candidatos.editar');

        return $this->conCandidato($request, $candidato, 'candidatos.editar', function (Candidato $c) use ($request) {
            if (! $c->enProceso()) {
                throw new CambioNoPermitido('Este candidato ya no está en proceso: no se le puede mandar el enlace.');
            }
            if (! app(CorreoPlataforma::class)->configurado()) {
                throw new CambioNoPermitido('La plataforma no tiene correo configurado: muéstrale el QR del kiosco o pide al administrador que configure el correo.');
            }
            if ($c->correo === null || trim((string) $c->correo) === '') {
                throw new CambioNoPermitido('No tiene correo capturado: agrégalo en «Editar solicitud» o muéstrale el QR del kiosco.');
            }
            app(Kiosco::class)->enviarEnlacePorCorreo($request->user(), $c);

            return "Listo: le mandamos a {$c->correo} el enlace para completar su solicitud (vale ".Kiosco::HORAS_ENLACE_CORREO.' horas).';
        });
    }

    // ------------------------------------------------------------- Exportar

    public function exportar(Request $request, HoraLocal $hora): StreamedResponse
    {
        Gate::authorize('candidatos.exportar');
        $empresaId = $this->empresaDeTrabajo($request);
        $filtros = $this->filtros($request);
        $filas = $this->tenant->conEmpresa($empresaId, fn () => $this->filtrar($this->candidatos->limitar(Candidato::query(), $request->user(), 'candidatos.exportar'), $filtros)
            ->with(['sede:id,nombre', 'departamento:id,nombre', 'puesto:id,nombre', 'vacantePublicada:id,titulo', 'postulacionActiva.evaluaciones:id,postulacion_id,tipo,promedio'])
            ->orderByDesc('id')->limit(5000)->get());

        // Datos personales sensibles: solo si se piden expresamente (columnas marcadas)
        $conPersonales = Entrada::texto($request->query('datos')) === 'personales';
        if ($conPersonales) {
            app(AdministradorRoles::class)->auditar($request->user(), 'candidatos.exportado_con_datos_personales', Empresa::findOrFail($empresaId), null,
                ['registros' => $filas->count()]);
        }

        return response()->streamDownload(function () use ($filas, $hora, $conPersonales) {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\xEF\xBB\xBF");
            $marca = ' [DATO PERSONAL]';
            Csv::fila($salida, array_merge(['Folio', 'Sede', 'Nombre', 'Puesto', 'Vacante', 'Departamento', 'Etapa', 'Origen', 'Escolaridad', 'Años de experiencia', 'Disponibilidad', 'Llegada', 'Decisión',
                'Promedio RR. HH.', 'Promedio departamento'],
                $conPersonales ? ['Teléfono'.$marca, 'Correo'.$marca, 'CURP'.$marca, 'RFC'.$marca, 'NSS'.$marca, 'Domicilio'.$marca, 'Contacto de emergencia'.$marca] : []));
            foreach ($filas as $c) {
                Csv::fila($salida, array_merge([$c->id, $c->sede?->nombre, $c->nombre_completo, $c->puestoVisible(), $c->vacantePublicada?->titulo, $c->departamento?->nombre, $c->etiquetaEtapa(),
                    Candidato::ORIGENES[$c->origen] ?? $c->origen, $c->escolaridadMaxima(), $c->anosExperiencia(), Candidato::DISPONIBILIDAD[$c->disponibilidad] ?? null,
                    $hora->formatear($c->llegada_en), $hora->formatear($c->decision_en),
                    $c->postulacionActiva?->evaluaciones->where('tipo', 'rh')->last()?->promedioTexto(), $c->postulacionActiva?->evaluaciones->where('tipo', 'departamento')->last()?->promedioTexto()],
                    $conPersonales ? [$c->telefono, $c->correo, $c->curp, $c->rfc, $c->nss, $c->domicilioCompleto(),
                        trim(($c->emergencia_nombre ?? '').' '.($c->emergencia_telefono ?? ''))] : []));
            }
            fclose($salida);
        }, 'candidatos-'.now()->format('Y-m-d').($conPersonales ? '-datos-personales' : '').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ------------------------------------------------- Fase 3: documentos, contrato, plazos y caducidad

    /** Pantalla «Documentos y plazos» (lista de documentos para contratar y plazos de candidatos). */
    public function configuracion(Request $request): View
    {
        Gate::authorize('candidatos.configurar');
        $actor = $request->user();
        $empresaId = $this->empresaDeTrabajo($request);
        $empresa = Empresa::findOrFail($empresaId);
        $documentos = app(DocumentosParaContratar::class);

        return $this->tenant->conEmpresa($empresaId, fn () => view('rh.candidatos.configuracion', [
            'empresa' => $empresa,
            'lista' => $documentos->lista($empresaId),
            'configurada' => $documentos->configurada($empresaId),
            'plazos' => app(PlazosCandidatos::class)->plazos($empresa),
            'limites' => PlazosCandidatos::LIMITES,
            'defecto' => PlazosCandidatos::DEFECTO,
            'maxDocumentos' => DocumentosParaContratar::MAX_DOCUMENTOS,
            'diasAviso' => CaducidadCandidatos::DIAS_AVISO,
            'puedeGuardar' => app(Autorizador::class)->alcanceDeEmpresa($actor, 'candidatos.configurar'),
        ]));
    }

    public function guardarDocumentos(Request $request): RedirectResponse
    {
        Gate::authorize('candidatos.configurar');
        abort_unless(app(Autorizador::class)->alcanceDeEmpresa($request->user(), 'candidatos.configurar'), 403,
            'La lista de documentos es de toda la empresa: hace falta «configurar» con alcance de empresa.');
        $empresa = Empresa::findOrFail($this->empresaDeTrabajo($request));
        $this->tenant->conEmpresa($empresa->id, fn () => app(DocumentosParaContratar::class)->guardar($request->user(), $empresa, $request->only(['documentos'])));

        return redirect()->route('candidatos.configuracion')->with('ok', 'Lista de documentos guardada. Desde ahora cada ficha muestra cuántos obligatorios le faltan.');
    }

    public function guardarPlazos(Request $request): RedirectResponse
    {
        Gate::authorize('candidatos.configurar');
        abort_unless(app(Autorizador::class)->alcanceDeEmpresa($request->user(), 'candidatos.configurar'), 403,
            'Los plazos son de toda la empresa: hace falta «configurar» con alcance de empresa.');
        $empresa = Empresa::findOrFail($this->empresaDeTrabajo($request));
        app(PlazosCandidatos::class)->guardar($request->user(), $empresa, $request->only(array_keys(PlazosCandidatos::DEFECTO)));

        return redirect()->route('candidatos.configuracion', [], 303)->withFragment('plazos')->with('ok', 'Plazos guardados. Los recordatorios usan estos tiempos desde la próxima revisión.');
    }

    /** «Contrato firmado» (desde Elegido): fecha, quién lo recibió y el archivo opcional. */
    public function contratoFirmado(Request $request, int $candidato): RedirectResponse
    {
        Gate::authorize('candidatos.editar');
        $this->existe($request, $candidato, 'candidatos.editar');
        $request->validate([
            'fecha' => ['nullable', 'date_format:Y-m-d'],
            'archivo' => ['nullable', 'file', 'max:'.DocumentosCandidato::MAXIMO_KB, 'mimes:pdf,jpg,jpeg,png'],
        ], ['fecha.*' => 'Revisa la fecha de la firma.', 'archivo.max' => 'El contrato pesa más de 5 MB.', 'archivo.*' => 'El contrato debe ser PDF, JPG o PNG.']);

        return $this->conCandidato($request, $candidato, 'candidatos.editar', function (Candidato $c) use ($request) {
            app(DocumentosParaContratar::class)->contratoFirmado($request->user(), $c, $request->only(['fecha']), $request->file('archivo'));
            $faltan = app(DocumentosParaContratar::class)->faltantesParaContratar($c->fresh()->load('documentos'), app(Postulaciones::class)->activa($c));

            return 'Contrato firmado registrado. '.($faltan === [] ? 'El expediente está completo: ya puedes Contratar.' : 'Todavía falta: '.implode('; ', array_map(fn ($f) => str_replace('Falta: ', '', $f), $faltan)).'.');
        });
    }

    /** «Recibido en papel» (checklist de la ficha): el documento se entregó físico y no se escanea. */
    public function documentoEnPapel(Request $request, int $candidato): RedirectResponse
    {
        Gate::authorize('candidatos.editar');

        return $this->conCandidato($request, $candidato, 'candidatos.editar', function (Candidato $c) use ($request) {
            $servicio = app(DocumentosParaContratar::class);
            $servicio->recibidoEnPapel($request->user(), $c, $request->only(['tipo', 'fecha']));
            $estado = $servicio->estado($c->fresh()->load('documentos'), app(Postulaciones::class)->activa($c));

            return 'Documento recibido en papel. '.$servicio->resumen($estado).($estado['faltan'] === [] ? ': completo.' : '; falta: '.implode(', ', $estado['faltan']).'.');
        });
    }

    /** «Conservar 6 meses más» (caducidad): desde la ficha o el botón de la campana. */
    public function conservar(Request $request, int $candidato): RedirectResponse
    {
        Gate::authorize('candidatos.editar');

        return $this->conCandidato($request, $candidato, 'candidatos.editar', function (Candidato $c) use ($request) {
            $hasta = app(CaducidadCandidatos::class)->conservar($request->user(), $c);

            return "La ficha de {$c->nombre_completo} se conservará hasta el ".app(HoraLocal::class)->formatear($hasta, 'd/m/Y').'.';
        });
    }
    // Fin Fase 3

    // ------------------------------------------------------------------ Ayudas

    /** @return array<string, mixed> */
    private function filtros(Request $request): array
    {
        $etapa = Entrada::texto($request->query('etapa'));

        return [
            'q' => mb_substr(trim(Entrada::texto($request->query('q'))), 0, 100),
            'etapa' => array_key_exists($etapa, Candidato::ETAPAS) || in_array($etapa, ['en_proceso', 'por_entrevistar'], true) ? $etapa : '',
            'sede' => (int) Entrada::texto($request->query('sede'), '0'),
            'departamento' => (int) Entrada::texto($request->query('departamento'), '0'),
            'vacante' => (int) Entrada::texto($request->query('vacante'), '0'),
            // Mis pendientes → «Solicitudes por revisar» (lo que el candidato llenó en el kiosco o por internet)
            'revisar' => Entrada::texto($request->query('revisar')) === '1',
            // Fase 3: postulaciones archivadas por caducidad
            'archivadas' => Entrada::texto($request->query('archivadas')) === '1',
            'caducar' => Entrada::texto($request->query('caducar')) === '1',
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
            // Mis pendientes → «Por entrevistar (RR. HH.)»
            ->when($f['etapa'] === 'por_entrevistar', fn ($x) => $x->whereIn('candidatos.etapa', Candidato::POR_ENTREVISTAR))
            ->when($f['etapa'] !== '' && ! in_array($f['etapa'], ['en_proceso', 'por_entrevistar'], true), fn ($x) => $x->where('candidatos.etapa', $f['etapa']))
            ->when($f['sede'] > 0, fn ($x) => $x->where('candidatos.sede_id', $f['sede']))
            ->when($f['departamento'] > 0, fn ($x) => $x->where('candidatos.departamento_id', $f['departamento']))
            // Vacante: la de cualquiera de sus postulaciones (no solo la activa)
            ->when(($f['vacante'] ?? 0) > 0, fn ($x) => $x->where(fn ($w) => $w->where('candidatos.vacante_id', $f['vacante'])
                ->orWhereIn('candidatos.id', Postulacion::where('vacante_id', $f['vacante'])->select('candidato_id'))))
            ->when($f['revisar'] ?? false, fn ($x) => $x->where('candidatos.autocaptura_pendiente', true))
            // Fase 3: lista «Por caducar» (con fecha de borrado) y postulaciones archivadas
            ->when($f['caducar'] ?? false, fn ($x) => $x->whereNotNull('candidatos.borrar_en')->orderBy('candidatos.borrar_en'));
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

    /**
     * Candidatos → Criterios de entrevista (ADR-0010): los criterios con los que
     * RR. HH. y el jefe califican; se guardan con el mismo formulario de
     * ajustes de Recepción (parte «criterios»).
     */
    public function criterios(Request $request): View
    {
        Gate::authorize('candidatos.configurar');
        $empresaId = $this->empresaDeTrabajo($request);

        return view('rh.candidatos.criterios', [
            'empresa' => Empresa::findOrFail($empresaId),
            'puedeGuardar' => app(Autorizador::class)->alcanceDeEmpresa($request->user(), 'candidatos.configurar'),
        ]);
    }

    private function empresaDeTrabajo(Request $request): int
    {
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        return $empresaId;
    }
}
