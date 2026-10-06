<?php

namespace App\Http\Controllers\Seguridad;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Equipo;
use App\Models\PaseSalida;
use App\Models\Proveedor;
use App\Models\Sede;
use App\Models\User;
use App\Services\Firmas\Firmas;
use App\Services\PasesSalida\AdministradorPasesSalida;
use App\Services\Permisos\Autorizador;
use App\Support\Entrada;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pases de salida (réplica de modules/pases_salida de SEGCAT, circuito v2):
 * lista en fichas con indicador de pasos, bandeja de firmas, ficha del pase
 * (Resumen · Artículos · Firmas y bitácora), aprobaciones en orden, pasos de
 * caseta con verificación de artículos, hoja impresa con QR y página de
 * verificación.
 *
 * Permisos: ver, crear, aprobar (aprobar, rechazar, omitir), firmar (pasos
 * de caseta), imprimir y configurar (circuito, ver CircuitoPasesSalidaController).
 * Ver App\Services\PasesSalida\AdministradorPasesSalida para el alcance.
 */
class PaseSalidaController extends Controller
{
    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly AdministradorPasesSalida $pases,
        private readonly Autorizador $autorizador,
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        Gate::authorize('pases_salida.ver');

        // Enlaces anteriores (?pase=ID) abren la ficha del pase
        if ($request->filled('pase') && is_numeric($request->input('pase'))) {
            return redirect()->route('pases-salida.show', (int) $request->input('pase'));
        }

