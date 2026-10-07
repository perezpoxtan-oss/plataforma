<?php

namespace App\Http\Controllers\Seguridad;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Proveedor;
use App\Models\User;
use App\Models\Vehiculo;
use App\Services\Padrones\AltasPorVerificar;
use App\Services\Padrones\HayParecidos;
use App\Services\Vehiculos\AdministradorVehiculos;
use App\Support\Entrada;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Padrón Vehicular (réplica de modules/vehiculos de SEGCAT): autos propios,
 * flotillas, taxis y unidades de transporte, con su calcomanía QR y la
 * búsqueda y el registro rápido que usarán Accesos y Estacionamientos.
 */
class VehiculoController extends Controller
{
    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly AdministradorVehiculos $vehiculos,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('vehiculos.ver');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);

        if ($empresaId === null) {
            return view('seguridad.vehiculos.index', ['sinEmpresa' => true]);
        }

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $actor) {
            $lista = Vehiculo::query()
                ->with(['proveedor:id,nombre', 'colaborador:id,num_empleado,nombre,apellido_paterno,apellido_materno'])
                ->leftJoin('users as uc', 'uc.id', '=', 'vehiculos.creado_por')
                ->leftJoin('users as ua', 'ua.id', '=', 'vehiculos.actualizado_por')
                ->select(['vehiculos.*', 'uc.name as creado_por_nombre', 'ua.name as actualizado_por_nombre'])
                ->orderByDesc('vehiculos.id')
                ->get();

            $puedeCrear = $actor->can('vehiculos.crear');
            $puedeEditar = $actor->can('vehiculos.editar');
            $conFormulario = $puedeCrear || $puedeEditar;

            return view('seguridad.vehiculos.index', [
                'sinEmpresa' => false,
                'vehiculos' => $lista,
                'empresaNombre' => Empresa::whereKey($this->tenant->empresaId())->value('nombre_comercial'),
                // Activos para elegir; los inactivos solo para conservar el valor de una edición
                'proveedores' => $conFormulario ? Proveedor::orderBy('nombre')->get(['id', 'nombre', 'categoria', 'activo']) : collect(),
                'colaboradores' => $conFormulario ? Colaborador::where(fn ($q) => $q->where(fn ($a) => $a->where('activo', true)->whereNull('fusionado_en_id'))
                    ->orWhereIn('id', $lista->pluck('colaborador_id')->filter()->unique()->values()))
                    ->orderBy('nombre')->orderBy('apellido_paterno')
                    ->get(['id', 'num_empleado', 'nombre', 'apellido_paterno', 'apellido_materno', 'activo']) : collect(),
                'editables' => $this->vehiculos->idsEnAlcance($actor, 'vehiculos.editar'),
                'desactivables' => $this->vehiculos->idsEnAlcance($actor, 'vehiculos.eliminar'),
                'prefijado' => $puedeCrear ? $this->altaDesdeProveedor($request) : null,
                'volver' => $request->query('volver') === 'proveedor' || $request->boolean('nuevo') ? 'proveedor' : '',
                'puede' => [
                    'crear' => $puedeCrear,
                    'editar' => $puedeEditar,
                    'estado' => $actor->can('vehiculos.eliminar'),
                    'imprimir' => $actor->can('vehiculos.imprimir'),
                ],
            ]);
        });
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('vehiculos.crear');
        $empresaId = $this->empresaDeTrabajo($request);

        $vehiculo = $this->tenant->conEmpresa($empresaId, fn () => $this->vehiculos->crear($request->user(), $empresaId, $request->all()));

        return $this->regresar($request, $vehiculo)->with('ok', "Vehículo {$vehiculo->placas} registrado correctamente. Ya puedes imprimir su calcomanía.");
    }

    public function update(Request $request, int $vehiculo): RedirectResponse
    {
        Gate::authorize('vehiculos.editar');
        $empresaId = $this->empresaDeTrabajo($request);

        $modelo = $this->tenant->conEmpresa($empresaId, function () use ($request, $empresaId, $vehiculo) {
            $modelo = $this->buscarEnAlcance($request->user(), $vehiculo, 'vehiculos.editar');

            return $this->vehiculos->actualizar($request->user(), $empresaId, $modelo, $request->all());
        });

        return $this->regresar($request, $modelo)->with('ok', "Vehículo {$modelo->placas} actualizado correctamente.");
    }

    /**
     * Baja lógica y reactivación con el mismo botón.
     */
    public function estado(Request $request, int $vehiculo): RedirectResponse
    {
        Gate::authorize('vehiculos.eliminar');
        $empresaId = $this->empresaDeTrabajo($request);
        $activo = $request->boolean('activo');

        $modelo = $this->tenant->conEmpresa($empresaId, function () use ($request, $vehiculo, $activo) {
            $modelo = $this->buscarEnAlcance($request->user(), $vehiculo, 'vehiculos.eliminar');
            $this->vehiculos->cambiarEstado($request->user(), $modelo, $activo);

            return $modelo;
        });

        return redirect()->to(route('vehiculos.index').'#vehiculo-'.$modelo->id)->with($activo ? 'ok' : 'aviso', $activo
            ? "Vehículo {$modelo->placas} reactivado correctamente."
            : "Vehículo {$modelo->placas} dado de baja. Puedes reactivarlo con un clic cuando quieras.");
    }

    /**
     * Calcomanía para imprimir (SEGCAT: vehiculo_ticket.php). El QR se dibuja
     * aquí mismo, en SVG: SEGCAT mandaba el código a api.qrserver.com (un
     * tercero) y sin internet la calcomanía salía sin QR.
     */
    public function calcomania(Request $request, int $vehiculo): View
    {
        Gate::authorize('vehiculos.imprimir');
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $vehiculo) {
            // Seguridad (AZ-04): dentro del alcance del permiso ("solo los propios")
            $modelo = $this->vehiculos->limitar(Vehiculo::query(), $request->user(), 'vehiculos.imprimir')->with('proveedor:id,nombre')->find($vehiculo);
            abort_if($modelo === null, 404);

            $url = route('vehiculos.qr', $modelo->codigo_qr);
            $svg = (new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd)))->writeString($url);

            return view('seguridad.vehiculos.calcomania', [
                'vehiculo' => $modelo,
                'empresaNombre' => Empresa::whereKey($modelo->empresa_id)->value('nombre_comercial'),
                // Sin la declaración XML: va incrustado en el HTML
                'qr' => preg_replace('/^<\?xml[^>]*>\s*/', '', $svg),
                'codigoLegible' => trim(chunk_split($modelo->codigo_qr, 4, ' ')),
            ]);
        });
    }

    /**
     * Lo que abre el QR de la calcomanía: la ficha del vehículo en el padrón.
     * El código es aleatorio y solo funciona dentro de la empresa del usuario.
     */
    public function qr(Request $request, string $codigo): RedirectResponse
    {
        Gate::authorize('vehiculos.ver');
        $empresaId = $this->empresaDeTrabajo($request);

        $id = $this->tenant->conEmpresa($empresaId, fn () => Vehiculo::where('codigo_qr', mb_strtolower($codigo))->value('id'));
        abort_if($id === null, 404);

        return redirect()->to(route('vehiculos.index').'#vehiculo-'.$id);
    }

    /**
     * Búsqueda para Accesos y Estacionamientos. ?q= placas, marca, modelo o color.
     */
    public function buscar(Request $request): JsonResponse
    {
        Gate::authorize('vehiculos.ver');
        $empresaId = $this->empresa->id($request->user());
        if ($empresaId === null) {
            return response()->json(['resultados' => []]);
        }

        $resultados = $this->tenant->conEmpresa($empresaId, fn () => $this->vehiculos->buscar(Entrada::texto($request->query('q', ''))));

        return response()->json(['resultados' => $resultados]);
    }

    /**
     * Registro rápido desde otros módulos. 201 con el vehículo; 409 si las
     * placas ya existen (con el vehículo existente, para usarlo) o, sin
     * confirmar_nuevo, si hay placas parecidas ("¿Es alguno de estos?",
     * con la lista en "parecidos"); 422 con los errores de captura.
     *
     * Altas por verificar (ADR-0006): también lo usa quien solo tiene el
     * permiso operativo de la pantalla de origen (origen=accesos|transporte);
     * entonces el vehículo nace pendiente de verificar.
     */
    public function rapido(Request $request): JsonResponse
    {
        $actor = $request->user();
        $altas = app(AltasPorVerificar::class);
        $origen = $request->filled('origen') || ! $actor->can('vehiculos.crear') ? $altas->origenDeAlta($actor, 'vehiculos', $request->input('origen')) : null;
        abort_unless($actor->can('vehiculos.crear') || $origen !== null, 403);
        $empresaId = $this->empresa->id($actor);
        if ($empresaId === null) {
            return response()->json(['ok' => false, 'mensaje' => 'Elige primero la empresa de trabajo.', 'errores' => []], 422);
        }

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $empresaId, $request, $altas, $origen) {
            $existente = $this->vehiculos->conPlacas(Entrada::texto($request->input('placas')));
            if ($existente !== null) {
                try {
                    // Unido con otro: se ofrece el correcto. Rechazado: no se puede usar.
                    $existente = $altas->paraOperacion('vehiculos', $existente, 'placas');
                } catch (ValidationException $e) {
                    return response()->json(['ok' => false, 'mensaje' => collect($e->errors())->flatten()->first(), 'errores' => $e->errors()], 422);
                }

                return response()->json([
                    'ok' => false,
                    'mensaje' => "Las placas {$existente->placas} ya están en el padrón".($existente->activo ? '.' : ' (dado de baja).'),
                    'vehiculo' => $this->vehiculos->resumen($existente),
                ], 409);
            }

            try {
                $vehiculo = DB::transaction(function () use ($actor, $empresaId, $request, $altas, $origen) {
                    $vehiculo = $this->vehiculos->crear($actor, $empresaId, $request->all());
                    // Ya validado: si hay placas parecidas se deshace y se pregunta primero
                    if (! $request->boolean('confirmar_nuevo')) {
                        $parecidos = $altas->parecidos('vehiculos', ['placas' => $vehiculo->placas], null, $vehiculo->id);
                        if ($parecidos !== []) {
                            throw new HayParecidos($parecidos);
                        }
                    }
                    $altas->registrarAlta($actor, 'vehiculos', $vehiculo, $origen);

                    return $vehiculo;
                });
            } catch (ValidationException $e) {
                return response()->json(['ok' => false, 'mensaje' => collect($e->errors())->flatten()->first(), 'errores' => $e->errors()], 422);
            } catch (HayParecidos $e) {
                return response()->json(['ok' => false, 'mensaje' => '¿Es alguno de estos? Hay placas parecidas en el padrón.', 'parecidos' => $e->parecidos], 409);
            }

            return response()->json(['ok' => true, 'vehiculo' => $this->vehiculos->resumen($vehiculo)], 201);
        });
    }

    /**
     * /vehiculos?nuevo=1&proveedor={id} (desde la ficha del proveedor): el
     * alta se abre sola con ese proveedor y la categoría que le corresponde.
     *
     * @return array{proveedor_id: int, propiedad: string}|null
     */
    private function altaDesdeProveedor(Request $request): ?array
    {
        if (! $request->boolean('nuevo')) {
            return null;
        }
        $proveedor = $request->filled('proveedor') ? Proveedor::where('activo', true)->find((int) $request->query('proveedor')) : null;
        if ($proveedor === null) {
            return ['proveedor_id' => 0, 'propiedad' => 'propio_visitante'];
        }

        return [
            'proveedor_id' => $proveedor->id,
            'propiedad' => AdministradorVehiculos::PROPIEDAD_POR_CATEGORIA[$proveedor->categoria] ?? 'empresa_proveedor',
        ];
    }

    /**
     * A dónde volver después de guardar. Nunca se acepta una URL del cliente:
     * solo la bandera volver=proveedor y el proveedor ya validado del vehículo.
     */
    private function regresar(Request $request, Vehiculo $vehiculo): RedirectResponse
    {
        if ($request->input('volver') === 'proveedor' && $vehiculo->proveedor_id !== null && Route::has('proveedores.show')) {
            return redirect()->route('proveedores.show', $vehiculo->proveedor_id);
        }

        return redirect()->to(route('vehiculos.index').'#vehiculo-'.$vehiculo->id);
    }

    private function empresaDeTrabajo(Request $request): int
    {
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        return $empresaId;
    }

    /**
     * Un vehículo de otra empresa o fuera del alcance del permiso responde 404.
     */
    private function buscarEnAlcance(User $actor, int $id, string $permiso): Vehiculo
    {
        $modelo = $this->vehiculos->limitar(Vehiculo::query(), $actor, $permiso)->find($id);
        abort_if($modelo === null, 404);

        return $modelo;
    }
}
