<?php

namespace App\Http\Controllers\Seguridad;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\MovimientoTransporte;
use App\Models\Paradero;
use App\Models\Persona;
use App\Models\Proveedor;
use App\Models\Ruta;
use App\Models\Sede;
use App\Models\User;
use App\Models\Vehiculo;
use App\Services\Avisos\AvisosCorreo;
use App\Services\Firmas\Firmas;
use App\Services\Transporte\BitacoraTransporte;
use App\Support\Csv;
use App\Support\Entrada;
use App\Support\HoraLocal;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bitácora de transporte (réplica de modules/transporte/bitacora_* de SEGCAT):
 * lista por fechas con detalle, alta "Registrar Bitácora Logística" (servicio
 * normal o taxis de emergencia), edición, anular / reactivar, Vo.Bo. del vale,
 * vale de caja chica para imprimir, firmas (solo con permiso), reportes y
 * exportación a CSV.
 *
 * Las reglas viven en App\Services\Transporte\BitacoraTransporte.
 */
class TransporteController extends Controller
{
    public const POR_PAGINA = 50;

    public const POR_PAGINA_REPORTE = 25;

    /** Máximo de unidades y choferes conocidos que se sugieren en el alta. */
    private const SUGERENCIAS = 400;

    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly BitacoraTransporte $bitacora,
        private readonly HoraLocal $hora,
    ) {}

    // ------------------------------------------------------------------ Lista

    public function index(Request $request): View
    {
        Gate::authorize('transporte.ver');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);

        if ($empresaId === null) {
            return view('seguridad.transporte.index', ['sinEmpresa' => true]);
        }

        $hoy = CarbonImmutable::now($this->hora->zona())->toDateString();
        $filtros = $this->filtros($request, $hoy, $hoy);

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $empresaId, $filtros) {
            $consulta = $this->filtrar($this->bitacora->limitar(MovimientoTransporte::query(), $actor, 'transporte.ver'), $filtros);
            $movimientos = (clone $consulta)->with($this->relaciones())
                ->orderByDesc('movimientos_transporte.created_at')->orderByDesc('movimientos_transporte.id')
                ->paginate(self::POR_PAGINA)->withQueryString();

            $sedesVer = $this->sedesActivas($this->bitacora->sedes($actor, 'transporte.ver'), true);
            $puede = $this->permisos($actor);
            $alta = $puede['crear'] ? $this->datosAlta($actor) : null;
            $editando = $this->editandoTrasError($actor);

            return view('seguridad.transporte.index', [
                'sinEmpresa' => false,
                'movimientos' => $movimientos,
                'resumen' => $this->bitacora->resumen($consulta),
                'filtros' => $filtros,
                'sedes' => $sedesVer,
                'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial'),
                'puede' => $puede,
                'alta' => $alta,
                'editando' => $editando,
                // Para "Editar" (destino del taxi): paraderos de las sedes que se ven
                'paraderosPorSede' => $puede['editar'] || $alta ? $this->paraderos($sedesVer->pluck('id')->all()) : collect(),
                'pasajerosAnteriores' => $this->pasajerosAnteriores(),
                'editables' => $movimientos->getCollection()->filter(fn ($m) => $puede['editar'] && $this->bitacora->puede($actor, 'transporte.editar', $m))->pluck('id')->all(),
                'anulables' => $movimientos->getCollection()->filter(fn ($m) => $puede['eliminar'] && $this->bitacora->puede($actor, 'transporte.eliminar', $m))->pluck('id')->all(),
                'autorizables' => $movimientos->getCollection()->filter(fn ($m) => $puede['aprobar'] && $this->bitacora->puede($actor, 'transporte.aprobar', $m))->pluck('id')->all(),
            ]);
        });
    }

    public function show(Request $request, int $movimiento): View
    {
        Gate::authorize('transporte.ver');
        $actor = $request->user();
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $movimiento, $empresaId) {
            $m = $this->visible($actor, $movimiento);
            $m->load($this->relaciones());
            $puede = $this->permisos($actor);
            $hermanos = $m->lote ? MovimientoTransporte::where('lote', $m->lote)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all() : [];

            return view('seguridad.transporte.show', [
                'm' => $m,
                'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial'),
                'puede' => $puede,
                'editable' => $puede['editar'] && $this->bitacora->puede($actor, 'transporte.editar', $m),
                'anulable' => $puede['eliminar'] && $this->bitacora->puede($actor, 'transporte.eliminar', $m),
                'autorizable' => $puede['aprobar'] && $this->bitacora->puede($actor, 'transporte.aprobar', $m),
                'hermanos' => $hermanos,
                'paraderosPorSede' => $this->paraderos([$m->sede_id]),
                'editando' => $this->editandoTrasError($actor),
                'pasajerosAnteriores' => $this->pasajerosAnteriores(),
            ]);
        });
    }

    // ------------------------------------------------------------- Escritura

    public function store(Request $request, AvisosCorreo $avisos): RedirectResponse
    {
        Gate::authorize('transporte.crear');
        $actor = $request->user();
        $empresaId = $this->empresaDeTrabajo($request);

        $movimientos = $this->tenant->conEmpresa($empresaId, function () use ($request, $actor, $avisos) {
            $movimientos = $this->bitacora->registrar($actor, $request->all(), $actor->can('transporte.firmar'), $this->bitacora->sedes($actor, 'transporte.crear'));
            foreach ($movimientos->filter->esTaxi() as $vale) {
                $avisos->valeTaxi($vale, $actor);
            }

            return $movimientos;
        });

        $primero = $movimientos->first();
        $mensaje = $primero->esTaxi()
            ? ($movimientos->count() === 1 ? 'Vale de taxi '.$primero->folio().' registrado.' : $movimientos->count().' vales de taxi registrados ('.$movimientos->map->folio()->implode(', ').').')
                .' Imprime cada vale para las firmas.'
            : 'Movimiento registrado correctamente.';

        $respuesta = redirect()->to(route('transporte.index').'#movimiento-'.$primero->id)->with('ok', $mensaje);
        if ($primero->esTaxi()) {
            $respuesta->with('vales', $movimientos->map(fn ($m) => ['id' => $m->id, 'folio' => $m->folio()])->all());
        }
        // "Registrar y capturar siguiente": se vuelve a abrir el alta con la misma sede y tipo
        if ($request->boolean('_siguiente')) {
            $respuesta->with('capturar_siguiente', ['sede_id' => $primero->sede_id, 'tipo_movimiento' => $primero->tipo_movimiento]);
        }

        return $respuesta;
    }

    public function update(Request $request, int $movimiento): RedirectResponse
    {
        Gate::authorize('transporte.editar');
        $empresaId = $this->empresaDeTrabajo($request);

        $m = $this->tenant->conEmpresa($empresaId, function () use ($request, $movimiento) {
            $m = $this->visible($request->user(), $movimiento);
            abort_unless($this->bitacora->puede($request->user(), 'transporte.editar', $m), 403);

            return $this->bitacora->actualizar($request->user(), $m, $request->all());
        });

        return $this->volver($m)->with('ok', 'Registro '.$m->folio().' actualizado correctamente.');
    }

    public function anular(Request $request, int $movimiento): RedirectResponse
    {
        return $this->cambiarEstado($request, $movimiento, 'transporte.eliminar', fn (User $a, MovimientoTransporte $m) => $this->bitacora->anular($a, $m),
            'aviso', fn ($m) => 'Registro '.$m->folio().' anulado. Queda en el historial y puedes reactivarlo.');
    }

    public function reactivar(Request $request, int $movimiento): RedirectResponse
    {
        return $this->cambiarEstado($request, $movimiento, 'transporte.eliminar', fn (User $a, MovimientoTransporte $m) => $this->bitacora->reactivar($a, $m),
            'ok', fn ($m) => 'Registro '.$m->folio().' reactivado — vuelve a contar como válido.');
    }

    public function autorizar(Request $request, int $movimiento): RedirectResponse
    {
        return $this->cambiarEstado($request, $movimiento, 'transporte.aprobar', fn (User $a, MovimientoTransporte $m) => $this->bitacora->autorizar($a, $m),
            'ok', fn ($m) => 'Vale '.$m->folio().' autorizado (Vo.Bo.). Ya no se puede editar.');
    }

    // --------------------------------------------------------------- Impresos

    /**
     * "VALE DE CAJA CHICA - TAXI DE OPERACIÓN" (SEGCAT: ticket_taxi.php): tres
     * copias en una hoja (Contabilidad, Caseta y Operador de taxi).
     */
    public function vale(Request $request, int $movimiento): View
    {
        Gate::authorize('transporte.ver');
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $movimiento, $empresaId) {
            $m = $this->visible($request->user(), $movimiento);
            abort_unless($m->esTaxi(), 404);
            $m->load($this->relaciones());

            return view('seguridad.transporte.vale', [
                'm' => $m,
                'empresa' => Empresa::find($empresaId),
            ]);
        });
    }

    /**
     * Imagen de una firma: solo con permiso de ver el movimiento (empresa,
     * sede y, con alcance "propios", autor). Nunca desde la carpeta pública.
     */
    public function firma(Request $request, int $movimiento, string $cual, Firmas $firmas): StreamedResponse
    {
        Gate::authorize('transporte.ver');
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $movimiento, $cual, $firmas) {
            $m = $this->visible($request->user(), $movimiento);

            return $firmas->respuesta($cual === 'taxista' ? $m->firma_taxista : $m->firma_guardia);
        });
    }

    // --------------------------------------------------------------- Reportes

    /**
     * "Reportes de Transporte" (SEGCAT: reportes_transporte.php): filtros por
     * sede, fechas, estatus y proveedor (fletera), resumen y 25 por página.
     */
    public function reportes(Request $request): View
    {
        Gate::authorize('transporte.ver');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);

        if ($empresaId === null) {
            return view('seguridad.transporte.reportes', ['sinEmpresa' => true]);
        }

        $ahora = CarbonImmutable::now($this->hora->zona());
        $filtros = $this->filtros($request, $ahora->startOfMonth()->toDateString(), $ahora->toDateString());

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $empresaId, $filtros) {
            $consulta = $this->filtrar($this->bitacora->limitar(MovimientoTransporte::query(), $actor, 'transporte.ver'), $filtros);

            return view('seguridad.transporte.reportes', [
                'sinEmpresa' => false,
                'registros' => (clone $consulta)->with($this->relaciones(false))
                    ->orderByDesc('movimientos_transporte.created_at')->orderByDesc('movimientos_transporte.id')
                    ->paginate(self::POR_PAGINA_REPORTE)->withQueryString(),
                'resumen' => $this->bitacora->resumen($consulta),
                'filtros' => $filtros,
                'sedes' => $this->sedesActivas($this->bitacora->sedes($actor, 'transporte.ver'), true),
                'proveedores' => Proveedor::whereIn('id', Ruta::query()->select('proveedor_id'))->orderBy('nombre')->get(['id', 'nombre']),
                'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial'),
                'puedeExportar' => $actor->can('transporte.exportar'),
            ]);
        });
    }

    /**
     * CSV UTF-8 con BOM (SEGCAT: un .xls que en realidad era HTML) con los
     * mismos filtros de la pantalla.
     */
    public function exportar(Request $request): StreamedResponse
    {
        Gate::authorize('transporte.exportar');
        $actor = $request->user();
        $empresaId = $this->empresaDeTrabajo($request);
        $ahora = CarbonImmutable::now($this->hora->zona());
        $filtros = $this->filtros($request, $ahora->toDateString(), $ahora->toDateString());

        $filas = $this->tenant->conEmpresa($empresaId, fn () => $this->filtrar($this->bitacora->limitar(MovimientoTransporte::query(), $actor, 'transporte.exportar'), $filtros)
            ->with($this->relaciones())
            ->orderBy('movimientos_transporte.created_at')->orderBy('movimientos_transporte.id')
            ->limit(20000)->get());

        $hora = $this->hora;

        return response()->streamDownload(function () use ($filas, $hora) {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\xEF\xBB\xBF"); // para que Excel respete los acentos
            Csv::fila($salida, ['Folio', 'Fecha y hora', 'Sede', 'Movimiento', 'Ruta', 'Horario', 'Transportista', 'Estatus', 'Vehículo', 'Placas', 'Núm. económico',
                'Conductor', 'PAX', 'Monto taxi ($)', 'Destino taxi', 'Justificación costo', 'Colaboradores', 'Observaciones', 'Registró (guardia)', 'Vo.Bo.', 'Anulado']);
            foreach ($filas as $m) {
                Csv::fila($salida, [
                    $m->folio(), $hora->formatear($m->created_at), $m->sede?->nombre, $m->etiquetaTipo(), $m->ruta?->nombre,
                    $m->horario ? $m->horario->inicio().'-'.$m->horario->fin() : '', $m->ruta?->proveedor?->nombre, $m->etiquetaEstatus(),
                    $m->etiquetaUnidad(), $m->vehiculo?->placas, $m->vehiculo?->numero_economico, $m->chofer?->nombre_completo, $m->cantidad_pax,
                    $m->monto !== null ? number_format((float) $m->monto, 2, '.', '') : '', $m->paradero?->nombre, $m->justificacion,
                    $m->pasajeros->map(fn ($c) => $c->nombreCompleto().' ['.$c->num_empleado.']')->implode(', '),
                    $m->observaciones, $m->registro?->name,
                    $m->autorizado() ? ($m->autorizador?->name.' '.$hora->formatear($m->autorizado_en)) : '',
                    $m->anulado ? 'SÍ' : '',
                ]);
            }
            fclose($salida);
        }, 'bitacora-transporte-'.$filtros['fecha_inicio'].'-al-'.$filtros['fecha_fin'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ------------------------------------------------------------------ Apoyo

    /**
     * Relaciones de la lista (sin N+1).
     *
     * @return array<int|string, mixed>
     */
    private function relaciones(bool $completo = true): array
    {
        $base = [
            'sede:id,nombre', 'ruta:id,nombre,proveedor_id,costo_maximo_taxi,sentido', 'ruta.proveedor:id,nombre',
            'horario:id,ruta_id,nombre,hora_inicio,hora_fin,dias',
            'vehiculo:id,placas,tipo,descripcion_otro,marca,modelo,numero_economico,capacidad',
            'chofer:id,nombre_completo,telefono', 'paradero:id,nombre', 'registro:id,name',
        ];

        return $completo
            ? [...$base, 'pasajeros:id,num_empleado,nombre,apellido_paterno,apellido_materno', 'editor:id,name', 'anulador:id,name', 'autorizador:id,name']
            : $base;
    }

    /**
     * @return array<string, bool>
     */
    private function permisos(User $actor): array
    {
        return [
            'crear' => $actor->can('transporte.crear') && $this->bitacora->sedes($actor, 'transporte.crear') !== [],
            'editar' => $actor->can('transporte.editar'),
            'eliminar' => $actor->can('transporte.eliminar'),
            'aprobar' => $actor->can('transporte.aprobar'),
            'firmar' => $actor->can('transporte.firmar'),
            'exportar' => $actor->can('transporte.exportar'),
        ];
    }

    /**
     * Todo lo que necesita el diálogo "Registrar Bitácora Logística".
     *
     * @return array<string, mixed>
     */
    private function datosAlta(User $actor): array
    {
        $sedes = $this->sedesActivas($this->bitacora->sedes($actor, 'transporte.crear'));
        $horarios = $this->bitacora->horariosPara($sedes->pluck('id')->all());
        $sugerencias = $sedes->mapWithKeys(fn (Sede $s) => [$s->id => $this->bitacora->sugerencia(
            $horarios->filter(fn ($h) => $h->ruta->sede_id === $s->id), $this->bitacora->ahoraEn($s),
        )]);

        $unidad = fn (Vehiculo $v) => [$v->placas => array_filter([
            'tipo' => $v->tipo === 'autobus' ? 'autobus' : ($v->tipo === 'sedan' || $v->tipo === 'suv' ? $v->tipo : ($v->descripcion_otro === 'VAN / URVAN' ? 'van' : 'otro')),
            'marca' => $v->marca, 'modelo' => $v->modelo, 'economico' => $v->numero_economico, 'capacidad' => $v->capacidad,
        ], fn ($x) => $x !== null && $x !== '')];
        $vehiculos = fn (array $propiedades) => Vehiculo::where('activo', true)->whereIn('propiedad', $propiedades)
            ->orderByDesc('updated_at')->limit(self::SUGERENCIAS)
            ->get(['id', 'placas', 'tipo', 'descripcion_otro', 'marca', 'modelo', 'numero_economico', 'capacidad'])
            ->mapWithKeys($unidad)->sortKeys();

        $verTelefono = $actor->can('visitantes.ver');
        $choferes = Persona::where('activo', true)->where('tipo', 'proveedor')->orderByDesc('updated_at')->limit(self::SUGERENCIAS)
            ->get(['id', 'nombre_completo', 'telefono'])
            ->mapWithKeys(fn (Persona $p) => [mb_strtoupper($p->nombre_completo) => $verTelefono ? (string) $p->telefono : ''])->sortKeys();

        return [
            'sedes' => $sedes,
            'horarios' => $horarios,
            'sugerencias' => $sugerencias,
            // Sugerencias solo para quien puede consultar esos padrones
            'unidades' => $actor->can('vehiculos.ver') ? $vehiculos(['transporte_personal', 'empresa_proveedor', 'agencia_renta']) : collect(),
            'taxis' => $actor->can('vehiculos.ver') ? $vehiculos(['taxi_app']) : collect(),
            'choferes' => $verTelefono ? $choferes : collect(),
            'paraderos' => $this->paraderos($sedes->pluck('id')->all()),
        ];
    }

    /**
     * @param  list<int>  $sedes
     * @return Collection<int, Collection<int, string>> sede_id => nombres de paraderos activos
     */
    private function paraderos(array $sedes): Collection
    {
        return Paradero::whereIn('sede_id', $sedes)->where('activo', true)->orderBy('nombre')->get(['id', 'sede_id', 'nombre'])
            ->groupBy('sede_id')->map(fn ($g) => $g->pluck('nombre')->values());
    }

    /**
     * Colaboradores elegidos que regresaron tras un error, para volver a pintar sus fichas.
     *
     * @return array<int, string> id => "Nombre · Núm. 1234"
     */
    private function pasajerosAnteriores(): array
    {
        $ids = collect(old('taxis', []))->filter(fn ($t) => is_array($t))->flatMap(fn ($t) => (array) ($t['pasajeros'] ?? []))
            ->merge((array) old('pasajeros', []))->filter(fn ($v) => is_numeric($v))->map(fn ($v) => (int) $v)->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        return Colaborador::whereIn('id', $ids)->get(['id', 'num_empleado', 'nombre', 'apellido_paterno', 'apellido_materno'])
            ->mapWithKeys(fn (Colaborador $c) => [$c->id => $c->nombreCompleto().' · Núm. '.$c->num_empleado])->all();
    }

    /** El movimiento cuya edición regresó con errores (para reabrir su diálogo). */
    private function editandoTrasError(User $actor): ?MovimientoTransporte
    {
        $dialogo = old('_dialogo');
        if (! is_string($dialogo) || ! preg_match('/^editar-(\d+)$/', $dialogo, $m)) {
            return null;
        }
        $movimiento = $this->bitacora->limitar(MovimientoTransporte::query(), $actor, 'transporte.ver')->with($this->relaciones())->find((int) $m[1]);

        return $movimiento !== null && $this->bitacora->puede($actor, 'transporte.editar', $movimiento) ? $movimiento : null;
    }

    /**
     * Sedes activas (o, para filtrar, también las inactivas que tengan registros) dentro del alcance.
     *
     * @param  list<int>|null  $permitidas
     * @return Collection<int, Sede>
     */
    private function sedesActivas(?array $permitidas, bool $paraFiltrar = false): Collection
    {
        return Sede::query()
            ->when($permitidas !== null, fn ($q) => $q->whereIn('id', $permitidas))
            ->when(! $paraFiltrar, fn ($q) => $q->where('activo', true))
            ->orderBy('nombre')->get(['id', 'empresa_id', 'nombre', 'zona_horaria', 'activo']);
    }

    /**
     * Filtros de la dirección (para compartir la búsqueda y exportar lo mismo).
     *
     * @return array{fecha_inicio: string, fecha_fin: string, sede: ?int, estatus: ?string, tipo: ?string, proveedor: ?int, estado: ?string, q: string}
     */
    private function filtros(Request $request, string $inicio, string $fin): array
    {
        $fecha = function (mixed $valor, string $porDefecto): string {
            if (! is_string($valor) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
                return $porDefecto;
            }
            $f = CarbonImmutable::createFromFormat('!Y-m-d', $valor);

            return $f !== false && $f->format('Y-m-d') === $valor ? $valor : $porDefecto;
        };
        $desde = $fecha($request->query('fecha_inicio'), $inicio);
        $hasta = $fecha($request->query('fecha_fin'), $fin);
        if ($hasta < $desde) {
            [$desde, $hasta] = [$hasta, $desde];
        }

        return [
            'fecha_inicio' => $desde,
            'fecha_fin' => $hasta,
            'sede' => is_numeric($request->query('sede')) ? (int) $request->query('sede') : null,
            'estatus' => array_key_exists(Entrada::texto($request->query('estatus')), MovimientoTransporte::ESTATUS) ? Entrada::texto($request->query('estatus')) : null,
            'tipo' => array_key_exists(Entrada::texto($request->query('tipo')), MovimientoTransporte::TIPOS) ? Entrada::texto($request->query('tipo')) : null,
            'proveedor' => is_numeric($request->query('proveedor')) ? (int) $request->query('proveedor') : null,
            'estado' => in_array($request->query('estado'), ['vigentes', 'anulados', 'por_autorizar'], true) ? Entrada::texto($request->query('estado')) : null,
            'q' => mb_substr(trim(Entrada::texto($request->query('q', ''))), 0, 100),
        ];
    }

    /**
     * @param  Builder<MovimientoTransporte>  $consulta
     * @param  array<string, mixed>  $f
     * @return Builder<MovimientoTransporte>
     */
    private function filtrar(Builder $consulta, array $f): Builder
    {
        return $consulta
            // "fecha" es el día en la hora local de la sede
            ->whereBetween('movimientos_transporte.fecha', [$f['fecha_inicio'], $f['fecha_fin']])
            ->when($f['sede'] !== null, fn ($q) => $q->where('movimientos_transporte.sede_id', $f['sede']))
            ->when($f['estatus'] !== null, fn ($q) => $q->where('movimientos_transporte.estatus', $f['estatus']))
            ->when($f['tipo'] !== null, fn ($q) => $q->where('movimientos_transporte.tipo_movimiento', $f['tipo']))
            ->when($f['proveedor'] !== null, fn ($q) => $q->whereHas('ruta', fn ($r) => $r->where('proveedor_id', $f['proveedor'])))
            ->when($f['estado'] === 'vigentes', fn ($q) => $q->where('movimientos_transporte.anulado', false))
            ->when($f['estado'] === 'anulados', fn ($q) => $q->where('movimientos_transporte.anulado', true))
            ->when($f['estado'] === 'por_autorizar', fn ($q) => $q->where('movimientos_transporte.estatus', 'no_llego')
                ->where('movimientos_transporte.anulado', false)->whereNull('movimientos_transporte.autorizado_en'))
            ->when($f['q'] !== '', function ($q) use ($f) {
                $comodin = '%'.addcslashes(mb_strtolower($f['q']), '%_\\').'%';
                $placas = '%'.addcslashes(Vehiculo::normalizarPlacas($f['q']), '%_\\').'%';
                $q->where(fn ($b) => $b->whereRaw('LOWER(movimientos_transporte.observaciones) LIKE ?', [$comodin])
                    ->orWhereHas('ruta', fn ($r) => $r->whereRaw('LOWER(nombre) LIKE ?', [$comodin]))
                    ->orWhereHas('vehiculo', fn ($v) => $v->where('placas', 'like', $placas)->orWhereRaw('LOWER(numero_economico) LIKE ?', [$comodin]))
                    ->orWhereHas('chofer', fn ($c) => $c->whereRaw('LOWER(nombre_completo) LIKE ?', [$comodin]))
                    ->orWhereHas('paradero', fn ($p) => $p->whereRaw('LOWER(nombre) LIKE ?', [$comodin]))
                    ->orWhereHas('pasajeros', fn ($c) => $c->where(fn ($n) => $n->whereRaw('LOWER(colaboradores.nombre) LIKE ?', [$comodin])
                        ->orWhereRaw('LOWER(colaboradores.apellido_paterno) LIKE ?', [$comodin])->orWhere('colaboradores.num_empleado', 'like', $comodin))));
            });
    }

    /**
     * @param  callable(User, MovimientoTransporte): void  $accion
     * @param  callable(MovimientoTransporte): string  $mensaje
     */
    private function cambiarEstado(Request $request, int $movimiento, string $permiso, callable $accion, string $tipo, callable $mensaje): RedirectResponse
    {
        Gate::authorize($permiso);
        $empresaId = $this->empresaDeTrabajo($request);

        $m = $this->tenant->conEmpresa($empresaId, function () use ($request, $movimiento, $permiso, $accion) {
            $m = $this->visible($request->user(), $movimiento);
            abort_unless($this->bitacora->puede($request->user(), $permiso, $m), 403);
            $accion($request->user(), $m);

            return $m;
        });

        return $this->volver($m)->with($tipo, $mensaje($m));
    }

    private function empresaDeTrabajo(Request $request): int
    {
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        return $empresaId;
    }

    /**
     * Movimiento de la empresa que el usuario puede ver (sede y alcance); si no, 404.
     */
    private function visible(User $actor, int $id): MovimientoTransporte
    {
        $m = $this->bitacora->limitar(MovimientoTransporte::query(), $actor, 'transporte.ver')->find($id);
        abort_if($m === null, 404);

        return $m;
    }

    /** De regreso a la pantalla de donde vino (lista o detalle), en la ficha. */
    private function volver(MovimientoTransporte $m): RedirectResponse
    {
        $anterior = url()->previous();
        $base = str_starts_with($anterior, route('transporte.index')) ? $anterior : route('transporte.index', ['fecha_inicio' => $m->fecha->toDateString(), 'fecha_fin' => $m->fecha->toDateString()]);

        return redirect()->to(strtok($base, '#').'#movimiento-'.$m->id);
    }
}
