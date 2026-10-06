<?php

namespace App\Http\Controllers\Padrones;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\Proveedor;
use App\Models\Sede;
use App\Models\User;
use App\Services\Padrones\AdministradorProveedores;
use App\Support\Entrada;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Proveedores / Empresas Externas (réplica de modules/proveedores de SEGCAT):
 * directorio de proveedores, contratistas, agencias, taxis y transportadoras,
 * las sedes donde opera cada uno y su ficha con personal y flotilla.
 *
 * Las reglas de alcance (empresa / sede) viven en AdministradorProveedores.
 */
class ProveedorController extends Controller
{
    public const PESTANAS = ['resumen', 'personal', 'flotilla'];

    /**
     * Presentación de cada categoría, con los textos e íconos de SEGCAT:
     * clave => [ícono, clase de la insignia, insignia, píldora del filtro, opción del formulario].
     */
    public const ESTILOS = [
        'proveedor' => ['bi-box-seam', 'bg-proveedor', 'Proveedor (Insumos)', 'Prov. Insumos/Alimentos', 'Proveedor (Insumos / Alimentos)'],
        'transporte_personal' => ['bi-bus-front', 'bg-agencia', 'Transporte de Personal', 'Transporte Personal', 'Proveedor (Transporte de Personal)'],
        'transporte_huespedes' => ['bi-suitcase-lg', 'bg-agencia', 'Transporte de Huéspedes', 'Transporte Huéspedes', 'Proveedor (Transporte de Huéspedes)'],
        'contratista' => ['bi-tools', 'bg-contratista', 'Contratista', 'Contratistas', 'Contratista (Mantenimiento / Obra)'],
        'agencia_autos' => ['bi-car-front', 'bg-agencia', 'Agencia de Autos', 'Agencias de Autos', 'Agencia de Renta de Autos'],
        'taxi' => ['bi-taxi-front-fill', 'bg-agencia', 'Taxi', 'Taxis', 'Taxi'],
        'agencia_viajes' => ['bi-airplane', 'bg-agencia', 'Agencia de Viajes', 'Agencias de Viajes', 'Agencia de Viajes'],
        'agencia_tours' => ['bi-map', 'bg-agencia', 'Agencia de Tours', 'Agencias de Tours', 'Agencia de Tours'],
        'transportadora' => ['bi-truck-front', 'bg-agencia', 'Transportadora', 'Transportadoras', 'Transportadora'],
    ];

    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly AdministradorProveedores $proveedores,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('proveedores.ver');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);

        if ($empresaId === null) {
            return view('padrones.proveedores.index', ['sinEmpresa' => true]);
        }

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $empresaId) {
            $verSedes = $this->proveedores->sedes($actor, 'proveedores.ver');
            $editar = $actor->can('proveedores.editar') ? $this->proveedores->sedes($actor, 'proveedores.editar') : [];
            $estado = $actor->can('proveedores.eliminar') ? $this->proveedores->sedes($actor, 'proveedores.eliminar') : [];
            $crear = $actor->can('proveedores.crear') ? $this->proveedores->sedes($actor, 'proveedores.crear') : [];

            $lista = $this->proveedores->consulta($actor)
                ->with(['sedes' => fn ($q) => $q->select('sedes.id', 'sedes.nombre', 'sedes.activo')->orderBy('sedes.nombre')])
                ->leftJoin('users as uc', 'uc.id', '=', 'proveedores.creado_por')
                ->leftJoin('users as ua', 'ua.id', '=', 'proveedores.actualizado_por')
                ->select('proveedores.*', 'uc.name as creado_por_nombre', 'ua.name as actualizado_por_nombre')
                ->withCount(['personas', 'vehiculos'])
                ->orderBy('proveedores.nombre')
                ->get();

            $sedes = Sede::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']);

            return view('padrones.proveedores.index', [
                'sinEmpresa' => false,
                'proveedores' => $lista,
                'sedes' => $sedes,
                'sedesFiltro' => $verSedes === null ? $sedes : $sedes->whereIn('id', $verSedes)->values(),
                'sedesAlta' => $crear === null ? $sedes : $sedes->whereIn('id', $crear)->values(),
                'sedesAsignables' => $editar === null ? $sedes : $sedes->whereIn('id', $editar)->values(),
                // Por proveedor: ¿puede editar sus datos? ¿desactivarlo? (los compartidos son de la empresa)
                'editables' => $editar === [] ? [] : $lista->filter(fn ($p) => $this->proveedores->esExclusivoDe($p, $editar))->pluck('id')->all(),
                'desactivables' => $estado === [] ? [] : $lista->filter(fn ($p) => $this->proveedores->esExclusivoDe($p, $estado))->pluck('id')->all(),
                'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial'),
                'puede' => [
                    'crear' => $crear !== [],
                    'crearEnEmpresa' => $crear === null,
                    'sedes' => $editar !== [],
                    'todasLasSedes' => $editar === null,
                ],
            ]);
        });
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('proveedores.crear');
        $empresaId = $this->empresaDeTrabajo($request);

        [$proveedor, $resultado] = $this->tenant->conEmpresa($empresaId, fn () => $this->proveedores->crear($request->user(), $request));

        $destino = redirect()->route('proveedores.index');
        // Si ya existía, se resalta la ficha del existente
        if ($resultado !== 'creado') {
            $destino->withFragment('proveedor-'.$proveedor->id);
        }

        return $destino->with(...match ($resultado) {
            'creado' => ['ok', "Empresa externa «{$proveedor->nombre}» registrada correctamente."],
            'sede_agregada' => ['ok', "Ya existía «{$proveedor->nombre}»: se agregó a tu sede."],
            default => ['aviso', "Ya existía «{$proveedor->nombre}» y ya opera en tu sede: no se creó un duplicado."],
        });
    }

    /**
     * Ficha del proveedor (SEGCAT: proveedor_ficha.php): datos, sedes donde
     * opera, su personal y su flotilla.
     */
    public function show(Request $request, int $proveedor): View
    {
        Gate::authorize('proveedores.ver');
        $actor = $request->user();
        $empresaId = $this->empresaDeTrabajo($request);
        $pestana = in_array($request->query('tab'), self::PESTANAS, true) ? $request->query('tab') : 'resumen';

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $proveedor, $pestana, $empresaId) {
            $modelo = $this->buscarVisible($actor, $proveedor);
            $modelo->load(['sedes' => fn ($q) => $q->orderBy('sedes.nombre')]);
            $verPersonas = $actor->can('visitantes.ver');
            $verVehiculos = $actor->can('vehiculos.ver');
            $editar = $actor->can('proveedores.editar') ? $this->proveedores->sedes($actor, 'proveedores.editar') : [];

            return view('padrones.proveedores.show', [
                'proveedor' => $modelo,
                'pestana' => $pestana,
                'creadoPor' => User::whereKey($modelo->creado_por)->value('name'),
                'actualizadoPor' => User::whereKey($modelo->actualizado_por)->value('name'),
                'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial'),
                'totalSedes' => Sede::where('activo', true)->count(),
                'personas' => $verPersonas ? $modelo->personas()->orderByDesc('activo')->orderBy('nombre_completo')->get() : collect(),
                'vehiculos' => $verVehiculos ? $modelo->vehiculos()->orderByDesc('activo')->orderBy('placas')->get() : collect(),
                'totalPersonas' => $modelo->personas()->count(),
                'totalVehiculos' => $modelo->vehiculos()->count(),
                'puede' => [
                    'verPersonas' => $verPersonas,
                    'verVehiculos' => $verVehiculos,
                    'editar' => $editar !== [] && $this->proveedores->esExclusivoDe($modelo, $editar),
                    'sedes' => $editar !== [],
                    'todasLasSedes' => $editar === null,
                    // Contrato con Padrón de personas y Padrón vehicular: abren su alta con el proveedor elegido
                    'agregarPersona' => $modelo->activo && Route::has('personas.index') && $actor->can('visitantes.crear'),
                    'agregarVehiculo' => $modelo->activo && Route::has('vehiculos.index') && $actor->can('vehiculos.crear'),
                    'enlacePersonas' => Route::has('personas.index'),
                    'enlaceVehiculos' => Route::has('vehiculos.index'),
                ],
                'sedesAsignables' => $editar === null
                    ? Sede::where('activo', true)->orderBy('nombre')->get(['id', 'nombre'])
                    : Sede::where('activo', true)->whereIn('id', $editar)->orderBy('nombre')->get(['id', 'nombre']),
            ]);
        });
    }

    public function update(Request $request, int $proveedor): RedirectResponse
    {
        Gate::authorize('proveedores.editar');
        $empresaId = $this->empresaDeTrabajo($request);

        $modelo = $this->tenant->conEmpresa($empresaId, function () use ($request, $proveedor) {
            $modelo = $this->buscarVisible($request->user(), $proveedor);
            $this->exigirExclusivo($request->user(), $modelo, 'proveedores.editar');

            return $this->proveedores->actualizar($request->user(), $modelo, $request);
        });

        return $this->volver($request, $modelo)->with('ok', "Empresa externa «{$modelo->nombre}» actualizada correctamente.");
    }

    /**
     * "Sedes donde opera". Con alcance de sede solo se marca o desmarca la propia.
     */
    public function sedes(Request $request, int $proveedor): RedirectResponse
    {
        Gate::authorize('proveedores.editar');
        $empresaId = $this->empresaDeTrabajo($request);

        [$modelo, $sigueVisible] = $this->tenant->conEmpresa($empresaId, function () use ($request, $proveedor) {
            $modelo = $this->buscarVisible($request->user(), $proveedor);
            $this->proveedores->actualizarSedes($request->user(), $modelo, $request);

            return [$modelo, $this->proveedores->consulta($request->user())->whereKey($modelo->id)->exists()];
        });

        // Si quitó su propia sede ya no puede ver la ficha: regresa a la lista
        $destino = $sigueVisible ? $this->volver($request, $modelo) : redirect()->route('proveedores.index');

        return $destino->with('ok', "Sedes de «{$modelo->nombre}» actualizadas.");
    }

    /**
     * Desactivar (SEGCAT: "BAJA / VETADA") y reactivar; nunca se borra.
     */
    public function estado(Request $request, int $proveedor): RedirectResponse
    {
        Gate::authorize('proveedores.eliminar');
        $empresaId = $this->empresaDeTrabajo($request);
        $activo = $request->boolean('activo');

        $modelo = $this->tenant->conEmpresa($empresaId, function () use ($request, $proveedor, $activo) {
            $modelo = $this->buscarVisible($request->user(), $proveedor);
            $this->exigirExclusivo($request->user(), $modelo, 'proveedores.eliminar');
            $this->proveedores->cambiarEstado($request->user(), $modelo, $activo);

            return $modelo;
        });

        return $this->volver($request, $modelo)->with($activo ? 'ok' : 'aviso', $activo
            ? "Empresa externa «{$modelo->nombre}» reactivada."
            : "Empresa externa «{$modelo->nombre}» desactivada (baja / vetada). Puedes reactivarla con el mismo botón cuando quieras.");
    }

    /**
     * Búsqueda para autocompletar (Pases de salida, Accesos...): ?q= mínimo 2
     * letras, máximo 15 resultados, solo activos y de las sedes del usuario.
     */
    public function buscar(Request $request): JsonResponse
    {
        Gate::authorize('proveedores.ver');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        if ($empresaId === null) {
            return response()->json(['resultados' => []]);
        }

        $resultados = $this->tenant->conEmpresa($empresaId, fn () => $this->proveedores->buscar(
            Entrada::texto($request->query('q', '')),
            $this->proveedores->sedes($actor, 'proveedores.ver'),
        ));

        return response()->json(['resultados' => $resultados]);
    }

    /**
     * Alta rápida "Nueva Empresa Externa" desde otros módulos (SEGCAT:
     * proveedor_proceso.php con formato_respuesta=json).
     * 201 creado; 200 si ya existía (se agrega la sede de quien la registra);
     * 422 con los errores de captura o si la existente está dada de baja.
     */
    public function rapido(Request $request): JsonResponse
    {
        Gate::authorize('proveedores.crear');
        $empresaId = $this->empresa->id($request->user());
        if ($empresaId === null) {
            return response()->json(['ok' => false, 'mensaje' => 'Elige primero la empresa de trabajo.', 'errores' => []], 422);
        }

        try {
            [$proveedor, $resultado] = $this->tenant->conEmpresa($empresaId, fn () => $this->proveedores->crear($request->user(), $request, rapido: true));
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'mensaje' => collect($e->errors())->flatten()->first(), 'errores' => $e->errors()], 422);
        }

        if (! $proveedor->activo) {
            return response()->json([
                'ok' => false,
                'mensaje' => "«{$proveedor->nombre}» ya está registrada pero dada de baja (vetada). Pide a tu administrador que la reactive.",
                'errores' => ['nombre' => ["«{$proveedor->nombre}» está dada de baja."]],
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'ya_existia' => $resultado !== 'creado',
            'proveedor' => $this->proveedores->resumen($proveedor),
        ], $resultado === 'creado' ? 201 : 200);
    }

    private function empresaDeTrabajo(Request $request): int
    {
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        return $empresaId;
    }

    /**
     * De otra empresa o fuera de las sedes del usuario: 404.
     */
    private function buscarVisible(User $actor, int $id): Proveedor
    {
        $modelo = $this->proveedores->consulta($actor)->find($id);
        abort_if($modelo === null, 404);

        return $modelo;
    }

    /**
     * Los datos generales y el estado de un proveedor compartido con otras
     * sedes solo los cambia quien tiene alcance de empresa.
     */
    private function exigirExclusivo(User $actor, Proveedor $proveedor, string $permiso): void
    {
        abort_unless(
            $this->proveedores->esExclusivoDe($proveedor, $this->proveedores->sedes($actor, $permiso)),
            403,
            'Este proveedor también opera en otras sedes: solo lo modifica quien tiene alcance de empresa.',
        );
    }

    /**
     * Desde la ficha se regresa a la ficha; desde la lista, a la lista.
     */
    private function volver(Request $request, Proveedor $proveedor): RedirectResponse
    {
        return $request->input('_volver') === 'ficha'
            ? redirect()->route('proveedores.show', $proveedor->id)
            : redirect()->route('proveedores.index');
    }
}
