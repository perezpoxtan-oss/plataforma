<?php

namespace App\Http\Controllers\RecursosHumanos;

use App\Http\Controllers\Controller;
use App\Models\Acceso;
use App\Models\Autorizacion;
use App\Models\Delegacion;
use App\Models\Departamento;
use App\Models\DepartamentoResponsable;
use App\Models\Empresa;
use App\Models\Sede;
use App\Services\Accesos\ConsultaAccesos;
use App\Services\Autorizaciones\Autorizaciones;
use App\Services\Autorizaciones\Cobertura;
use App\Services\Autorizaciones\Delegaciones;
use App\Services\Autorizaciones\Escalamiento;
use App\Services\Candidatos\CambioNoPermitido;
use App\Services\Candidatos\Entrevistas;
use App\Services\Permisos\Autorizador;
use App\Services\Recepcion\AjustesRecepcion;
use App\Support\Entrada;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Autorizaciones departamentales: bandeja «Por responder» (visitas),
 * historial (incluye las de candidatos del proceso anterior), respuesta (también desde el botón del correo, con
 * dirección firmada que pide iniciar sesión y confirmar), responsables por
 * departamento, delegaciones («No molestar») y el estado en vivo para la caseta.
 */
class AutorizacionController extends Controller
{
    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly Autorizaciones $autorizaciones,
        private readonly Delegaciones $delegaciones,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('autorizaciones.ver');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        if ($empresaId === null) {
            return view('rh.autorizaciones.index', ['sinEmpresa' => true]);
        }
        $estado = Entrada::texto($request->query('estado'));
        $estado = array_key_exists($estado, Autorizacion::ESTADOS) ? $estado : '';

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $estado, $empresaId) {
            $porResponder = $this->autorizaciones->pendientesPara($actor);
            // Las de Recepción de RR. HH. se atienden en Recepción (no son de un departamento)
            $historial = $this->autorizaciones->limitar(Autorizacion::query(), $actor)->with($this->autorizaciones->relaciones())
                ->where('tipo', '!=', 'recepcion')
                ->when($estado !== '', fn ($q) => $q->where('estado', $estado))
                ->orderByDesc('id')->paginate(20)->withQueryString();
            $configura = $actor->can('autorizaciones.configurar');
            $misDelegaciones = Delegacion::with(['usuario:id,name', 'delegado:id,name', 'canceladaPor:id,name'])
                ->when(! $configura, fn ($q) => $q->where(fn ($w) => $w->where('user_id', $actor->id)->orWhere('delegado_id', $actor->id)))
                ->where('hasta', '>=', now()->subDays(30))->orderByDesc('id')->limit(30)->get();

            return view('rh.autorizaciones.index', [
                'sinEmpresa' => false,
                'porResponder' => $porResponder,
                'historial' => $historial,
                'estado' => $estado,
                'delegaciones' => $misDelegaciones,
                'usuarios' => $actor->can('autorizaciones.configurar') ? $this->delegaciones->candidatosADelegado($empresaId) : collect(),
                'miDelegacion' => $this->delegaciones->activaDe($actor), // C-12
                'esResponsable' => DepartamentoResponsable::where('user_id', $actor->id)->exists(),
                // Candidatos fase 2: las entrevistas que Recursos Humanos le canalizó (pantalla Entrevistar)
                'entrevistas' => $actor->can('candidatos.evaluar') ? app(Entrevistas::class)->pendientes($actor)->count() : null,
                'puede' => [
                    'responder' => $actor->can('autorizaciones.responder'),
                    'configurar' => $configura,
                ],
            ]);
        });
    }

    public function show(Request $request, int $autorizacion): View
    {
        // Departamentales: autorizaciones.ver; las de Recepción: quien atiende Recepción de RR. HH.
        abort_unless($this->puedeVerAlguna($request), 403);
        $actor = $request->user();
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $autorizacion) {
            $a = $this->buscar($actor, $autorizacion);

            return view('rh.autorizaciones.show', ['a' => $a, 'puedeResponder' => $a->pendiente() && $this->autorizaciones->puedeResponder($actor, $a), 'confirmar' => null]);
        });
    }

    /**
     * Botón del correo: dirección firmada (vence en 24 h). Pide iniciar sesión
     * (middleware auth) y muestra la solicitud con el botón para confirmar.
     */
    public function confirmar(Request $request, int $autorizacion): View
    {
        abort_unless($this->puedeResponderAlguna($request), 403);
        $actor = $request->user();
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $actor, $autorizacion) {
            $a = $this->buscar($actor, $autorizacion);
            abort_unless($request->hasValidSignature(), 403, 'El enlace del correo ya venció o fue alterado. Abre la solicitud desde la plataforma.');
            $respuesta = Entrada::texto($request->query('respuesta'));
            abort_unless(array_key_exists($respuesta, Autorizacion::RESPUESTAS[$a->tipo]), 404);

            return view('rh.autorizaciones.show', ['a' => $a, 'puedeResponder' => $a->pendiente() && $this->autorizaciones->puedeResponder($actor, $a), 'confirmar' => $respuesta]);
        });
    }

    public function responder(Request $request, int $autorizacion): RedirectResponse
    {
        abort_unless($this->puedeResponderAlguna($request), 403);
        // Primero el registro (otra empresa o fuera de alcance → 404) y después la validación
        $this->tenant->conEmpresa($this->empresaDeTrabajo($request), fn () => $this->buscar($request->user(), $autorizacion));
        $datos = $request->validate([
            'respuesta' => ['required', Rule::in(array_keys(Autorizacion::BOTONES))],
            'comentario' => ['nullable', 'string', 'max:500'],
            'medio' => ['nullable', Rule::in(['plataforma', 'correo'])],
            'volver' => ['nullable', Rule::in(['recepcion'])],
            'atender' => ['nullable', 'boolean'], // Recepción: «Que pase y lo atiendo»
        ], ['respuesta.*' => 'Elige una respuesta.', 'comentario.max' => 'El comentario admite máximo 500 caracteres.']);
        $empresaId = $this->empresaDeTrabajo($request);

        try {
            $a = $this->tenant->conEmpresa($empresaId, function () use ($request, $autorizacion, $datos) {
                $a = $this->buscar($request->user(), $autorizacion);

                return $this->autorizaciones->responder($request->user(), $a, $datos['respuesta'], $datos['comentario'] ?? null, $datos['medio'] ?? 'plataforma',
                    filter_var($datos['atender'] ?? false, FILTER_VALIDATE_BOOL));
            });
        } catch (CambioNoPermitido $e) {
            return ($datos['volver'] ?? null) === 'recepcion'
                ? redirect()->route('recepcion.index')->with('error', $e->getMessage())
                : redirect()->route('autorizaciones.show', $autorizacion)->with('error', $e->getMessage());
        }

        $mensaje = match (true) {
            $a->tipo === 'recepcion' && $datos['respuesta'] === 'espere' => "Listo: la caseta verá que {$a->titulo()} debe esperar.",
            $a->tipo === 'recepcion' && $a->estado === 'autorizada' => "Listo: {$a->titulo()} ya puede pasar. La caseta ya lo ve."
                .($a->candidato_id && $a->candidato?->fresh()?->etapa === 'con_rh' ? ' Queda contigo («Con RR. HH.»): cuando lo entrevistes, califícalo desde su ficha.' : ''),
            $a->tipo === 'recepcion' => "Respuesta registrada: {$a->titulo()} no puede pasar. La caseta ya lo ve.",
            $a->estado === 'autorizada' => "Listo: autorizaste el ingreso de {$a->titulo()}. La caseta ya lo ve.",
            default => "Respuesta registrada: rechazaste a {$a->titulo()}.",
        };
        if ($a->tipo === 'recepcion') {
            return $request->user()->can('recepcion_rh.ver')
                ? redirect()->route('recepcion.index')->with('ok', $mensaje)
                : redirect()->route('autorizaciones.show', $a->id)->with('ok', $mensaje);
        }

        // C-09: quien respondió su propia visita sin ver la bandeja vuelve a la solicitud
        return $request->user()->can('autorizaciones.ver')
            ? redirect()->route('autorizaciones.index')->with('ok', $mensaje)
            : redirect()->route('autorizaciones.show', $a->id)->with('ok', $mensaje);
    }

    // ------------------------------------------------- Responsables y ajustes

    public function responsables(Request $request): View
    {
        Gate::authorize('autorizaciones.configurar');
        $actor = $request->user();
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, fn () => view('rh.autorizaciones.responsables', [
            'departamentos' => Departamento::with(['sedes:sedes.id,nombre'])->where('activo', true)->orderBy('nombre')->get(['id', 'nombre', 'todas_las_sedes']),
            // C-08: las filas con su cuenta (para marcar «cuenta dada de baja», «sin permiso», «no puede entrar a…»)
            'responsables' => ($cobertura = app(Cobertura::class))->filas()->load('sede:id,nombre')->groupBy('departamento_id'),
            'cobertura' => $cobertura,
            'sinCobertura' => $cobertura->sedesSinCobertura(),
            'funciones' => $cobertura->porFuncion(), // Revisión Etapa 3a
            'usuarios' => $usuarios = $this->delegaciones->candidatosAResponsable($empresaId),
            // A-04: a qué sedes puede entrar cada candidato (el diálogo solo ofrece esas)
            'alcances' => $usuarios->mapWithKeys(fn ($u) => [$u->id => $cobertura->sedesDe($u)])->all(),
            'sedes' => Sede::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'empresa' => Empresa::findOrFail($empresaId),
            'requiere' => app(AjustesRecepcion::class)->visitasRequierenAutorizacion(Empresa::findOrFail($empresaId)),
            'deEmpresa' => app(Autorizador::class)->alcanceDeEmpresa($actor, 'autorizaciones.configurar'),
        ]));
    }

    public function guardarResponsables(Request $request, int $departamento): RedirectResponse
    {
        Gate::authorize('autorizaciones.configurar');
        abort_unless(app(Autorizador::class)->alcanceDeEmpresa($request->user(), 'autorizaciones.configurar'), 403,
            'Los responsables son de toda la empresa: hace falta el permiso «configurar» con alcance de empresa.');
        $empresaId = $this->empresaDeTrabajo($request);
        $filas = $request->input('responsables', []);

        $nombre = $this->tenant->conEmpresa($empresaId, function () use ($request, $departamento, $empresaId, $filas) {
            $d = Departamento::find($departamento);
            abort_if($d === null, 404);
            $this->delegaciones->guardarResponsables($request->user(), $empresaId, $d, is_array($filas) ? $filas : []);

            return $d->nombre;
        });

        return redirect()->to(route('autorizaciones.responsables').'#depto-'.$departamento)->with('ok', "Responsables de {$nombre} guardados.");
    }

    // ------------------------------------------------------------ Delegaciones

    public function delegar(Request $request): RedirectResponse
    {
        Gate::authorize('autorizaciones.responder');
        // C-12: la pantalla ofrece la propia delegación en Mi cuenta; esta ruta se conserva (quien configura delega por otra persona)
        $empresaId = $this->empresaDeTrabajo($request);
        $configura = $request->user()->can('autorizaciones.configurar');
        $d = $this->tenant->conEmpresa($empresaId, fn () => $this->delegaciones->crear($request->user(), $empresaId, $request->except(['_token', '_dialogo']), $configura));

        return redirect()->route('autorizaciones.index')->with('ok', "Delegación guardada: mientras esté activa, lo que le toque aprobar a {$d->usuario?->name} le llega a {$d->delegado?->name}.");
    }

    public function cancelarDelegacion(Request $request, int $delegacion): RedirectResponse
    {
        Gate::authorize('autorizaciones.responder');
        $actor = $request->user();
        $empresaId = $this->empresaDeTrabajo($request);

        try {
            $this->tenant->conEmpresa($empresaId, function () use ($actor, $delegacion) {
                $d = Delegacion::with('usuario:id,name', 'delegado:id,name')->find($delegacion);
                abort_if($d === null || ($d->user_id !== $actor->id && ! $actor->can('autorizaciones.configurar')), 404);
                $this->delegaciones->cancelar($actor, $d);
            });
        } catch (CambioNoPermitido $e) {
            return redirect()->route('autorizaciones.index')->with('error', $e->getMessage());
        }

        return redirect()->route('autorizaciones.index')->with('ok', 'Delegación cancelada: los avisos vuelven a llegarle al responsable.');
    }

    // --------------------------------------------------------- Caseta en vivo

    /**
     * Estado de las visitas que esperan autorización (la caseta lo consulta
     * cada 15 s en «Pendientes de Autorización»).
     */
    public function estadoCaseta(Request $request): JsonResponse
    {
        Gate::authorize('accesos.ver');
        $ids = collect(explode(',', Entrada::texto($request->query('ids'))))->map(fn ($v) => (int) $v)->filter()->take(100)->values();
        $empresaId = $this->empresa->id($request->user());
        if ($empresaId === null || $ids->isEmpty()) {
            return response()->json(['estados' => (object) []]);
        }

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $ids, $empresaId) {
            // Si ya tocaba recordar o escalar alguna, se hace en este mismo sondeo (hosting sin cron confiable)
            $escalamiento = app(Escalamiento::class);
            $escalamiento->escalarSiToca($empresaId);
            $accesos = app(ConsultaAccesos::class)->limitar(Acceso::query(), $request->user(), 'accesos.ver')
                ->whereIn('accesos.id', $ids)
                ->with(['autorizacionDepartamento.departamento:id,nombre', 'autorizacionDepartamento.colaborador:id,nombre,apellido_paterno,apellido_materno', 'departamento:id,nombre', 'host:id,nombre,apellido_paterno,apellido_materno'])
                ->get(['accesos.id', 'accesos.estado', 'accesos.autorizacion', 'accesos.tipo', 'accesos.entrada_at', 'accesos.departamento_id', 'accesos.host_colaborador_id', 'accesos.sede_id']);
            $estados = $accesos->mapWithKeys(fn ($a) => [$a->id => $a->estado.'|'.($a->autorizacion ?? '')]);
            // Reloj, color y «Se escaló a» de los que siguen pendientes
            $esperas = collect($escalamiento->esperasPara($accesos, Empresa::find($empresaId)))
                ->map(fn ($e) => ['minutos' => $e['minutos'], 'nivel' => $e['nivel'], 'escalado' => $e['escalado']]);

            return response()->json(['estados' => (object) $estados->all(), 'esperas' => (object) $esperas->all()]);
        });
    }

    // ------------------------------------------------------------------ Ayudas

    /** Departamentales (autorizaciones.ver) o de Recepción de RR. HH. (candidatos.editar o recepcion_rh.ver). */
    private function puedeVerAlguna(Request $request): bool
    {
        $u = $request->user();

        // C-09: la persona citada ve su propia visita (cada solicitud se revisa en buscar())
        return $u->can('autorizaciones.ver') || $u->can('candidatos.editar') || $u->can('recepcion_rh.ver') || $this->tieneColaborador($u);
    }

    /** C-09: cuenta ligada a una persona del directorio (puede tener visitas que la esperan). */
    private function tieneColaborador($u): bool
    {
        return ($u->getAttributes()['colaborador_id'] ?? null) !== null;
    }

    /** Responder: el responsable (autorizaciones.responder) o quien atiende Recepción de RR. HH. Cada solicitud lo revisa otra vez. */
    private function puedeResponderAlguna(Request $request): bool
    {
        $u = $request->user();

        // Ver el panel de Recepción de RR. HH. no da el «Que pase» (lo da quien edita candidatos)
        return $u->can('autorizaciones.responder') || $u->can('candidatos.editar') || $this->tieneColaborador($u);
    }

    private function buscar($actor, int $id): Autorizacion
    {
        $a = $this->autorizaciones->limitar(Autorizacion::query(), $actor)->with($this->autorizaciones->relaciones())->find($id);
        if ($a === null && ($fuera = Autorizacion::with('sede:id,nombre')->find($id)) !== null
            && in_array((int) $actor->id, array_map('intval', [...(array) ($fuera->avisados ?? []), ...(array) ($fuera->escalado_a ?? [])]), true)) {
            // A-04: le llegó el aviso, pero la solicitud es de una sede a la que su cuenta no entra: explicación en vez de 404
            throw new HttpResponseException(redirect()->route($actor->can('autorizaciones.ver') ? 'autorizaciones.index' : 'panel')
                ->with('error', 'Esta solicitud es de '.($fuera->sede?->nombre ?? 'otra sede').' y tu cuenta no puede responder ahí. Ya se avisó a quien sí puede; si crees que te toca, pide a tu administrador que revise tus sedes.'));
        }
        abort_if($a === null, 404);

        return $a;
    }

    private function empresaDeTrabajo(Request $request): int
    {
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        return $empresaId;
    }
}
