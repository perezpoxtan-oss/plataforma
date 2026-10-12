<?php

namespace App\Http\Controllers\RecursosHumanos;

use App\Http\Controllers\Controller;
use App\Models\CandidatoDocumento;
use App\Models\EvaluacionCandidato;
use App\Models\Postulacion;
use App\Services\Candidatos\CambioNoPermitido;
use App\Services\Candidatos\DocumentosCandidato;
use App\Services\Candidatos\Entrevistas;
use App\Services\Manual\Manual;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * «Entrevistar» (candidatos, fase 2): el jefe o la persona que Recursos
 * Humanos asignó entrevista, evalúa y elige. Ve un RESUMEN del candidato
 * (sin CURP, RFC, NSS, domicilio ni contacto), la evaluación de RR. HH. y,
 * solo si la vacante lo permite («El jefe puede ver el CV»), el CV en PDF.
 *
 * Solo el entrevistador asignado o su delegado activo (candidatos.evaluar en
 * esa sede): para cualquier otro la entrevista no existe (404). Recursos
 * Humanos no evalúa ni elige por el jefe.
 */
class EntrevistaController extends Controller
{
    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly Entrevistas $entrevistas,
    ) {}

    /** Mis entrevistas: por evaluar (con su cita) y las que ya evalué. */
    public function index(Request $request): View
    {
        Gate::authorize('candidatos.evaluar');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        if ($empresaId === null) {
            return view('rh.entrevistas.index', ['sinEmpresa' => true]);
        }

        return $this->tenant->conEmpresa($empresaId, function () use ($actor) {
            $con = ['candidato:id,nombre_completo', 'vacantePublicada:id,titulo', 'puesto:id,nombre', 'departamento:id,nombre', 'sede:id,nombre', 'entrevistador:id,name'];

            return view('rh.entrevistas.index', [
                'sinEmpresa' => false,
                'pendientes' => $this->entrevistas->pendientes($actor)->with($con)->limit(100)->get(),
                'evaluadas' => $this->entrevistas->limitar(Postulacion::query(), $actor)->where('postulaciones.etapa', '!=', 'canalizado')
                    ->whereIn('postulaciones.id', EvaluacionCandidato::where('tipo', 'departamento')->select('postulacion_id'))
                    ->with([...$con, 'evaluaciones'])->orderByDesc('postulaciones.updated_at')->limit(30)->get(),
                'textoCita' => fn (Postulacion $p) => $this->entrevistas->textoCita($p),
                'manual' => $this->paginaManual($actor),
            ]);
        });
    }

    public function show(Request $request, int $postulacion): View
    {
        Gate::authorize('candidatos.evaluar');
        $actor = $request->user();
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $postulacion, $empresaId) {
            $p = $this->buscar($actor, $postulacion);
            $p->load(['candidato', 'vacantePublicada:id,titulo,jefe_ve_cv,plazas', 'puesto:id,nombre', 'departamento:id,nombre', 'sede:id,nombre',
                'entrevistador:id,name', 'evaluaciones.evaluador:id,name']);
            $c = $p->candidato;
            $ultima = $p->evaluaciones->where('tipo', 'departamento')->last();
            $corregible = $this->entrevistas->puedeCorregir($actor, $p, $ultima);

            return view('rh.entrevistas.show', [
                'p' => $p,
                'c' => $c,
                'evaluaciones' => $p->evaluaciones,
                'cv' => $this->documentoCv($p),
                'puedeEvaluar' => $p->etapa === 'canalizado' && $this->entrevistas->puedeEvaluar($actor, $p),
                // Corregir lo que acaba de guardar (15 minutos) y «No se presentó» cuando la cita era «Ahora, está en sala»
                'corregible' => $corregible ? $ultima : null,
                'minutosCorreccion' => Entrevistas::MINUTOS_CORRECCION,
                'puedeNoSePresento' => $p->etapa === 'canalizado' && $p->cita_ahora && $this->entrevistas->puedeEvaluar($actor, $p),
                'criterios' => $this->entrevistas->criterios($empresaId),
                'textoCita' => $this->entrevistas->textoCita($p),
                'manual' => $this->paginaManual($actor),
            ]);
        });
    }

    /**
     * Guarda la evaluación del entrevistador (Elegir / Considerar / Segunda
     * entrevista / Rechazar) o, si acaba de guardarla, la corrige.
     */
    public function evaluar(Request $request, int $postulacion): RedirectResponse
    {
        Gate::authorize('candidatos.evaluar');
        $actor = $request->user();
        $empresaId = $this->empresaDeTrabajo($request);
        // Primero el registro (otra empresa, otra sede u otro entrevistador → 404): solo evalúa
        // el entrevistador asignado o su delegado (quien evaluó antes solo consulta, salvo para corregir lo suyo)
        $this->tenant->conEmpresa($empresaId, function () use ($actor, $postulacion) {
            $p = $this->buscar($actor, $postulacion);
            abort_unless($this->entrevistas->puedeEvaluar($actor, $p) || $this->entrevistas->puedeCorregir($actor, $p, $this->ultimaDe($p)), 404);
        });
        $entrada = $request->only(['criterios', 'resultado', 'comentario', 'entrevista_en', 'entrevista_fecha', 'entrevista_hora']);

        try {
            [$e, $corregida] = $this->tenant->conEmpresa($empresaId, function () use ($actor, $postulacion, $entrada) {
                $p = $this->buscar($actor, $postulacion);
                $ultima = $this->ultimaDe($p);
                if ($ultima !== null && $this->entrevistas->puedeCorregir($actor, $p, $ultima)) {
                    return [$this->entrevistas->corregirEvaluacion($actor, $p, $ultima, $entrada), true];
                }

                return [$this->entrevistas->evaluarDepartamento($actor, $p, $entrada), false];
            });
        } catch (CambioNoPermitido $x) {
            return redirect()->route('entrevistas.show', $postulacion)->with('error', $x->getMessage());
        }

        return redirect()->route('entrevistas.index')->with('ok', match (true) {
            $corregida => 'Evaluación corregida ('.$e->etiquetaResultado().'). Recursos Humanos ya tiene la versión nueva.',
            $e->resultado === 'elegir' => 'Listo: elegiste a esta persona. Recursos Humanos ya lo sabe y se encarga de los documentos y el contrato.',
            $e->resultado === 'segunda_entrevista' => 'Listo: pediste una segunda entrevista. Recursos Humanos le pondrá fecha y te avisará; te queda en «Por evaluar».',
            default => 'Evaluación guardada ('.$e->etiquetaResultado().'). Recursos Humanos ya la tiene y se comunica con el candidato.',
        });
    }

    /** «No se presentó» desde la entrevista: solo cuando la cita era «Ahora, está en sala». */
    public function noSePresento(Request $request, int $postulacion): RedirectResponse
    {
        Gate::authorize('candidatos.evaluar');
        $actor = $request->user();
        $empresaId = $this->empresaDeTrabajo($request);
        $this->tenant->conEmpresa($empresaId, fn () => abort_unless($this->entrevistas->puedeEvaluar($actor, $this->buscar($actor, $postulacion)), 404));

        try {
            $this->tenant->conEmpresa($empresaId, fn () => $this->entrevistas->noSePresento($actor, $this->buscar($actor, $postulacion)->candidato, true));
        } catch (CambioNoPermitido $x) {
            return redirect()->route('entrevistas.show', $postulacion)->with('error', $x->getMessage());
        }

        return redirect()->route('entrevistas.index')->with('ok', 'Quedó como «No se presentó». Recursos Humanos ya lo sabe y, si vuelve a agendar, te llegará la cita nueva.');
    }

    /** CV en PDF: solo si la vacante lo permite («El jefe puede ver el CV»). Disco privado. */
    public function cv(Request $request, int $postulacion): StreamedResponse
    {
        Gate::authorize('candidatos.evaluar');
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $postulacion) {
            $p = $this->buscar($request->user(), $postulacion);
            $p->load('vacantePublicada:id,jefe_ve_cv');
            $doc = $this->documentoCv($p);
            abort_if($doc === null, 404);

            return app(DocumentosCandidato::class)->respuesta($doc->ruta, $doc->nombre_original, false);
        });
    }

    /**
     * Botón del correo: dirección firmada (vence en 72 h). Pide iniciar sesión
     * y abre la entrevista (que vuelve a revisar quién puede verla).
     */
    public function correo(Request $request, int $postulacion): RedirectResponse
    {
        Gate::authorize('candidatos.evaluar');
        $empresaId = $this->empresaDeTrabajo($request);
        $this->tenant->conEmpresa($empresaId, fn () => $this->buscar($request->user(), $postulacion));
        abort_unless($request->hasValidSignature(), 403, 'El enlace del correo ya venció o fue alterado. Abre la entrevista desde Mis pendientes.');

        return redirect()->route('entrevistas.show', $postulacion);
    }

    // ------------------------------------------------------------------ Ayudas

    /** El CV en PDF que puede ver el entrevistador (null si la vacante no lo permite o no hay). */
    private function documentoCv(Postulacion $p): ?CandidatoDocumento
    {
        if (! (bool) $p->vacantePublicada?->jefe_ve_cv) {
            return null;
        }

        return CandidatoDocumento::where('candidato_id', $p->candidato_id)->where('tipo', 'cv')->where('mime', 'application/pdf')->orderByDesc('id')->first();
    }

    private function ultimaDe(Postulacion $p): ?EvaluacionCandidato
    {
        return EvaluacionCandidato::where('postulacion_id', $p->id)->where('tipo', 'departamento')->orderByDesc('id')->first();
    }

    private function buscar($actor, int $id): Postulacion
    {
        $p = $this->entrevistas->limitar(Postulacion::query(), $actor)->find($id);
        abort_if($p === null, 404);

        return $p;
    }

    private function paginaManual($actor): ?string
    {
        $manual = app(Manual::class);
        $pagina = $manual->pagina('entrevistar-y-elegir');

        return $pagina !== null && $manual->puedeVer($actor, $pagina) ? route('manual.ver', $pagina->slug) : null;
    }

    private function empresaDeTrabajo(Request $request): int
    {
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        return $empresaId;
    }
}
