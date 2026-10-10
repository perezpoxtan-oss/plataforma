<?php

namespace App\Http\Controllers\RecursosHumanos;

use App\Http\Controllers\Controller;
use App\Models\Acceso;
use App\Models\Autorizacion;
use App\Models\Candidato;
use App\Models\Empresa;
use App\Models\EnlaceKiosco;
use App\Models\Postulacion;
use App\Models\Sede;
use App\Services\Accesos\ConsultaAccesos;
use App\Services\Autorizaciones\Autorizaciones;
use App\Services\Candidatos\AdministradorCandidatos;
use App\Services\Candidatos\CambioNoPermitido;
use App\Services\Candidatos\DocumentosCandidato;
use App\Services\Candidatos\Kiosco;
use App\Services\Permisos\Autorizador;
use App\Services\Recepcion\AjustesRecepcion;
use App\Services\Recepcion\PanelRecepcion;
use App\Support\Entrada;
use App\Support\HoraLocal;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Recepción de Recursos Humanos: panel en (casi) tiempo real, modo kiosco
 * (tableta en la sala de espera con el QR de cada candidato), métricas de
 * espera, ajustes (aviso de privacidad, kiosco) y las fotos de la caseta.
 */
class RecepcionController extends Controller
{
    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly PanelRecepcion $panel,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('recepcion_rh.ver');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        if ($empresaId === null) {
            return view('rh.recepcion.index', ['sinEmpresa' => true]);
        }