        return $this->lista($request, null);
    }

    /**
     * Bandeja de firmas: lo que espera la firma del usuario en sesión.
     */
    public function pendientes(Request $request): View
    {
        Gate::authorize('pases_salida.ver');

        return $this->lista($request, 'mi_firma');
    }

    private function lista(Request $request, ?string $forzar): View
    {
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);

        if ($empresaId === null) {
            return view('seguridad.pases-salida.index', ['sinEmpresa' => true, 'bandeja' => $forzar !== null]);
        }

        $filtros = $request->validate([
            'filtro' => ['nullable', 'string'],
            'q' => ['nullable', 'string', 'max:200'],
            'sede' => ['nullable', 'integer'],
        ]);
        $filtro = $forzar ?? (array_key_exists($filtros['filtro'] ?? '', AdministradorPasesSalida::FILTROS) ? $filtros['filtro'] : 'todos');
        $texto = Entrada::texto($filtros['q'] ?? '');
        $sedeFiltro = isset($filtros['sede']) ? (int) $filtros['sede'] : null;

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $empresaId, $filtro, $texto, $sedeFiltro, $forzar) {
            $base = fn () => $this->pases->buscar($this->pases->limitar(PaseSalida::query(), $actor, 'pases_salida.ver'), $texto)
                ->when($sedeFiltro !== null, fn ($q) => $q->where(fn ($s) => $s->where('pases_salida.sede_id', $sedeFiltro)->orWhere('pases_salida.sede_destino_id', $sedeFiltro)));

            $conteos = collect(AdministradorPasesSalida::FILTROS)->map(fn ($t, $clave) => $this->pases->filtrar($base(), $clave, $actor)->count());

            $lista = $this->pases->filtrar($base(), $filtro, $actor)
                ->with(['sede:id,nombre', 'sedeDestino:id,nombre', 'proveedor:id,nombre', 'colaboradorDestino:id,nombre,apellido_paterno,apellido_materno',
                    'solicitante:id,num_empleado,nombre,apellido_paterno,apellido_materno', 'creador:id,name', 'editor:id,name',
                    'aprobaciones.rol:id,nombre', 'aprobaciones.departamento:id,nombre'])
                ->withCount('articulos')
                ->orderByDesc('pases_salida.id')
                ->paginate(AdministradorPasesSalida::POR_PAGINA)->withQueryString();

            $verSedes = $this->pases->sedes($actor, 'pases_salida.ver');
            $sedesVisibles = Sede::orderBy('nombre')->get(['id', 'nombre', 'activo'])
                ->filter(fn ($s) => $verSedes === null || in_array($s->id, $verSedes, true))->values();

            $puedeCrear = $actor->can('pases_salida.crear');
            $sedesOrigen = $puedeCrear ? $this->pases->sedesOrigen($actor) : collect();
            $porFirmar = $this->pases->idsPorFirmar($actor);

            return view('seguridad.pases-salida.index', [
                'sinEmpresa' => false,
                'bandeja' => $forzar !== null,
                'pases' => $lista,
                'filtro' => $filtro,
                'texto' => $texto,
                'sedeFiltro' => $sedeFiltro,
                'conteos' => $conteos,
                'sedesVisibles' => $sedesVisibles,
                'variasSedes' => $sedesVisibles->count() > 1,
                'pasesSrv' => $this->pases,
                'porFirmar' => $porFirmar,
                'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial'),
                'puede' => [
                    'crear' => $puedeCrear && $sedesOrigen->isNotEmpty(),
                    'colaborador' => $actor->can('colaboradores.crear') || $actor->can('colaboradores.provisional'),
                    'proveedor' => $actor->can('proveedores.crear'),
                    'equipos' => $actor->can('equipos.ver'),
                    'configurar' => $actor->can('pases_salida.configurar'),
                ],
                'formulario' => $puedeCrear && $sedesOrigen->isNotEmpty() ? $this->formulario($sedesOrigen) : null,
                'anteriores' => $this->colaboradoresAnteriores(),
            ]);
        });
    }

    /**
     * Ficha del pase: Resumen · Artículos · Firmas y bitácora, con las
     * acciones que el usuario puede hacer ahora.
     */
    public function show(Request $request, int $pase): View
    {
        Gate::authorize('pases_salida.ver');
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $pase) {
            // Seguridad (AZ-03): un pase ajeno responde 404
            $modelo = $this->buscarEnAlcance($request->user(), $pase, 'pases_salida.ver');

            return view('seguridad.pases-salida.show', $this->datosFicha($request->user(), $modelo));
        });
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('pases_salida.crear');
        $empresaId = $this->empresaDeTrabajo($request);

        $pase = $this->tenant->conEmpresa($empresaId, fn () => $this->pases->crear($request->user(), $request->all()));

        // "Registrar y capturar siguiente": vuelve a la lista con el diálogo abierto
        if ($request->boolean('siguiente')) {
            return redirect()->route('pases-salida.index', ['nuevo' => 1])
                ->with('ok', "Pase {$pase->folio} registrado y enviado a aprobación. Captura el siguiente.");
        }

        return redirect()->route('pases-salida.show', $pase->id)->with('ok', "Pase {$pase->folio} registrado y enviado a aprobación.");
    }

    /**
     * Corregir y reenviar un pase rechazado.
     */
    public function update(Request $request, int $pase): RedirectResponse
    {
        Gate::authorize('pases_salida.crear');
        $empresaId = $this->empresaDeTrabajo($request);

        try {
            $modelo = $this->tenant->conEmpresa($empresaId, function () use ($request, $pase) {
                $modelo = $this->buscarEnAlcance($request->user(), $pase, 'pases_salida.ver');
                $this->autorizar(fn () => $this->pases->reenviar($request->user(), $modelo, $request->all()));

                return $modelo;
            });
        } catch (ValidationException $e) {
            return redirect()->route('pases-salida.show', $pase)->withErrors($e->errors())
                ->withInput($request->except(['_token', '_method']) + ['_dialogo' => 'corregir']);
        }

        return redirect()->route('pases-salida.show', $modelo->id)->with('ok', "Pase {$modelo->folio} corregido y reenviado a aprobación.");
    }

    /**
     * Firma del paso que toca: una aprobación (paso=aprobacion, permiso
     * "aprobar") o un paso de caseta (salida, recepcion, salida_regreso,
     * regreso; permiso "firmar").
     */
    public function firmar(Request $request, int $pase): RedirectResponse
    {
        $paso = Entrada::texto($request->input('paso'));
        Gate::authorize($paso === 'aprobacion' ? 'pases_salida.aprobar' : 'pases_salida.firmar');
        $empresaId = $this->empresaDeTrabajo($request);

        try {
            [$modelo, $estado] = $this->tenant->conEmpresa($empresaId, function () use ($request, $pase, $paso) {
                $modelo = $this->buscarEnAlcance($request->user(), $pase, 'pases_salida.ver');
                $estado = $this->autorizar(fn () => $paso === 'aprobacion'
                    ? $this->pases->aprobar($request->user(), $modelo, $request->all())
                    : $this->pases->registrarPaso($request->user(), $modelo, $request->all()));

                return [$modelo->refresh(), $estado];
            });
        } catch (ValidationException $e) {
            return redirect()->route('pases-salida.show', $pase)->withErrors($e->errors())
                ->withInput($request->except(['firma', 'firma_persona', '_token']) + ['_dialogo' => $paso === 'aprobacion' ? 'aprobar' : 'paso']);
        }

        $mensaje = $paso === 'aprobacion'
            ? "Aprobación registrada en el pase {$modelo->folio}.".($estado === PaseSalida::APROBADO ? ' El pase quedó «Aprobado, listo para salir».' : ' Se avisó al siguiente aprobador.')
            : "Listo: {$modelo->folio} ahora está «{$modelo->insignia(false)[0]}».";

        return redirect()->route('pases-salida.show', $modelo->id)->with('ok', $mensaje);
    }

    /**
     * Rechazo con motivo: el pase vuelve al solicitante.
     */
    public function rechazar(Request $request, int $pase): RedirectResponse
    {
        Gate::authorize('pases_salida.aprobar');

        return $this->accionAprobacion($request, $pase, 'rechazar', fn ($m) => $this->pases->rechazar($request->user(), $m, $request->all()),
            fn ($m) => ['aviso', "Pase {$m->folio} rechazado y devuelto al solicitante."], ['motivo_rechazo']);
    }

    /**
     * Omitir un paso opcional, con comentario.
     */
    public function omitir(Request $request, int $pase): RedirectResponse
    {
        Gate::authorize('pases_salida.aprobar');

        return $this->accionAprobacion($request, $pase, 'omitir', fn ($m) => $this->pases->omitir($request->user(), $m, $request->all()),
            fn ($m) => ['ok', "Paso omitido en el pase {$m->folio}."], ['comentario']);
    }

    /**
     * Cancelar (antes de aprobarse): su dueño.
     */
    public function cancelar(Request $request, int $pase): RedirectResponse
    {
        abort_unless($request->user()->can('pases_salida.crear') || $request->user()->can('pases_salida.editar'), 403);

        return $this->accionAprobacion($request, $pase, 'cancelar', fn ($m) => $this->pases->cancelar($request->user(), $m, $request->all()),
            fn ($m) => ['aviso', "Pase {$m->folio} cancelado."], ['motivo_cancelacion']);
    }

    /**
     * Hoja impresa del pase: logo, folio, QR de verificación, artículos y todas las firmas.
     */
    public function imprimir(Request $request, int $pase): View
    {
        Gate::authorize('pases_salida.imprimir');
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $pase, $empresaId) {
            $modelo = $this->buscarEnAlcance($request->user(), $pase, 'pases_salida.imprimir');
            $this->cargarFicha($modelo);
            $empresa = Empresa::whereKey($empresaId)->first(['id', 'nombre_comercial', 'razon_social', 'logo_ruta']);
            $logo = $empresa->logo_ruta;
            $url = route('pases-salida.verificar', $modelo->codigo_verificacion);

            return view('seguridad.pases-salida.imprimir', [
                'pase' => $modelo,
                'empresa' => $empresa,
                'logo' => is_string($logo) && $logo !== '' && ! str_contains($logo, '..') && is_file(public_path($logo)) ? asset($logo) : null,
                'vencido' => $this->pases->vencido($modelo),
                'aprobaciones' => $this->pases->circuito()->rondaActual($modelo),
                'qr' => (string) preg_replace('/^<\?xml[^>]*>\s*/', '', (new Writer(new ImageRenderer(new RendererStyle(150, 1), new SvgImageBackEnd)))->writeString($url)),
                'urlVerificar' => $url,
            ]);
        });
    }

    /**
     * Verificación del QR de la hoja: confirma que el pase es auténtico y su
     * estado. Pide sesión y alcance (como todo el módulo: el pase sale de
     * sus sedes o va a ellas) y solo muestra folio y estado (nada personal).
     */
    public function verificar(Request $request, string $codigo): View
    {
        Gate::authorize('pases_salida.ver');
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $codigo) {
            $modelo = $this->pases->limitar(PaseSalida::query(), $request->user(), 'pases_salida.ver')
                ->with('sede:id,nombre')->withCount('articulos')->where('codigo_verificacion', mb_strtoupper($codigo))->first();
            abort_if($modelo === null, 404);

            return view('seguridad.pases-salida.verificar', [
                'pase' => $modelo,
                'vencido' => $this->pases->vencido($modelo),
            ]);
        });
    }

    /**
     * Imagen de una firma: solo con permiso de ver el pase y dentro de su alcance.
     */
    public function firma(Request $request, int $pase, int $firma, Firmas $firmas): StreamedResponse
    {
        abort_unless($request->user()->can('pases_salida.ver') || $request->user()->can('pases_salida.imprimir'), 403);
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $pase, $firma, $firmas) {
            $permiso = $request->user()->can('pases_salida.ver') ? 'pases_salida.ver' : 'pases_salida.imprimir';
            $modelo = $this->buscarEnAlcance($request->user(), $pase, $permiso);
            $registro = $modelo->firmas()->whereKey($firma)->first();
            abort_if($registro === null, 404);

            return $firmas->respuesta($registro->firma_ruta);
        });
    }

    /**
     * Mi firma guardada (solo la ve su dueño).
     */
    public function miFirma(Request $request, Firmas $firmas): StreamedResponse
    {
        abort_unless($request->user()->can('pases_salida.aprobar') || $request->user()->can('pases_salida.firmar'), 403);
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $firmas) {
            $guardada = $this->pases->firmaGuardada($request->user());
            abort_if($guardada === null, 404);

            return $firmas->respuesta($guardada->firma_ruta);
        });
    }

    public function borrarMiFirma(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('pases_salida.aprobar') || $request->user()->can('pases_salida.firmar'), 403);
        $empresaId = $this->empresaDeTrabajo($request);

        $borrada = $this->tenant->conEmpresa($empresaId, fn () => $this->pases->borrarFirmaUsuario($request->user()));

        return back()->with('ok', $borrada ? 'Tu firma guardada se borró. La próxima vez firmarás en el recuadro.' : 'No tenías una firma guardada.');
    }

    /**
     * Datos de un equipo escaneado con el lector para llenar un renglón de "Artículos que Salen".
     */
    public function equipo(Request $request, int $equipo): JsonResponse
    {
        Gate::authorize('pases_salida.crear');
        Gate::authorize('equipos.ver');
        $empresaId = $this->empresaDeTrabajo($request);

        $datos = $this->tenant->conEmpresa($empresaId, function () use ($request, $equipo) {
            $sedes = $this->autorizador->sedesPermitidas($request->user(), 'equipos.ver');
            $modelo = Equipo::query()->when($sedes !== null, fn ($q) => $q->whereIn('sede_id', $sedes))->find($equipo);
            abort_if($modelo === null, 404);

            return $this->pases->articuloDeEquipo($modelo);
        });

        return response()->json($datos);
    }

    // ------------------------------------------------------------------ Apoyo

    /**
     * Rechazar, omitir o cancelar: mismo manejo de errores (vuelven dentro de su diálogo).
     *
     * @param  list<string>  $conservar
     */
    private function accionAprobacion(Request $request, int $pase, string $dialogo, callable $accion, callable $mensaje, array $conservar): RedirectResponse
    {
        $empresaId = $this->empresaDeTrabajo($request);

        try {
            $modelo = $this->tenant->conEmpresa($empresaId, function () use ($request, $pase, $accion) {
                $modelo = $this->buscarEnAlcance($request->user(), $pase, 'pases_salida.ver');
                $this->autorizar(fn () => $accion($modelo));

                return $modelo->refresh();
            });
        } catch (ValidationException $e) {
            $previo = ['_dialogo' => $dialogo];
            foreach ($conservar as $campo) {
                $previo[$campo] = Entrada::texto($request->input($campo));
            }

            return redirect()->route('pases-salida.show', $pase)->withErrors($e->errors())->withInput($previo);
        }

        [$tipo, $texto] = $mensaje($modelo);

        return redirect()->route('pases-salida.show', $modelo->id)->with($tipo, $texto);
    }

    /**
     * Las reglas del circuito (quién firma) responden 403 con su explicación.
     */
    private function autorizar(callable $accion): mixed
    {
        try {
            return $accion();
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }
    }

    /**
     * Opciones del formulario "Nuevo Pase de Salida".
     *
     * @param  Collection<int, Sede>  $sedesOrigen
     * @return array<string, mixed>
     */
    private function formulario($sedesOrigen): array
    {
        $direccion = fn ($s) => implode(', ', array_filter([$s->direccion, $s->colonia, $s->ciudad]));

        return [
            'sedesOrigen' => $sedesOrigen,
            'sedesDestino' => Sede::where('activo', true)->orderBy('nombre')->get(['id', 'nombre', 'direccion', 'colonia', 'ciudad', 'telefono'])
                ->map(fn ($s) => ['id' => $s->id, 'nombre' => $s->nombre, 'direccion' => $direccion($s), 'telefono' => $s->telefono]),
            'proveedores' => Proveedor::where('activo', true)->orderBy('nombre')->get(['id', 'nombre', 'direccion', 'telefono']),
        ];
    }

    /**
     * Todo lo que pinta la ficha de un pase.
     *
     * @return array<string, mixed>
     */
    private function datosFicha(User $actor, PaseSalida $pase): array
    {
        $this->cargarFicha($pase);
        $circuito = $this->pases->circuito();
        $actual = $circuito->actual($pase);
        $paso = $pase->pasoFisico();
        $puedeAprobar = $actual !== null && $circuito->puedeAprobar($actor, $pase, $actual);
        $gestionar = $this->pases->puedeGestionar($actor, $pase);
        $corregir = $gestionar && $pase->estado === PaseSalida::RECHAZADO && $actor->can('pases_salida.crear');
        $sedesOrigen = $corregir ? $this->pases->sedesOrigen($actor) : collect();
        $vencido = $this->pases->vencido($pase);

        return [
            'pase' => $pase,
            'vencido' => $vencido,
            'etapas' => $this->pases->etapas($pase, $vencido),
            'siguiente' => $this->pases->siguiente($pase),
            'ronda' => $circuito->rondaActual($pase),
            'rondasAnteriores' => $pase->aprobaciones->where('ronda', '<', $pase->ronda)->groupBy('ronda'),
            'actual' => $actual,
            'sinFirmantes' => $actual !== null && $circuito->reglaSinFirmantes($pase, $actual),
            'paso' => $paso,
            'puede' => [
                'aprobar' => $puedeAprobar,
                'omitir' => $puedeAprobar && ! $actual->obligatorio,
                'porQueNo' => $actual !== null && ! $puedeAprobar ? $circuito->motivoNoPuede($actor, $pase, $actual) : null,
                'firmarPaso' => $paso !== null && $this->pases->puedeFirmarPaso($actor, $pase, $paso),
                'cancelar' => $gestionar,
                'corregir' => $corregir && $sedesOrigen->isNotEmpty(),
                'imprimir' => $actor->can('pases_salida.imprimir')
                    && $this->pases->limitar(PaseSalida::query(), $actor, 'pases_salida.imprimir')->whereKey($pase->id)->exists(),
                'colaborador' => $actor->can('colaboradores.crear') || $actor->can('colaboradores.provisional'),
                'proveedor' => $actor->can('proveedores.crear'),
                'equipos' => $actor->can('equipos.ver'),
            ],
            'firmaGuardada' => ($actor->can('pases_salida.aprobar') || $actor->can('pases_salida.firmar')) && $this->pases->firmaGuardada($actor) !== null,
            'personaSugerida' => $paso !== null ? $this->pases->personaSugerida($pase) : '',
            'formulario' => $corregir && $sedesOrigen->isNotEmpty() ? $this->formulario($sedesOrigen) : null,
            'anteriores' => $this->colaboradoresAnteriores($pase),
        ];
    }

    private function cargarFicha(PaseSalida $pase): void
    {
        $pase->load([
            'sede:id,nombre,direccion,colonia,ciudad', 'sedeDestino:id,nombre', 'proveedor:id,nombre',
            'solicitante:id,num_empleado,nombre,apellido_paterno,apellido_materno,departamento_id,puesto_id',
            'solicitante.departamento:id,nombre', 'solicitante.puesto:id,nombre',
            'colaboradorDestino:id,num_empleado,nombre,apellido_paterno,apellido_materno',
            'articulos', 'firmas.capturo:id,name', 'creador:id,name', 'editor:id,name', 'rechazador:id,name',
            'aprobaciones.rol:id,nombre', 'aprobaciones.departamento:id,nombre', 'aprobaciones.resolvio:id,name', 'aprobaciones.firma',
            'bitacora.firmas',
        ]);
    }

    /**
     * Tras un error al guardar (o al corregir un pase): el solicitante y el
     * colaborador destino ya elegidos, para volver a mostrarlos en el lector.
     *
     * @return array<string, string>
     */
    private function colaboradoresAnteriores(?PaseSalida $pase = null): array
    {
        $anteriores = [];
        foreach (['colaborador_id', 'colaborador_destino_id'] as $campo) {
            $id = request()->old($campo, $pase?->{$campo});
            $colaborador = is_numeric($id) ? Colaborador::find((int) $id) : null;
            if ($colaborador !== null) {
                $anteriores[$campo] = $colaborador->nombreCompleto().($colaborador->num_empleado ? ' · Núm. '.$colaborador->num_empleado : ' · provisional');
            }
        }

        return $anteriores;
    }

    private function empresaDeTrabajo(Request $request): int
    {
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        return $empresaId;
    }

    /**
     * Un pase de otra empresa o fuera de su alcance responde 404.
     */
    private function buscarEnAlcance(User $actor, int $id, string $permiso): PaseSalida
    {
        $modelo = $this->pases->limitar(PaseSalida::query(), $actor, $permiso)->find($id);
        abort_if($modelo === null, 404);

        return $modelo;
    }
}
