<?php

namespace App\Http\Controllers\Operacion\Reservas;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\Reserva;
use App\Models\ReservaAprobacion;
use App\Models\ReservaArea;
use App\Models\ReservaServicio;
use App\Models\ReservaTipoUso;
use App\Models\Sede;
use App\Models\User;
use App\Services\Reservas\AdministradorReservas;
use App\Services\Reservas\AjustesReservas;
use App\Services\Reservas\CatalogosReservas;
use App\Services\Reservas\Cotizador;
use App\Services\Reservas\Disponibilidad;
use App\Services\Reservas\EsperaReservas;
use App\Services\Reservas\ResponsablesReservas;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reservas de áreas (docs/tecnico/reservas.md): calendario por área (día,
 * semana, mes), Mis reservas, reservar, ficha, modificar, cancelar, ajustar
 * el costo e imprimir la hoja (orden de evento / cotización).
 */
class ReservaController extends Controller
{
    public const VISTAS = ['dia' => 'Día', 'semana' => 'Semana', 'mes' => 'Mes'];

    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly AdministradorReservas $reservas,
        private readonly Disponibilidad $disponibilidad,
        private readonly AjustesReservas $ajustes,
        private readonly ResponsablesReservas $responsables,
    ) {}

    // ------------------------------------------------------------ Calendario

    public function index(Request $request): View
    {
        $actor = $request->user();
        abort_unless($actor->can('reservas.ver') || $actor->can('reservas.crear'), 403);
        $empresaId = $this->empresa->id($actor);
        if ($empresaId === null) {
            return view('operacion.reservas.index', ['sinEmpresa' => true]);
        }
        app(EsperaReservas::class)->escalarSiToca($empresaId);

        $filtros = $request->validate([
            'vista' => ['nullable', 'in:dia,semana,mes'], 'fecha' => ['nullable', 'date_format:Y-m-d'],
            'sede' => ['nullable', 'integer'], 'area' => ['nullable', 'integer'],
        ]);
        $zona = Empresa::whereKey($empresaId)->value('zona_horaria') ?: 'America/Mexico_City';
        $vista = $filtros['vista'] ?? 'semana';
        $fecha = CarbonImmutable::createFromFormat('Y-m-d', $filtros['fecha'] ?? now($zona)->toDateString(), $zona)->startOfDay();
        [$desde, $hasta] = match ($vista) {
            'dia' => [$fecha, $fecha->addDay()],
            'mes' => [$fecha->startOfMonth()->startOfWeek(CarbonImmutable::MONDAY), $fecha->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY)->addSecond()],
            default => [$fecha->startOfWeek(CarbonImmutable::MONDAY), $fecha->startOfWeek(CarbonImmutable::MONDAY)->addWeek()],
        };

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $filtros, $vista, $fecha, $desde, $hasta, $zona) {
            $areas = $this->areasVisibles($actor);
            $sedes = Sede::whereIn('id', $areas->pluck('sede_id')->unique())->orderBy('nombre')->get(['id', 'nombre']);
            $sede = isset($filtros['sede']) && $sedes->contains('id', (int) $filtros['sede']) ? (int) $filtros['sede'] : null;
            $enSede = $areas->when($sede !== null, fn ($c) => $c->where('sede_id', $sede))->values();
            $area = isset($filtros['area']) && $enSede->contains('id', (int) $filtros['area']) ? (int) $filtros['area'] : null;
            $mostrar = $enSede->when($area !== null, fn ($c) => $c->where('id', $area))->values();

            $lista = Reserva::ocupan()->with(['area.espacio', 'area.sede', 'solicitante:id,name', 'tipoUso:id,nombre'])
                ->whereIn('reserva_area_id', $mostrar->pluck('id'))
                ->where('inicio', '<', $hasta->utc())->where('fin', '>', $desde->utc())
                ->orderBy('inicio')->get();
            $eventos = $lista->map(fn (Reserva $r) => $this->eventoCalendario($actor, $r, $zona));

            $dias = [];
            for ($d = $desde; $d->lessThan($hasta); $d = $d->addDay()) {
                $clave = $d->toDateString();
                $dias[$clave] = ['fecha' => $d, 'eventos' => $eventos->filter(fn ($e) => $e['dia'] === $clave)->values()];
            }

            return view('operacion.reservas.index', [
                'sinEmpresa' => false, 'vista' => $vista, 'fecha' => $fecha, 'desde' => $desde, 'hasta' => $hasta, 'dias' => $dias,
                'areas' => $enSede, 'mostrar' => $mostrar, 'sedes' => $sedes, 'sede' => $sede, 'area' => $area,
                'navegar' => $this->navegacion($vista, $fecha), 'hoy' => now($zona)->toDateString(),
                'puede' => $this->permisosGenerales($actor),
                'puedeReservar' => $mostrar->filter(fn ($a) => $this->reservas->puedeReservarEn($actor, (int) $a->sede_id))->pluck('id')->all(),
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function eventoCalendario(User $actor, Reserva $r, string $zona): array
    {
        $local = $this->disponibilidad->zona($r->area);
        $detalle = $this->reservas->puedeVer($actor, $r);

        return [
            'id' => $r->id, 'dia' => $r->inicio->copy()->setTimezone($zona)->toDateString(), 'area_id' => (int) $r->reserva_area_id, 'area' => $r->area->nombre(),
            'desde' => $r->inicio->copy()->setTimezone($local)->format('H:i'), 'hasta' => $r->fin->copy()->setTimezone($local)->format('H:i'),
            'bloqueo' => $r->bloqueo_desde->copy()->setTimezone($local)->format('H:i').'–'.$r->bloqueo_hasta->copy()->setTimezone($local)->format('H:i'),
            'titulo' => $detalle ? $r->titulo : 'Ocupado', 'estado' => $r->estado, 'estadoTexto' => $r->estadoTexto(),
            'url' => $detalle ? route('reservas.show', $r->id) : null, 'mia' => (int) $r->solicitante_id === (int) $actor->id,
        ];
    }

    /**
     * @return array{anterior: string, siguiente: string, titulo: string}
     */
    private function navegacion(string $vista, CarbonImmutable $fecha): array
    {
        $meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        [$anterior, $siguiente, $titulo] = match ($vista) {
            'dia' => [$fecha->subDay(), $fecha->addDay(), self::diaTexto($fecha).' '.$fecha->day.' de '.$meses[$fecha->month].' de '.$fecha->year],
            'mes' => [$fecha->subMonthNoOverflow(), $fecha->addMonthNoOverflow(), ucfirst($meses[$fecha->month]).' de '.$fecha->year],
            default => [$fecha->subWeek(), $fecha->addWeek(), 'Semana del '.$fecha->startOfWeek(CarbonImmutable::MONDAY)->format('d/m').' al '.$fecha->startOfWeek(CarbonImmutable::MONDAY)->addDays(6)->format('d/m/Y')],
        };

        return ['anterior' => $anterior->toDateString(), 'siguiente' => $siguiente->toDateString(), 'titulo' => $titulo];
    }

    public static function diaTexto(CarbonImmutable $fecha): string
    {
        return ['', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'][$fecha->dayOfWeekIso];
    }

    /**
     * Áreas reservables que el usuario puede ver o reservar.
     *
     * @return Collection<int, ReservaArea>
     */
    private function areasVisibles(User $actor, string $permiso = 'ver|crear'): Collection
    {
        $permisos = explode('|', $permiso);
        $sedes = [];
        $todas = false;
        foreach ($permisos as $p) {
            if (! $actor->can('reservas.'.$p)) {
                continue;
            }
            $s = $this->reservas->sedes($actor, 'reservas.'.$p);
            if ($s === null) {
                $todas = true;
            } else {
                $sedes = array_merge($sedes, $s);
            }
        }

        return ReservaArea::with(['espacio:id,nombre,activo', 'sede:id,nombre,zona_horaria,empresa_id', 'montajes'])
            ->where('reservable', true)->whereHas('espacio', fn ($q) => $q->where('activo', true))
            ->when(! $todas, fn ($q) => $q->whereIn('sede_id', array_unique($sedes)))
            ->get()->sortBy(fn ($a) => $a->sede?->nombre.' '.$a->nombre())->values();
    }

    /**
     * @return array<string, bool>
     */
    private function permisosGenerales(User $actor): array
    {
        return [
            'crear' => $actor->can('reservas.crear'), 'ver' => $actor->can('reservas.ver'), 'aprobar' => $actor->can('reservas.aprobar'),
            'configurar' => $actor->can('reservas.configurar'), 'exportar' => $actor->can('reservas.exportar'),
        ];
    }

    // ----------------------------------------------------------- Mis reservas

    public function mias(Request $request): View
    {
        $actor = $request->user();
        abort_unless($actor->can('reservas.crear') || $actor->can('reservas.ver'), 403);
        $empresaId = $this->empresa->id($actor);
        if ($empresaId === null) {
            return view('operacion.reservas.index', ['sinEmpresa' => true]);
        }
        $estado = array_key_exists((string) $request->query('estado'), Reserva::ESTADOS) ? (string) $request->query('estado') : null;
        $pasadas = $request->boolean('pasadas');

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $estado, $pasadas) {
            $lista = Reserva::with(['area.espacio', 'area.sede', 'aprobaciones.departamento:id,nombre', 'ordenes'])
                ->where('solicitante_id', $actor->id)
                ->when($estado !== null, fn ($q) => $q->where('estado', $estado))
                ->when($estado === null, fn ($q) => $pasadas ? $q->where('fin', '<', now()) : $q->where('fin', '>=', now()))
                ->orderBy($pasadas ? 'inicio' : 'inicio', $pasadas ? 'desc' : 'asc')
                ->paginate(30)->withQueryString();
            $conteos = Reserva::where('solicitante_id', $actor->id)->where('fin', '>=', now())
                ->selectRaw('estado, COUNT(*) AS total')->groupBy('estado')->pluck('total', 'estado');

            return view('operacion.reservas.mias', [
                'reservas' => $lista, 'estado' => $estado, 'pasadas' => $pasadas, 'conteos' => $conteos,
                'puede' => $this->permisosGenerales($actor), 'textos' => $this->reservas->textos(),
            ]);
        });
    }

    // -------------------------------------------------------- Reservar / editar

    public function create(Request $request): View|RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor->can('reservas.crear'), 403);
        $empresaId = $this->empresa->id($actor);
        if ($empresaId === null) {
            return view('operacion.reservas.index', ['sinEmpresa' => true]);
        }

        return $this->tenant->conEmpresa($empresaId, fn () => $this->formulario($actor, $empresaId, null, $request));
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor->can('reservas.crear'), 403);
        abort_if(! $this->tenant->activo(), 404);
        $reserva = $this->reservas->crear($actor, $request->all());

        $confirmada = $reserva->estado === Reserva::CONFIRMADA;

        return redirect()->route('reservas.show', $reserva->id)
            ->with('ok', $confirmada
                ? "Reserva {$reserva->folioTexto()} confirmada: el área ya es tuya en ese horario."
                : "Reserva {$reserva->folioTexto()} enviada. Te avisaremos en la campana cuando la aprueben.")
            ->with('siguientes', [
                ['Imprimir la hoja', route('reservas.imprimir', $reserva->id), 'bi-printer'],
                ['Hacer otra reserva', route('reservas.create'), 'bi-plus-circle'],
            ]);
    }

    public function edit(Request $request, int $reserva): View|RedirectResponse
    {
        $actor = $request->user();
        $reserva = $this->cargar($reserva);
        if (! $this->reservas->puedeModificar($actor, $reserva)) {
            abort_unless($this->reservas->puedeVer($actor, $reserva), 403);

            return redirect()->route('reservas.show', $reserva->id)->with('error', 'Esta reserva ya no se puede modificar (empezó, terminó, se rechazó o se canceló).');
        }

        return $this->formulario($actor, (int) $reserva->empresa_id, $reserva->load('items'), $request);
    }

    public function update(Request $request, int $reserva): RedirectResponse
    {
        $actor = $request->user();
        $reserva = $this->cargar($reserva);
        abort_unless($this->reservas->puedeVer($actor, $reserva), 403);
        $reserva = $this->reservas->modificar($actor, $reserva, $request->all());

        return redirect()->route('reservas.show', $reserva->id)
            ->with('ok', $reserva->estado === Reserva::CONFIRMADA
                ? 'Cambios guardados: la reserva sigue confirmada.'
                : 'Cambios guardados: se pidió de nuevo la aprobación de lo que cambió.')
            ->with('siguientes', [['Ver mis reservas', route('reservas.mias'), 'bi-calendar-week']]);
    }

    private function formulario(User $actor, int $empresaId, ?Reserva $reserva, Request $request): View
    {
        app(CatalogosReservas::class)->asegurar();
        $areas = $this->areasVisibles($actor, 'crear')->filter(fn ($a) => $this->reservas->puedeReservarEn($actor, (int) $a->sede_id))->values();
        if ($reserva !== null && ! $areas->contains('id', $reserva->reserva_area_id)) {
            $actual = ReservaArea::with(['espacio', 'sede', 'montajes'])->find($reserva->reserva_area_id);
            if ($actual !== null) {
                $areas->push($actual);
            }
        }
        $ajustes = $this->ajustes->de($empresaId);
        $zonaDe = fn (ReservaArea $a) => $this->disponibilidad->zona($a);
        $valores = $reserva === null ? [
            'reserva_area_id' => (int) $request->query('area', $areas->count() === 1 ? $areas->first()->id : 0),
            'fecha' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('fecha')) ? $request->query('fecha') : '',
            'hora_inicio' => '', 'hora_fin' => '', 'titulo' => '', 'tipo_uso_id' => '', 'montaje_id' => '', 'asistentes' => '',
            'para_cliente' => false, 'cliente_nombre' => '', 'cliente_empresa' => '', 'cliente_correo' => '', 'cliente_telefono' => '',
            'tarifa' => '', 'notas' => '', 'servicios' => [],
        ] : [
            'reserva_area_id' => (int) $reserva->reserva_area_id,
            'fecha' => $reserva->inicio->copy()->setTimezone($zonaDe($reserva->area))->toDateString(),
            'hora_inicio' => $reserva->inicio->copy()->setTimezone($zonaDe($reserva->area))->format('H:i'),
            'hora_fin' => $reserva->fin->copy()->setTimezone($zonaDe($reserva->area))->format('H:i'),
            'titulo' => $reserva->titulo, 'tipo_uso_id' => $reserva->tipo_uso_id, 'montaje_id' => $reserva->montaje_id, 'asistentes' => $reserva->asistentes,
            'para_cliente' => $reserva->para_cliente, 'cliente_nombre' => $reserva->cliente_nombre, 'cliente_empresa' => $reserva->cliente_empresa,
            'cliente_correo' => $reserva->cliente_correo, 'cliente_telefono' => $reserva->cliente_telefono, 'tarifa' => $reserva->tarifa, 'notas' => $reserva->notas,
            'servicios' => $reserva->items->whereNotNull('servicio_id')->mapWithKeys(fn ($i) => [(int) $i->servicio_id => ['cantidad' => $i->cantidadTexto(), 'notas' => $i->notas]])->all(),
        ];

        return view('operacion.reservas.formulario', [
            'reserva' => $reserva, 'valores' => $valores, 'ajustes' => $ajustes,
            'areas' => $areas->map(fn (ReservaArea $a) => [
                'id' => $a->id, 'nombre' => $a->nombre(), 'sede' => $a->sede?->nombre, 'aforo' => $a->aforo_maximo,
                'montajes' => $a->montajes->map(fn ($m) => ['id' => $m->id, 'nombre' => $m->nombre, 'aforo' => $m->pivot->aforo])->values()->all(),
                'tarifas' => $a->tarifas(), 'montaje' => (int) $a->montaje_minutos, 'desmontaje' => (int) $a->desmontaje_minutos,
                'inmediata' => (bool) $a->confirmacion_inmediata, 'horario' => $this->horarioTexto($a), 'descripcion' => $a->descripcion,
                'foto' => $a->foto ? route('reservas.foto', $a->id) : null,
            ])->values(),
            'tipos' => ReservaTipoUso::where('activo', true)->orderBy('orden')->orderBy('nombre')->get(['id', 'nombre']),
            'servicios' => ReservaServicio::with('departamento:id,nombre')->where('activo', true)->orderBy('categoria')->orderBy('orden')->orderBy('nombre')->get()
                ->groupBy('categoria'),
            'puedeAjustar' => $actor->can('reservas.aprobar'),
        ]);
    }

    /**
     * «Lun a vie 08:00–22:00 · sáb 09:00–14:00» (o «Cualquier hora»).
     *
     * @return list<string>
     */
    private function horarioTexto(ReservaArea $a): array
    {
        if (! is_array($a->horario) || $a->horario === []) {
            return ['Cualquier día y hora'];
        }
        $lineas = [];
        foreach (ReservaArea::DIAS as $n => $dia) {
            $v = (array) ($a->horario[(string) $n] ?? []);
            $lineas[] = mb_substr($dia, 0, 3).': '.($v === [] ? 'no se reserva' : collect($v)->map(fn ($x) => $x[0].'–'.$x[1])->join(', '));
        }

        return $lineas;
    }

    /**
     * Horarios ocupados de un área en un día (para el formulario).
     */
    public function disponibilidad(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor->can('reservas.crear') || $actor->can('reservas.ver'), 403);
        abort_if(! $this->tenant->activo(), 404);
        $datos = $request->validate(['area' => ['required', 'integer'], 'fecha' => ['required', 'date_format:Y-m-d'], 'excepto' => ['nullable', 'integer']]);
        $area = ReservaArea::where('reservable', true)->findOrFail((int) $datos['area']);
        abort_unless($this->reservas->puedeReservarEn($actor, (int) $area->sede_id) || $actor->can('reservas.ver', $area), 403);

        $fecha = CarbonImmutable::createFromFormat('Y-m-d', $datos['fecha'], $this->disponibilidad->zona($area));
        $ventanas = is_array($area->horario) && $area->horario !== [] ? (array) ($area->horario[(string) $fecha->dayOfWeekIso] ?? []) : null;

        return response()->json([
            'ocupado' => $this->disponibilidad->ocupadoEl($area, $datos['fecha'], isset($datos['excepto']) ? (int) $datos['excepto'] : null),
            'horario' => $ventanas === null ? 'Cualquier hora' : ($ventanas === [] ? 'Este día el área no se reserva' : collect($ventanas)->map(fn ($v) => $v[0].' a '.$v[1])->join(' y ')),
        ]);
    }

    // ------------------------------------------------------------------ Ficha

    public function show(Request $request, int $reserva): View
    {
        $actor = $request->user();
        $reserva = $this->cargar($reserva);
        $reserva->load(['area.espacio', 'area.sede', 'area.montajes', 'sede:id,nombre', 'tipoUso:id,nombre', 'montaje:id,nombre', 'solicitante:id,name,email',
            'items.departamento:id,nombre', 'aprobaciones.departamento:id,nombre', 'aprobaciones.respondidaPor:id,name', 'ordenes.departamento:id,nombre',
            'ordenes.listaPor:id,name', 'eventos.usuario:id,name', 'canceladaPor:id,name']);
        $vigentes = $reserva->aprobaciones->where('vigente', true)->values();
        $responder = $vigentes->filter(fn (ReservaAprobacion $a) => $this->responsables->puedeResponder($actor, $a, $reserva))->values();
        $ordenes = $reserva->ordenes->where('estado', '!=', 'cancelada')->values();
        $atiende = $ordenes->filter(fn ($o) => $o->estado === 'pendiente' && $reserva->estado === Reserva::CONFIRMADA
            && ($this->responsables->atiendeDepartamento($actor, (int) $o->departamento_id, (int) $reserva->sede_id, (int) $reserva->empresa_id) || $actor->can('reservas.configurar', $reserva)))
            ->pluck('id')->all();
        $atiendeAlgo = $ordenes->contains(fn ($o) => $this->responsables->atiendeDepartamento($actor, (int) $o->departamento_id, (int) $reserva->sede_id, (int) $reserva->empresa_id));
        abort_unless($this->reservas->puedeVer($actor, $reserva) || $responder->isNotEmpty() || $atiendeAlgo
            || $vigentes->contains(fn ($a) => $this->responsables->leToca($actor, $a, $reserva)), 403);

        $espera = app(EsperaReservas::class);
        $umbrales = $espera->umbrales((int) $reserva->empresa_id);

        return view('operacion.reservas.show', [
            'reserva' => $reserva, 'vigentes' => $vigentes, 'responder' => $responder->pluck('id')->all(),
            'relojes' => $vigentes->mapWithKeys(fn ($a) => [$a->id => $espera->reloj($a, $umbrales)])->all(),
            'ordenes' => $ordenes, 'atiende' => $atiende, 'textos' => $this->reservas->textos(),
            'zona' => $this->disponibilidad->zona($reserva->area),
            'puede' => [
                'modificar' => $this->reservas->puedeModificar($actor, $reserva), 'cancelar' => $this->reservas->puedeCancelar($actor, $reserva),
                'costo' => $this->reservas->puedeAjustarCosto($actor, $reserva), 'imprimir' => $this->puedeImprimir($actor, $reserva),
                'historial' => $actor->can('auditoria.ver'),
            ],
            'designados' => $vigentes->mapWithKeys(fn ($a) => [$a->id => User::whereIn('id', $this->responsables->designados($a, $reserva))->pluck('name')->join(', ', ' y ')])->all(),
        ]);
    }

    /** La reserva de la empresa de trabajo (otra empresa o sin empresa elegida: 404). */
    private function cargar(int $id): Reserva
    {
        abort_if(! $this->tenant->activo(), 404);

        return Reserva::findOrFail($id);
    }

    private function puedeImprimir(User $actor, Reserva $reserva): bool
    {
        return $actor->can('reservas.imprimir', $reserva)
            || ((int) $reserva->solicitante_id === (int) $actor->id && $actor->can('reservas.imprimir'));
    }

    public function cancelar(Request $request, int $reserva): RedirectResponse
    {
        $actor = $request->user();
        $reserva = $this->cargar($reserva);
        abort_unless($this->reservas->puedeVer($actor, $reserva) || $actor->can('reservas.aprobar', $reserva), 403);
        $this->reservas->cancelar($actor, $reserva, (string) $request->input('motivo'));

        return redirect()->route('reservas.show', $reserva->id)->with('ok', 'Reserva cancelada: el área quedó libre y avisamos a quien la iba a aprobar o atender.')
            ->with('siguientes', [['Hacer otra reserva', route('reservas.create'), 'bi-plus-circle']]);
    }

    public function costo(Request $request, int $reserva): RedirectResponse
    {
        $reserva = $this->cargar($reserva);
        $this->reservas->ajustarCosto($request->user(), $reserva, $request->all());

        return redirect()->route('reservas.show', $reserva->id)->with('ok', 'Costo guardado. Total: '.Cotizador::dinero((float) $reserva->refresh()->total, $reserva->moneda).'.')
            ->with('siguientes', [['Imprimir la cotización', route('reservas.imprimir', $reserva->id), 'bi-printer']]);
    }

    public function imprimir(Request $request, int $reserva): View
    {
        $reserva = $this->cargar($reserva);
        abort_unless($this->puedeImprimir($request->user(), $reserva), 403);
        $reserva->load(['area.espacio', 'area.sede', 'sede', 'tipoUso:id,nombre', 'montaje:id,nombre', 'solicitante:id,name',
            'items.departamento:id,nombre', 'aprobaciones.departamento:id,nombre', 'aprobaciones.respondidaPor:id,name', 'ordenes.departamento:id,nombre']);

        return view('operacion.reservas.imprimir', [
            'reserva' => $reserva, 'textos' => $this->reservas->textos(), 'zona' => $this->disponibilidad->zona($reserva->area),
            'empresa' => Empresa::find($reserva->empresa_id),
        ]);
    }

    public function foto(Request $request, int $area): StreamedResponse
    {
        $actor = $request->user();
        abort_if(! $this->tenant->activo(), 404);
        $area = ReservaArea::findOrFail($area);
        abort_unless($actor->can('reservas.ver') || $actor->can('reservas.crear') || $actor->can('reservas.configurar'), 403);
        abort_if($area->foto === null || ! Storage::disk('local')->exists($area->foto), 404);

        return Storage::disk('local')->response($area->foto, null, ['Cache-Control' => 'private, max-age=3600']);
    }
}
