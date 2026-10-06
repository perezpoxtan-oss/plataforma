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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pases de salida (réplica de modules/pases_salida de SEGCAT): lista con
 * filtros, "Nuevo Pase de Salida" en 3 pasos, detalle "Firmas del pase" con
 * el circuito de firmas, rechazo y hoja impresa con todas las firmas.
 *
 * Permisos: ver, crear, aprobar (aprobaciones y rechazo), firmar (salida,
 * recepción en destino, salida de regreso y regreso) e imprimir. Ver
 * App\Services\PasesSalida\AdministradorPasesSalida para el alcance.
 */
class PaseSalidaController extends Controller
{
    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly AdministradorPasesSalida $pases,
        private readonly Autorizador $autorizador,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('pases_salida.ver');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);

        if ($empresaId === null) {
            return view('seguridad.pases-salida.index', ['sinEmpresa' => true]);
        }

        $filtros = $request->validate([
            'filtro' => ['nullable', 'string'],
            'q' => ['nullable', 'string', 'max:100'],
            'sede' => ['nullable', 'integer'],
            'pase' => ['nullable', 'integer'],
        ]);
        $filtro = array_key_exists($filtros['filtro'] ?? '', AdministradorPasesSalida::FILTROS) ? $filtros['filtro'] : 'todos';
        $texto = trim((string) ($filtros['q'] ?? ''));
        $sedeFiltro = isset($filtros['sede']) ? (int) $filtros['sede'] : null;

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $empresaId, $filtro, $texto, $sedeFiltro, $filtros) {
            $base = fn () => $this->pases->buscar($this->pases->limitar(PaseSalida::query(), $actor, 'pases_salida.ver'), $texto)
                ->when($sedeFiltro !== null, fn ($q) => $q->where(fn ($s) => $s->where('pases_salida.sede_id', $sedeFiltro)->orWhere('pases_salida.sede_destino_id', $sedeFiltro)));

            $conteos = collect(AdministradorPasesSalida::FILTROS)->map(fn ($t, $clave) => $this->pases->filtrar($base(), $clave)->count());

            $lista = $this->pases->filtrar($base(), $filtro)
                ->with(['sede:id,nombre', 'sedeDestino:id,nombre', 'proveedor:id,nombre', 'colaboradorDestino:id,nombre,apellido_paterno,apellido_materno',
                    'solicitante:id,num_empleado,nombre,apellido_paterno,apellido_materno', 'creador:id,name', 'editor:id,name'])
                ->withCount('articulos')
                ->orderByDesc('pases_salida.id')
                ->paginate(AdministradorPasesSalida::POR_PAGINA)->withQueryString();

            $verSedes = $this->pases->sedes($actor, 'pases_salida.ver');
            $sedesVisibles = Sede::orderBy('nombre')->get(['id', 'nombre', 'activo'])
                ->filter(fn ($s) => $verSedes === null || in_array($s->id, $verSedes, true))->values();

            $puedeCrear = $actor->can('pases_salida.crear');
            $sedesOrigen = $puedeCrear ? $this->pases->sedesOrigen($actor) : collect();

            // Detalle abierto al cargar: tras firmar, rechazar o un error (?pase=ID)
            $detalle = isset($filtros['pase']) ? $this->buscarEnAlcance($actor, (int) $filtros['pase'], 'pases_salida.ver') : null;

            return view('seguridad.pases-salida.index', [
                'sinEmpresa' => false,
                'pases' => $lista,
                'filtro' => $filtro,
                'texto' => $texto,
                'sedeFiltro' => $sedeFiltro,
                'conteos' => $conteos,
                'sedesVisibles' => $sedesVisibles,
                'variasSedes' => $sedesVisibles->count() > 1,
                'pasesSrv' => $this->pases,
                'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial'),
                'puede' => [
                    'crear' => $puedeCrear && $sedesOrigen->isNotEmpty(),
                    'colaborador' => $actor->can('colaboradores.crear') || $actor->can('colaboradores.provisional'),
                    'proveedor' => $actor->can('proveedores.crear'),
                    'equipos' => $actor->can('equipos.ver'),
                ],
                'formulario' => $puedeCrear && $sedesOrigen->isNotEmpty() ? $this->formulario($sedesOrigen) : null,
                'anteriores' => $this->colaboradoresAnteriores(),
                'detalle' => $detalle === null ? null : $this->datosDetalle($actor, $detalle),
            ]);
        });
    }

    /**
     * Detalle "Firmas del pase" (SEGCAT: pases_salida_modal_aprobar.php). Se
     * pide al abrir el diálogo; sin JavaScript abre la lista con el detalle.
     */
    public function show(Request $request, int $pase): View|RedirectResponse
    {
        Gate::authorize('pases_salida.ver');
        $empresaId = $this->empresaDeTrabajo($request);

        if (! $request->ajax()) {
            return redirect()->route('pases-salida.index', ['pase' => $pase]);
        }

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $pase) {
            $modelo = $this->buscarEnAlcance($request->user(), $pase, 'pases_salida.ver');

            return view('seguridad.pases-salida._detalle', $this->datosDetalle($request->user(), $modelo));
        });
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('pases_salida.crear');
        $empresaId = $this->empresaDeTrabajo($request);

        $pase = $this->tenant->conEmpresa($empresaId, fn () => $this->pases->crear($request->user(), $request->all()));

        return redirect()->to(route('pases-salida.index').'#pase-'.$pase->id)
            ->with('ok', "Pase {$pase->folio} registrado y enviado a aprobación.");
    }

    /**
     * Firma de un rol del circuito (SEGCAT: pases_salida_firmar.php).
     */
    public function firmar(Request $request, int $pase): RedirectResponse
    {
        abort_unless($request->user()->can('pases_salida.aprobar') || $request->user()->can('pases_salida.firmar'), 403);
        $empresaId = $this->empresaDeTrabajo($request);

        try {
            [$modelo, $estado] = $this->tenant->conEmpresa($empresaId, function () use ($request, $pase) {
                $modelo = $this->buscarEnAlcance($request->user(), $pase, 'pases_salida.ver');

                return [$modelo, $this->pases->firmar($request->user(), $modelo, $request->all())];
            });
        } catch (ValidationException $e) {
            return redirect()->route('pases-salida.index', ['pase' => $pase])->withErrors($e->errors())
                ->withInput($request->except(['firma', '_token']));
        }

        $rol = PaseSalida::grupoDeRol(Entrada::texto($request->input('rol')));
        $texto = $rol ? PaseSalida::GRUPOS[$rol][1][$request->input('rol')] : 'la firma';
        $mensaje = "Firma de «{$texto}» registrada en el pase {$modelo->folio}.";
        if ($estado !== null) {
            $mensaje .= ' El pase avanzó a «'.$modelo->insignia(false)[0].'».';
        }

        return redirect()->route('pases-salida.index', ['pase' => $modelo->id])->with('ok', $mensaje);
    }

    /**
     * Rechazo con motivo (SEGCAT: pases_salida_rechazar.php).
     */
    public function rechazar(Request $request, int $pase): RedirectResponse
    {
        Gate::authorize('pases_salida.aprobar');
        $empresaId = $this->empresaDeTrabajo($request);

        try {
            $modelo = $this->tenant->conEmpresa($empresaId, function () use ($request, $pase) {
                $modelo = $this->buscarEnAlcance($request->user(), $pase, 'pases_salida.ver');
                $this->pases->rechazar($request->user(), $modelo, $request->all());

                return $modelo;
            });
        } catch (ValidationException $e) {
            return redirect()->route('pases-salida.index', ['pase' => $pase])->withErrors($e->errors())
                ->withInput(['_dialogo' => 'rechazar', 'motivo_rechazo' => Entrada::texto($request->input('motivo_rechazo'))]);
        }

        return redirect()->route('pases-salida.index', ['pase' => $modelo->id])->with('aviso', "Pase {$modelo->folio} rechazado.");
    }

    /**
     * Hoja impresa del pase con todas sus firmas.
     */
    public function imprimir(Request $request, int $pase): View
    {
        Gate::authorize('pases_salida.imprimir');
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $pase, $empresaId) {
            $modelo = $this->buscarEnAlcance($request->user(), $pase, 'pases_salida.imprimir');
            $this->cargarDetalle($modelo);
            $empresa = Empresa::whereKey($empresaId)->first(['id', 'nombre_comercial', 'razon_social', 'logo_ruta']);
            $logo = $empresa->logo_ruta;

            return view('seguridad.pases-salida.imprimir', [
                'pase' => $modelo,
                'empresa' => $empresa,
                'logo' => is_string($logo) && $logo !== '' && ! str_contains($logo, '..') && is_file(public_path($logo)) ? asset($logo) : null,
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
     * Todo lo que pinta el detalle de un pase.
     *
     * @return array<string, mixed>
     */
    private function datosDetalle(User $actor, PaseSalida $pase): array
    {
        $this->cargarDetalle($pase);
        $grupo = $pase->grupoAbierto();

        return [
            'pase' => $pase,
            'grupoAbierto' => $grupo,
            'vencido' => $this->pases->vencido($pase),
            'puedeFirmar' => $grupo !== null && $this->pases->puedeFirmarGrupo($actor, $pase, $grupo),
            'puedeRechazar' => $this->pases->puedeRechazar($actor, $pase),
            'puedeImprimir' => $actor->can('pases_salida.imprimir')
                && $this->pases->limitar(PaseSalida::query(), $actor, 'pases_salida.imprimir')->whereKey($pase->id)->exists(),
            'sugeridos' => $this->nombresSugeridos($actor, $pase),
        ];
    }

    private function cargarDetalle(PaseSalida $pase): void
    {
        $pase->load([
            'sede:id,nombre,direccion,colonia,ciudad', 'sedeDestino:id,nombre', 'proveedor:id,nombre',
            'solicitante:id,num_empleado,nombre,apellido_paterno,apellido_materno,departamento_id,puesto_id',
            'solicitante.departamento:id,nombre', 'solicitante.puesto:id,nombre',
            'colaboradorDestino:id,num_empleado,nombre,apellido_paterno,apellido_materno',
            'articulos', 'firmas.capturo:id,name', 'creador:id,name', 'editor:id,name', 'rechazador:id,name',
        ]);
    }

    /**
     * Nombre que se propone al firmar, para teclear lo menos posible: el
     * solicitante en sus roles, quien se lleva el equipo cuando es un
     * colaborador y el usuario en sesión en los roles de Seguridad.
     *
     * @return array<string, string>
     */
    private function nombresSugeridos(User $actor, PaseSalida $pase): array
    {
        $solicitante = $pase->solicitante?->nombreCompleto();
        $destino = $pase->colaboradorDestino?->nombreCompleto();
        $sugeridos = [];
        foreach (PaseSalida::GRUPOS as [, $roles]) {
            foreach (array_keys($roles) as $rol) {
                $nombre = match (true) {
                    str_starts_with($rol, 'solicitante_') => $solicitante,
                    str_starts_with($rol, 'seguridad_') => $actor->name,
                    $rol === 'recibe_salida' && $pase->destino_tipo === 'colaborador' => $destino,
                    default => null,
                };
                if ($nombre) {
                    $sugeridos[$rol] = mb_strtoupper($nombre, 'UTF-8');
                }
            }
        }

        return $sugeridos;
    }

    /**
     * Tras un error al guardar: el solicitante y el colaborador destino que ya
     * se habían elegido, para volver a mostrarlos en el lector.
     *
     * @return array<string, string>
     */
    private function colaboradoresAnteriores(): array
    {
        $anteriores = [];
        foreach (['colaborador_id', 'colaborador_destino_id'] as $campo) {
            $id = request()->old($campo);
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