        return $this->tenant->conEmpresa($empresaId, function () use ($actor) {
            $filas = $this->panel->enEspera($actor);

            return view('rh.recepcion.index', [
                'sinEmpresa' => false,
                'filas' => $filas,
                'contadores' => $this->panel->contadores($actor, $filas),
                'puede' => $this->puede($actor),
            ]);
        });
    }

    /** JSON del panel (cada 15 s con la pestaña visible). */
    public function datos(Request $request): JsonResponse
    {
        Gate::authorize('recepcion_rh.ver');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        if ($empresaId === null) {
            return response()->json(['html' => '', 'total' => 0, 'contadores' => null]);
        }

        return response()->json($this->tenant->conEmpresa($empresaId, function () use ($actor) {
            $filas = $this->panel->enEspera($actor);

            return [
                'html' => view('rh.recepcion._lista', ['filas' => $filas, 'puede' => $this->puede($actor)])->render(),
                'total' => count($filas),
                'contadores' => $this->panel->contadores($actor, $filas),
                'hora' => app(HoraLocal::class)->formatear(now(), 'H:i'),
            ];
        }));
    }

    /** @return array<string, bool> */
    private function puede($actor): array
    {
        return [
            'editar' => $actor->can('candidatos.editar'),
            'ver' => $actor->can('candidatos.ver'),
            'kiosco' => $actor->can('candidatos.editar') || $actor->can('accesos.crear'),
            'configurar' => $actor->can('candidatos.configurar'),
            // «Que pase»: quien atiende Recepción de RR. HH. (cada solicitud lo revisa otra vez por su sede)
            'responder' => $actor->can('candidatos.editar') || $actor->can('recepcion_rh.ver'),
        ];
    }

    public function metricas(Request $request): View
    {
        Gate::authorize('recepcion_rh.ver');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        $hora = app(HoraLocal::class);
        $hoy = $hora->formatear(now(), 'Y-m-d');
        $desde = $this->fecha(Entrada::texto($request->query('desde')), $hora->formatear(now()->subDays(29), 'Y-m-d'));
        $hasta = $this->fecha(Entrada::texto($request->query('hasta')), $hoy);
        if ($desde > $hasta) {
            [$desde, $hasta] = [$hasta, $desde];
        }
        $sede = (int) Entrada::texto($request->query('sede'), '0') ?: null;
        if ($empresaId === null) {
            return view('rh.recepcion.metricas', ['sinEmpresa' => true]);
        }

        return $this->tenant->conEmpresa($empresaId, fn () => view('rh.recepcion.metricas', [
            'sinEmpresa' => false,
            'm' => $this->panel->metricas($actor, $desde, $hasta, $sede),
            'desde' => $desde, 'hasta' => $hasta, 'sede' => $sede,
            'sedes' => app(AdministradorCandidatos::class)->sedesParaElegir($actor, 'recepcion_rh.ver'),
        ]));
    }

    // ------------------------------------------------------------------ Kiosco

    /**
     * Modo kiosco (tableta en la sala de espera, con sesión): candidatos en
     * proceso de hoy con su código; al elegir uno se muestra su QR en grande.
     */
    public function kiosco(Request $request): View
    {
        abort_unless($request->user()->can('candidatos.editar') || $request->user()->can('accesos.crear'), 403);
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        if ($empresaId === null) {
            return view('rh.recepcion.kiosco', ['sinEmpresa' => true]);
        }

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $request) {
            $consulta = $this->candidatosParaKiosco($actor);
            $lista = (clone $consulta)->with(['enlaces' => fn ($q) => $q->whereNull('revocado_en')->where('expira_en', '>', now())])
                ->whereIn('etapa', Candidato::ABIERTAS)->orderByDesc('id')->limit(60)->get(['id', 'nombre_completo', 'etapa', 'sede_id', 'vacante', 'puesto_id']);
            $elegidoId = (int) Entrada::texto($request->query('candidato'), '0');
            $elegido = $elegidoId > 0 ? $lista->firstWhere('id', $elegidoId) : null;
            $enlace = $elegido?->enlaces->first(fn (EnlaceKiosco $e) => $e->vigente());
            $url = $enlace ? route('kiosco.codigo', ['codigo' => $enlace->codigo]) : null;

            return view('rh.recepcion.kiosco', [
                'sinEmpresa' => false,
                'lista' => $lista,
                'elegido' => $elegido,
                'enlace' => $enlace,
                'url' => $url,
                'qr' => $url ? $this->qr($url) : null,
                'urlCodigo' => route('kiosco.codigo'),
            ]);
        });
    }

    public function generarEnlace(Request $request, int $candidato): RedirectResponse
    {
        abort_unless($request->user()->can('candidatos.editar') || $request->user()->can('accesos.crear'), 403);
        $empresaId = $this->empresaDeTrabajo($request);

        try {
            $this->tenant->conEmpresa($empresaId, function () use ($request, $candidato) {
                $c = $this->candidatosParaKiosco($request->user())->find($candidato);
                abort_if($c === null, 404);
                // «QR para que llene su solicitud» (ficha): muestra el vigente o crea uno
                $vigente = $request->boolean('reusar')
                    && $c->enlaces()->whereNull('revocado_en')->where('expira_en', '>', now())->get()->contains(fn (EnlaceKiosco $e) => $e->vigente());
                if (! $vigente) {
                    app(Kiosco::class)->generar($request->user(), $c);
                }
            });
        } catch (CambioNoPermitido $e) {
            return redirect()->route('recepcion.kiosco')->with('error', $e->getMessage());
        }

        return redirect()->route('recepcion.kiosco', ['candidato' => $candidato])->with('ok', 'Listo: que el candidato escanee el QR con su celular (o escriba el código).');
    }

    /**
     * Candidatos que el usuario puede mandar al kiosco: con candidatos.editar
     * los de sus sedes; la caseta (accesos.crear), los que se registraron en
     * sus sedes con un acceso (sin ver su CV).
     *
     * @return Builder<Candidato>
     */
    private function candidatosParaKiosco($actor)
    {
        $admin = app(AdministradorCandidatos::class);
        if ($actor->can('candidatos.editar')) {
            return $admin->limitar(Candidato::query(), $actor, 'candidatos.editar');
        }
        $sedes = app(ConsultaAccesos::class)->sedes($actor, 'accesos.crear');

        return Candidato::query()->whereNotNull('acceso_id')->when($sedes !== null, fn ($q) => $q->whereIn('sede_id', $sedes));
    }

    private function qr(string $texto): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(320, 1), new SvgImageBackEnd)))->writeString($texto);

        return (string) preg_replace('/^<\?xml[^>]*>\s*/', '', $svg);
    }

    // ------------------------------------------------------------------ Ajustes

    public function ajustes(Request $request): View
    {
        Gate::authorize('candidatos.configurar');
        $empresaId = $this->empresaDeTrabajo($request);

        return view('rh.recepcion.ajustes', ['empresa' => Empresa::findOrFail($empresaId)]);
    }

    public function guardarAjustes(Request $request): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor->can('candidatos.configurar') || $actor->can('autorizaciones.configurar'), 403);
        $empresaId = $this->empresaDeTrabajo($request);
        // Son de toda la empresa: con alcance de sede solo se consultan
        $autorizador = app(Autorizador::class);
        $privacidad = $actor->can('candidatos.configurar') && $autorizador->alcanceDeEmpresa($actor, 'candidatos.configurar');
        $autorizaciones = $actor->can('autorizaciones.configurar') && $autorizador->alcanceDeEmpresa($actor, 'autorizaciones.configurar');
        abort_unless($privacidad || $autorizaciones, 403, 'Estos ajustes son de toda la empresa: hace falta el permiso «configurar» con alcance de empresa.');
        // Cada formulario manda solo su parte (aviso de privacidad o autorización de visitas)
        $privacidad = $privacidad && $request->has('aviso_privacidad');
        $autorizaciones = $autorizaciones && $request->has('visitas_requieren_autorizacion');
        if (! $privacidad && ! $autorizaciones) {
            return back()->with('error', 'No hubo cambios que guardar.');
        }

        app(AjustesRecepcion::class)->guardar($actor, Empresa::findOrFail($empresaId), $request->only(['aviso_privacidad', 'visitas_requieren_autorizacion', 'kiosco_horas', 'kiosco_usos', 'rh_autoriza_paso']), $privacidad, $autorizaciones);

        return back()->with('ok', 'Ajustes de Recepción guardados.');
    }

    // -------------------------------------------------------- Fotos de caseta

    public function fotoPersona(Request $request, int $acceso): StreamedResponse
    {
        return $this->foto($request, $acceso, 'foto_persona');
    }

    public function fotoIdentificacion(Request $request, int $acceso): StreamedResponse
    {
        return $this->foto($request, $acceso, 'foto_identificacion');
    }

    /**
     * Foto de la caseta: la ve quien ve ese acceso (accesos.ver en su sede) o
     * Recursos Humanos si el acceso es de un candidato de sus sedes.
     */
    private function foto(Request $request, int $id, string $campo): StreamedResponse
    {
        $actor = $request->user();
        abort_unless($actor->can('accesos.ver') || $actor->can('candidatos.ver') || $actor->can('recepcion_rh.ver') || $actor->can('autorizaciones.ver'), 403);
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $id, $campo) {
            $acceso = $actor->can('accesos.ver') ? app(ConsultaAccesos::class)->limitar(Acceso::query(), $actor, 'accesos.ver')->find($id) : null;
            if ($acceso === null && $actor->can('candidatos.ver')) {
                // Cualquier visita de un candidato de sus sedes (la de su ficha o las ligadas a sus postulaciones)
                $visibles = app(AdministradorCandidatos::class)->limitar(Candidato::query(), $actor, 'candidatos.ver')->select('candidatos.id');
                $acceso = Acceso::whereKey($id)->where(fn ($q) => $q->whereIn('id', Candidato::whereIn('id', $visibles)->whereNotNull('acceso_id')->select('acceso_id'))
                    ->orWhereIn('postulacion_id', Postulacion::whereIn('candidato_id', $visibles)->select('id')))->first();
            }
            if ($acceso === null && $campo === 'foto_persona' && $actor->can('recepcion_rh.ver')) {
                // El panel de Recepción muestra la foto de la persona (no la de su identificación)
                $sedes = app(Autorizador::class)->sedesPermitidas($actor, 'recepcion_rh.ver');
                $acceso = Acceso::where('motivo_visita', 'rh')->when($sedes !== null, fn ($q) => $q->whereIn('sede_id', $sedes))->find($id);
            }
            if ($acceso === null && $campo === 'foto_persona' && $actor->can('autorizaciones.ver')) {
                // El responsable que autoriza la visita ve quién es
                $visible = app(Autorizaciones::class)->limitar(Autorizacion::query(), $actor)->where('acceso_id', $id)->exists();
                $acceso = $visible ? Acceso::find($id) : null;
            }
            abort_if($acceso === null || $acceso->{$campo} === null, 404);

            return app(DocumentosCandidato::class)->respuesta($acceso->{$campo});
        });
    }

    // ------------------------------------------------------------------ Ayudas

    private function fecha(string $valor, string $defecto): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) && checkdate((int) substr($valor, 5, 2), (int) substr($valor, 8, 2), (int) substr($valor, 0, 4)) ? $valor : $defecto;
    }

    private function empresaDeTrabajo(Request $request): int
    {
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        return $empresaId;
    }
}
