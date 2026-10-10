<?php

namespace App\Http\Controllers\Seguridad;

use App\Http\Controllers\Controller;
use App\Models\Acceso;
use App\Models\AcompananteAcceso;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Persona;
use App\Models\Sede;
use App\Services\Accesos\ConsultaAccesos;
use App\Services\Accesos\MovimientoNoPermitido;
use App\Services\Accesos\MovimientosAccesos;
use App\Services\Accesos\RegistroAccesos;
use App\Support\Csv;
use App\Support\HoraLocal;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bitácora de accesos (réplica de modules/accesos de SEGCAT: "Control de
 * Accesos"): Gente en Sitio, Pendientes de Autorización e Historial
 * Finalizados; "Registro Inteligente de Ingreso"; autorizar, salida, salida
 * de acompañantes, cambiar zona, salida a tour y regreso; exportación.
 *
 * Las reglas viven en App\Services\Accesos; aquí solo se autoriza, se fija la
 * empresa de trabajo y se responde.
 */
class AccesoController extends Controller
{
    public const PESTANAS = ['en_sitio', 'pendientes', 'historial'];

    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly ConsultaAccesos $consulta,
        private readonly RegistroAccesos $registro,
        private readonly MovimientosAccesos $movimientos,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('accesos.ver');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);

        if ($empresaId === null) {
            return view('seguridad.accesos.index', ['sinEmpresa' => true]);
        }

        $filtros = $this->filtros($request);
        $pestana = in_array($request->query('pestana'), self::PESTANAS, true) ? $request->query('pestana') : 'en_sitio';

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $filtros, $pestana, $empresaId) {
            $conteos = $this->consulta->conteos($actor);
            $puedeCrear = $actor->can('accesos.crear');
            $sedesAlta = $puedeCrear ? $this->consulta->sedesParaElegir($actor, 'accesos.crear') : collect();
            $sedesEdicion = $actor->can('accesos.editar') ? $this->consulta->sedesParaElegir($actor, 'accesos.editar') : collect();
            $sedesVer = $this->consulta->sedes($actor, 'accesos.ver');

            return view('seguridad.accesos.index', [
                'sinEmpresa' => false,
                'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial'),
                'pestana' => $pestana,
                'filtros' => $filtros,
                'conteos' => $conteos,
                'lista' => match ($pestana) {
                    'pendientes' => $this->consulta->pendientes($actor, $filtros),
                    'historial' => $this->consulta->historial($actor, $filtros),
                    default => $this->consulta->enSitio($actor, $filtros),
                },
                'sedesFiltro' => Sede::orderBy('nombre')->when($sedesVer !== null, fn ($q) => $q->whereIn('id', $sedesVer))->get(['id', 'nombre']),
                'sedesAlta' => $sedesAlta,
                // Zonas de las sedes donde registra o edita, con ocupación en vivo
                'zonas' => $this->consulta->zonas($sedesAlta->pluck('id')->merge($sedesEdicion->pluck('id'))->unique()->values()->all()),
                'tiposGafete' => $puedeCrear ? $this->consulta->tiposGafete() : collect(),
                'departamentos' => $puedeCrear ? Departamento::with('sedes:sedes.id')->where('activo', true)->orderBy('nombre')->get(['id', 'nombre', 'todas_las_sedes']) : collect(),
                'previos' => $puedeCrear ? $this->consulta->previos(session()->getOldInput()) : [],
                'siguiente' => session('siguiente'),
                'puede' => [
                    'crear' => $puedeCrear && $sedesAlta->isNotEmpty(),
                    'editar' => $actor->can('accesos.editar'),
                    'aprobar' => $actor->can('accesos.aprobar'),
                    'exportar' => $actor->can('accesos.exportar'),
                    'provisional' => $actor->can('colaboradores.crear') || $actor->can('colaboradores.provisional'),
                    'persona' => $actor->can('visitantes.crear') || $actor->can('accesos.crear'), // Altas por verificar (ADR-0006)
                ],
            ]);
        });
    }

    /**
     * Registro Inteligente de Ingreso. "Guardar y capturar siguiente" reabre el
     * formulario con la misma sede y el mismo tipo de persona.
     */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('accesos.crear');
        $empresaId = $this->empresaDeTrabajo($request);

        $acceso = $this->tenant->conEmpresa($empresaId, fn () => $this->registro->registrar($request->user(), $request->except(['_token', '_dialogo', 'siguiente'])));

        $mensaje = $acceso->estado === 'pendiente'
            ? "Registro de {$acceso->nombre} guardado: queda PENDIENTE hasta que el host confirme la autorización."
            : "Ingreso de {$acceso->nombre} registrado correctamente.";
        // Recepción (ADR-0007): visita que espera al responsable del departamento
        if (($acceso->getAttributes()['autorizacion'] ?? null) === 'esperando') {
            $mensaje = $acceso->motivo_visita === 'rh'
                ? "Registro de {$acceso->nombre} guardado: ESPERANDO A RR. HH. Ya se avisó a Recursos Humanos; aquí verás cuándo puede pasar."
                : "Registro de {$acceso->nombre} guardado: ESPERANDO AUTORIZACIÓN del departamento. Ya se avisó al responsable; aquí verás su respuesta.";
        }
        $redireccion = redirect()->route('accesos.index', $acceso->estado === 'pendiente' ? ['pestana' => 'pendientes'] : [])->with('ok', $mensaje);

        return $request->boolean('siguiente')
            ? $redireccion->with('siguiente', ['sede_id' => $acceso->sede_id, 'tipo' => $acceso->tipo, 'mensaje' => $mensaje])
            : $redireccion;
    }

    public function autorizar(Request $request, int $acceso): RedirectResponse
    {
        Gate::authorize('accesos.aprobar');

        return $this->mover($request, $acceso, 'accesos.aprobar', function (Acceso $a) use ($request) {
            $this->movimientos->autorizar($request->user(), $a);

            return "Acceso autorizado — {$a->nombre} ya puede ingresar.";
        }, 'pendientes');
    }

    public function salida(Request $request, int $acceso): RedirectResponse
    {
        Gate::authorize('accesos.editar');

        return $this->mover($request, $acceso, 'accesos.editar', function (Acceso $a) use ($request) {
            $this->movimientos->salida($request->user(), $a);

            return "Salida de {$a->nombre} registrada correctamente.";
        });
    }

    public function zona(Request $request, int $acceso): RedirectResponse
    {
        Gate::authorize('accesos.editar');

        return $this->mover($request, $acceso, 'accesos.editar', function (Acceso $a) use ($request) {
            $this->movimientos->cambiarZona($request->user(), $a, $request->input('zona_estacionamiento_id'));

            return 'Zona de estacionamiento actualizada.';
        });
    }

    public function salidaTemporal(Request $request, int $acceso): RedirectResponse
    {
        Gate::authorize('accesos.editar');

        return $this->mover($request, $acceso, 'accesos.editar', function (Acceso $a) use ($request) {
            $this->movimientos->salidaTemporal($request->user(), $a, $request->all());

            return $a->tipo === 'huesped' ? "Salida a tour de {$a->nombre} registrada." : "Salida temporal de {$a->nombre} registrada.";
        });
    }

    public function regreso(Request $request, int $acceso): RedirectResponse
    {
        Gate::authorize('accesos.editar');

        return $this->mover($request, $acceso, 'accesos.editar', function (Acceso $a) use ($request) {
            $this->movimientos->regreso($request->user(), $a, $request->all());

            return $a->tipo === 'huesped' ? "Regreso de tour de {$a->nombre} registrado." : "Regreso de {$a->nombre} registrado.";
        });
    }

    public function acompananteSalida(Request $request, int $acompanante): RedirectResponse
    {
        Gate::authorize('accesos.editar');

        return $this->moverAcompanante($request, $acompanante, function (AcompananteAcceso $ac) use ($request) {
            $this->movimientos->salidaAcompanante($request->user(), $ac);

            return "Salida de {$ac->nombreVisible()} registrada — su gafete ya quedó libre.";
        });
    }

    public function acompananteSalidaTemporal(Request $request, int $acompanante): RedirectResponse
    {
        Gate::authorize('accesos.editar');

        return $this->moverAcompanante($request, $acompanante, function (AcompananteAcceso $ac) use ($request) {
            $this->movimientos->salidaTemporalAcompanante($request->user(), $ac);

            return "Salida temporal de {$ac->nombreVisible()} registrada — su gafete sigue reservado.";
        });
    }

    public function acompananteRegreso(Request $request, int $acompanante): RedirectResponse
    {
        Gate::authorize('accesos.editar');

        return $this->moverAcompanante($request, $acompanante, function (AcompananteAcceso $ac) use ($request) {
            $this->movimientos->regresoAcompanante($request->user(), $ac);

            return "Regreso de {$ac->nombreVisible()} registrado.";
        });
    }

    /**
     * Buscar a alguien en sitio para darle salida (por nombre, placas, gafete o
     * habitación; o el gafete leído con el lector universal).
     */
    public function enSitio(Request $request): JsonResponse
    {
        Gate::authorize('accesos.ver');
        $datos = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'gafete' => ['nullable', 'integer']]);
        $empresaId = $this->empresa->id($request->user());
        if ($empresaId === null) {
            return response()->json(['resultados' => []]);
        }

        return response()->json(['resultados' => $this->tenant->conEmpresa($empresaId, fn () => $this->consulta->buscarEnSitio(
            $request->user(), (string) ($datos['q'] ?? ''), isset($datos['gafete']) ? (int) $datos['gafete'] : null,
        ))]);
    }

    /**
     * Autocompletar del formulario: colaboradores de la sede, personas del
     * padrón, vehículos por placas y empresas externas. Lo usa quien registra
     * ingresos (accesos.crear) aunque no administre esos padrones: la caseta
     * consulta, el padrón lo cuida su módulo.
     */
    public function buscar(Request $request): JsonResponse
    {
        Gate::authorize('accesos.crear');
        $datos = $request->validate([
            'que' => ['required', Rule::in(['colaborador', 'persona', 'vehiculo', 'proveedor'])],
            'q' => ['nullable', 'string', 'max:100'],
            'sede' => ['nullable', 'integer'],
            'tipo' => ['nullable', Rule::in(array_keys(Persona::TIPOS))],
        ]);
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        if ($empresaId === null) {
            return response()->json(['resultados' => []]);
        }
        $resultados = $this->tenant->conEmpresa($empresaId, fn () => $this->consulta->sugerencias(
            $actor, $datos['que'], (string) ($datos['q'] ?? ''), isset($datos['sede']) ? (int) $datos['sede'] : null, $datos['tipo'] ?? null,
        ));

        return response()->json(['resultados' => $resultados]);
    }

    /**
     * Gafetes que se pueden prestar ahora en una sede (para elegir sin escanear).
     */
    public function gafetes(Request $request): JsonResponse
    {
        Gate::authorize('accesos.crear');
        $sede = (int) $request->validate(['sede' => ['required', 'integer']])['sede'];
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $sede) {
            $permitidas = $this->consulta->sedes($request->user(), 'accesos.crear');
            abort_if($permitidas !== null && ! in_array($sede, $permitidas, true), 404);

            return response()->json(['resultados' => $this->consulta->gafetesDisponibles($sede)]);
        });
    }

    /**
     * CSV del historial con los filtros de la pantalla (BOM para que Excel respete los acentos).
     */
    public function exportar(Request $request, HoraLocal $hora): StreamedResponse
    {
        Gate::authorize('accesos.exportar');
        $empresaId = $this->empresaDeTrabajo($request);
        $filtros = $this->filtros($request);

        $filas = $this->tenant->conEmpresa($empresaId, fn () => $this->consulta->consultaHistorial($request->user(), $filtros, 'accesos.exportar')
            ->with(['sede:id,nombre', 'zona:id,nombre', 'departamento:id,nombre', 'host:id,nombre,apellido_paterno,apellido_materno',
                'registradoPor:id,name', 'autorizadoPor:id,name', 'salidaPor:id,name', 'vehiculo:id,marca,modelo,color'])
            ->limit(10000)->get());

        return response()->streamDownload(function () use ($filas, $hora) {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\xEF\xBB\xBF");
            Csv::fila($salida, ['Folio', 'Sede', 'Tipo', 'Movimiento', 'Nombre', 'Empresa / Procedencia', 'Gafete', 'Placas', 'Vehículo', 'Zona',
                'Conductor', 'Visita a / Host', 'Departamento', 'Habitación', 'Pase', 'ID custodiada', 'Acompañantes',
                'Entrada', 'Autorizado', 'Salida', 'Registró', 'Autorizó', 'Dio salida']);
            foreach ($filas as $a) {
                Csv::fila($salida, [
                    $a->id, $a->sede?->nombre, $a->etiquetaTipo(), Acceso::MOVIMIENTOS[$a->movimiento] ?? $a->movimiento, $a->nombre,
                    $a->empresa_procedencia, $a->gafete_texto, $a->placas,
                    $a->vehiculo ? trim($a->vehiculo->marca.' '.$a->vehiculo->modelo.' '.$a->vehiculo->color) : null,
                    $a->zona?->nombre, $a->conductor, $a->persona_visita ?? $a->host?->nombreCompleto(), $a->departamento?->nombre,
                    $a->habitacion, Acceso::PASES[$a->tipo_pase] ?? null, Acceso::IDENTIFICACIONES[$a->identificacion] ?? null, $a->num_acompanantes,
                    $hora->formatear($a->entrada_at), $hora->formatear($a->autorizado_at), $hora->formatear($a->salida_at),
                    $a->registradoPor?->name, $a->autorizadoPor?->name, $a->salidaPor?->name,
                ]);
            }
            fclose($salida);
        }, 'bitacora-accesos-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ------------------------------------------------------------------ Ayudas

    /**
     * @return array<string, mixed>
     */
    private function filtros(Request $request): array
    {
        return $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'tipo' => ['nullable', Rule::in(array_keys(Acceso::TIPOS))],
            'sede' => ['nullable', 'integer'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d'],
        ], [
            'desde.date_format' => 'Revisa la fecha «Desde».',
            'hasta.date_format' => 'Revisa la fecha «Hasta».',
        ]);
    }

    private function empresaDeTrabajo(Request $request): int
    {
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        return $empresaId;
    }

    /**
     * Busca el acceso dentro del alcance (otra empresa o sede → 404), aplica el
     * cambio y regresa con el aviso. Un cambio que ya no aplica regresa con su motivo.
     */
    private function mover(Request $request, int $id, string $permiso, \Closure $cambio, ?string $pestana = null): RedirectResponse
    {
        $empresaId = $this->empresaDeTrabajo($request);

        try {
            $mensaje = $this->tenant->conEmpresa($empresaId, function () use ($request, $id, $permiso, $cambio) {
                $acceso = $this->consulta->limitar(Acceso::query(), $request->user(), $permiso)->find($id);
                abort_if($acceso === null, 404);

                return $cambio($acceso);
            });
        } catch (MovimientoNoPermitido $e) {
            return redirect()->route('accesos.index', $pestana ? ['pestana' => $pestana] : [])->with('error', $e->getMessage());
        }

        return redirect()->route('accesos.index', $pestana ? ['pestana' => $pestana] : [])->with('ok', $mensaje);
    }

    private function moverAcompanante(Request $request, int $id, \Closure $cambio): RedirectResponse
    {
        $empresaId = $this->empresaDeTrabajo($request);

        try {
            $mensaje = $this->tenant->conEmpresa($empresaId, function () use ($request, $id, $cambio) {
                $acompanante = AcompananteAcceso::with('acceso')->find($id);
                $visible = $acompanante && $this->consulta->limitar(Acceso::query(), $request->user(), 'accesos.editar')->whereKey($acompanante->acceso_id)->exists();
                abort_unless($visible, 404);

                return $cambio($acompanante);
            });
        } catch (MovimientoNoPermitido $e) {
            return redirect()->route('accesos.index')->with('error', $e->getMessage());
        }

        return redirect()->route('accesos.index')->with('ok', $mensaje);
    }
}
