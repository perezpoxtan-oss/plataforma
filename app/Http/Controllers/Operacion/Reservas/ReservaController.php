<?php

namespace App\Http\Controllers\Operacion\Reservas;

use App\Http\Controllers\Controller;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Espacio;
use App\Models\Reserva;
use App\Models\ReservaAprobacion;
use App\Models\ReservaArea;
use App\Models\ReservaServicio;
use App\Models\ReservaServicioItem;
use App\Models\ReservaTipoUso;
use App\Models\Sede;
use App\Models\User;
use App\Services\Reservas\AdministradorReservas;
use App\Services\Reservas\AjustesReservas;
use App\Services\Reservas\CatalogosReservas;
use App\Services\Reservas\Cotizador;
use App\Services\Reservas\Disponibilidad;
use App\Services\Reservas\EsperaReservas;
use App\Services\Reservas\NivelesReservas;
use App\Services\Reservas\ResponsablesReservas;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    /**
     * Entrada (formato de fichas, como Zonas y edificios y Rutas): tarjetas de
     * SEDES con sus conteos → fichas de las ÁREAS reservables de la sede (con
     * una sola sede se entra directo). Los enlaces anteriores con vista, fecha
     * o área abren el calendario.
     */
    public function index(Request $request): View
    {
        $actor = $request->user();
        abort_unless($actor->can('reservas.ver') || $actor->can('reservas.crear'), 403);
        if ($request->hasAny(['vista', 'fecha', 'area'])) {
            return $this->calendario($request);
        }
        $empresaId = $this->empresa->id($actor);
        if ($empresaId === null) {
            return view('operacion.reservas.sedes', ['sinEmpresa' => true]);
        }
        app(EsperaReservas::class)->escalarSiToca($empresaId);
        $elegida = $request->integer('sede') ?: null;
        $zonaElegida = $request->integer('zona') ?: null;

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $empresaId, $elegida, $zonaElegida) {
            $areas = $this->areasVisibles($actor);
            $sedes = Sede::whereIn('id', $areas->pluck('sede_id')->unique())->orderBy('nombre')->get(['id', 'nombre', 'codigo', 'zona_horaria', 'empresa_id']);
            $zona = Empresa::whereKey($empresaId)->value('zona_horaria') ?: 'America/Mexico_City';
            $hoyDesde = now($zona)->startOfDay()->utc();
            $hoyHasta = now($zona)->startOfDay()->addDay()->utc();
            $sede = $elegida !== null && $sedes->contains('id', $elegida) ? $elegida : ($sedes->count() === 1 ? (int) $sedes->first()->id : null);
            $puede = $this->permisosGenerales($actor);

            if ($sede === null) {
                // Conteos por sede en dos consultas
                $hoy = Reserva::ocupan()->whereIn('reserva_area_id', $areas->pluck('id'))->where('inicio', '<', $hoyHasta)->where('fin', '>', $hoyDesde)
                    ->selectRaw('sede_id, COUNT(*) AS total')->groupBy('sede_id')->pluck('total', 'sede_id');
                $porAprobar = Reserva::whereIn('reserva_area_id', $areas->pluck('id'))->whereIn('estado', [Reserva::POR_APROBAR, Reserva::CAMBIO_PEDIDO])
                    ->selectRaw('sede_id, COUNT(*) AS total')->groupBy('sede_id')->pluck('total', 'sede_id');
                $resumen = $sedes->mapWithKeys(fn ($s) => [$s->id => [
                    'areas' => $areas->where('sede_id', $s->id)->reject(fn ($a) => $a->porInteriores())->count(), 'hoy' => (int) ($hoy[$s->id] ?? 0), 'por_aprobar' => (int) ($porAprobar[$s->id] ?? 0),
                ]])->all();

                return view('operacion.reservas.sedes', ['sinEmpresa' => false, 'sedes' => $sedes, 'resumen' => $resumen, 'puede' => $puede]);
            }

            // Fichas por niveles, como Zonas y edificios: primer nivel de la sede
            // y, al entrar a una zona con espacios interiores, sus espacios.
            $enSede = $areas->where('sede_id', $sede)->values();
            $niveles = app(NivelesReservas::class);
            $padres = $niveles->padres($enSede);
            $zona = $zonaElegida !== null ? $enSede->firstWhere('id', $zonaElegida) : null;
            $nivel = $niveles->hijas($enSede, $padres, $zona?->id);
            $porId = $enSede->keyBy('id');
            $finales = fn (int $id) => collect($niveles->descendientes($padres, $id))->reject(fn ($d) => $porId[$d]->porInteriores())->values();

            // Próxima reserva y cuántas hay hoy (dos consultas para toda la sede)
            $proximas = Reserva::ocupan()->whereIn('reserva_area_id', $enSede->pluck('id'))->where('fin', '>', now())
                ->orderBy('inicio')->get(['id', 'reserva_area_id', 'inicio', 'fin'])->groupBy('reserva_area_id');
            $titulares = $nivel->mapWithKeys(fn ($a) => [$a->id => $this->responsables->delArea($a)[0]]);
            $nombres = User::whereIn('id', $titulares->filter()->unique())->pluck('name', 'id');
            $local = fn ($fecha, $area) => $fecha->copy()->setTimezone($this->disponibilidad->zona($area));
            $moneda = (string) (Empresa::whereKey($empresaId)->value('moneda') ?: 'MXN');
            // Dónde está cada ficha dentro de la zona («Piso 1»), para agruparlas
            $ancestros = $nivel->flatMap(fn ($a) => $a->espacio?->idsAncestros() ?? [])->unique()->values();
            $nombresEspacio = $ancestros->isEmpty() ? collect() : Espacio::whereIn('id', $ancestros)->pluck('nombre', 'id');
            $fichas = $nivel->map(function (ReservaArea $a) use ($proximas, $titulares, $nombres, $local, $hoyDesde, $hoyHasta, $moneda, $finales, $zona, $nombresEspacio) {
                $dentro = $finales((int) $a->id);
                $ids = $dentro->push((int) $a->id);
                $suyas = $ids->flatMap(fn ($id) => $proximas->get($id, collect()))->sortBy('inicio')->values();
                $proxima = $suyas->first(fn ($r) => $r->inicio->greaterThanOrEqualTo(now())) ?? $suyas->first();
                $tarifas = $a->tarifas();
                $antes = $a->espacio?->idsAncestros() ?? [];
                if ($zona !== null && ($i = array_search((int) $zona->espacio_id, $antes, true)) !== false) {
                    $antes = array_slice($antes, $i + 1);
                }

                return [
                    'area' => $a, 'responsable' => $titulares[$a->id] ? ($nombres[$titulares[$a->id]] ?? null) : null,
                    'hoy' => $suyas->filter(fn ($r) => $r->inicio->lessThan($hoyHasta) && $r->fin->greaterThan($hoyDesde))->count(),
                    'proxima' => $proxima === null ? null : ReservaController::diaTexto($local($proxima->inicio, $a)).' '.$local($proxima->inicio, $a)->format('d/m H:i'),
                    'tarifa' => $tarifas === [] ? null : collect($tarifas)->map(fn ($v, $k) => ReservaArea::TARIFAS[$k][1].' '.Cotizador::dinero($v, $moneda))->first(),
                    'dentro' => $dentro->count() - 1,
                    'grupo' => $zona === null ? '' : collect($antes)->map(fn ($id) => $nombresEspacio[$id] ?? null)->filter()->join(' › '),
                ];
            })->sortBy(fn ($f) => $f['grupo'].' '.mb_strtolower($f['area']->nombre()))->values();

            // Migas: Sede › zonas que contienen a la actual › zona actual
            $migas = [];
            for ($z = $zona; $z !== null; $z = ($padres[$z->id] ?? null) !== null ? $porId[$padres[$z->id]] : null) {
                array_unshift($migas, $z);
            }
            $sedesConfigurar = $actor->can('reservas.configurar') ? $this->reservas->sedes($actor, 'reservas.configurar') : [];
            $configurarAqui = $sedesConfigurar === null || in_array($sede, $sedesConfigurar, true);

            return view('operacion.reservas.sede', [
                'sede' => $sedes->firstWhere('id', $sede), 'variasSedes' => $sedes->count() > 1, 'fichas' => $fichas, 'puede' => $puede,
                'puedeReservar' => $this->reservas->puedeReservarEn($actor, $sede), 'zona' => $zona, 'migas' => $migas,
                'configurarAqui' => $configurarAqui,
                'eliminar' => $configurarAqui ? app(CatalogosReservas::class)->resumenParaEliminar($nivel) : [],
                // La lista para «Agregar área reservable» se pide al abrir el diálogo
                'opciones' => $configurarAqui && session('errors')?->has('espacio_id') ? app(CatalogosReservas::class)->opcionesParaAgregar([$sede], Empresa::find($empresaId)) : null,
            ]);
        });
    }

    /**
     * Calendario por área (día, semana, mes): vista secundaria, desde la ficha
     * del área o el botón «Calendario».
     */
    public function calendario(Request $request): View
    {
        $actor = $request->user();
        abort_unless($actor->can('reservas.ver') || $actor->can('reservas.crear'), 403);
        $empresaId = $this->empresa->id($actor);
        if ($empresaId === null) {
            return view('operacion.reservas.sedes', ['sinEmpresa' => true]);
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
            // Una zona «por sus espacios interiores» no se reserva: no tiene calendario propio
            $areas = $this->areasVisibles($actor)->reject(fn (ReservaArea $a) => $a->porInteriores())->values();
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

            return view('operacion.reservas.calendario', [
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

    public static function diaTexto(CarbonInterface $fecha): string
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

        return ReservaArea::with(['espacio:id,nombre,activo,nivel,ruta,padre_id', 'sede:id,nombre,zona_horaria,empresa_id', 'montajes'])
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
            return view('operacion.reservas.sedes', ['sinEmpresa' => true]);
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
            return view('operacion.reservas.sedes', ['sinEmpresa' => true]);
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
        $todas = $this->areasVisibles($actor, 'crear')->filter(fn ($a) => $this->reservas->puedeReservarEn($actor, (int) $a->sede_id))->values();
        if ($reserva !== null && ! $todas->contains('id', $reserva->reserva_area_id)) {
            $actual = ReservaArea::with(['espacio', 'sede', 'montajes'])->find($reserva->reserva_area_id);
            if ($actual !== null) {
                $todas->push($actual);
            }
        }
        // Cascada: zona (primer nivel de la sede) y luego el espacio final. Una
        // zona «por sus espacios interiores» solo agrupa; no se reserva.
        $niveles = app(NivelesReservas::class);
        $padres = $niveles->padres($todas);
        $porId = $todas->keyBy('id');
        $ancestros = $todas->flatMap(fn ($a) => $a->espacio?->idsAncestros() ?? [])->unique()->values();
        $nombresEspacio = $ancestros->isEmpty() ? collect() : Espacio::whereIn('id', $ancestros)->pluck('nombre', 'id');
        $areas = $todas->reject(fn (ReservaArea $a) => $a->porInteriores())->values();
        $ajustes = $this->ajustes->de($empresaId);
        $zonaDe = fn (ReservaArea $a) => $this->disponibilidad->zona($a);
        $valores = $reserva === null ? [
            'reserva_area_id' => (int) $request->query('area', $areas->count() === 1 ? $areas->first()->id : 0),
            'fecha' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('fecha')) ? $request->query('fecha') : '',
            'hora_inicio' => '', 'hora_fin' => '', 'titulo' => '', 'tipo_uso_id' => '', 'montaje_id' => '', 'asistentes' => '',
            'para_cliente' => false, 'cliente_nombre' => '', 'cliente_empresa' => '', 'cliente_correo' => '', 'cliente_telefono' => '',
            'tarifa' => '', 'notas' => '', 'servicios' => [], 'otros' => [],
            // Orden de evento: el departamento se propone con el del colaborador del usuario
            'departamento_solicitante_id' => $this->reservas->departamentoDe($actor) ?? '', 'dia_completo' => false,
            'montaje_minutos' => '', 'desmontaje_minutos' => '', 'indicaciones_montaje' => '', 'motivo_excepcion_aforo' => '',
        ] : [
            'reserva_area_id' => (int) $reserva->reserva_area_id,
            'fecha' => $reserva->inicio->copy()->setTimezone($zonaDe($reserva->area))->toDateString(),
            'hora_inicio' => $reserva->inicio->copy()->setTimezone($zonaDe($reserva->area))->format('H:i'),
            'hora_fin' => $reserva->fin->copy()->setTimezone($zonaDe($reserva->area))->format('H:i'),
            'titulo' => $reserva->titulo, 'tipo_uso_id' => $reserva->tipo_uso_id, 'montaje_id' => $reserva->montaje_id, 'asistentes' => $reserva->asistentes,
            'para_cliente' => $reserva->para_cliente, 'cliente_nombre' => $reserva->cliente_nombre, 'cliente_empresa' => $reserva->cliente_empresa,
            'cliente_correo' => $reserva->cliente_correo, 'cliente_telefono' => $reserva->cliente_telefono, 'tarifa' => $reserva->tarifa, 'notas' => $reserva->notas,
            'servicios' => $reserva->items->whereNotNull('servicio_id')->mapWithKeys(fn ($i) => [(int) $i->servicio_id => ['usar' => '1', 'cantidad' => $i->cantidadTexto(), 'notas' => $i->notas]])->all(),
            'otros' => $reserva->items->whereNull('servicio_id')->map(fn ($i) => ['departamento_id' => $i->departamento_id, 'nombre' => $i->nombre, 'notas' => $i->notas, 'cantidad' => $i->cantidadTexto()])->values()->all(),
            'departamento_solicitante_id' => $reserva->departamento_solicitante_id ?? '', 'dia_completo' => (bool) $reserva->dia_completo,
            'montaje_minutos' => $reserva->minutosMontaje(), 'desmontaje_minutos' => $reserva->minutosDesmontaje(),
            'indicaciones_montaje' => $reserva->indicaciones_montaje, 'motivo_excepcion_aforo' => $reserva->motivo_excepcion_aforo,
        ];
        $departamentos = Departamento::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']);
        $deptoMontaje = $ajustes['departamento_montaje_id'] !== null && $departamentos->contains('id', $ajustes['departamento_montaje_id']) ? $ajustes['departamento_montaje_id'] : null;
        // Los dados de baja ya no se ofrecen, salvo los que esta reserva ya pidió (se conservan)
        $yaPedidos = $reserva?->items->whereNotNull('servicio_id')->pluck('servicio_id')->map(fn ($id) => (int) $id)->all() ?? [];
        $servicios = ReservaServicio::with('departamento:id,nombre')
            ->where(fn ($q) => $q->where('activo', true)->orWhereIn('id', $yaPedidos))
            ->orderBy('orden')->orderBy('nombre')->get();
        $otros = collect(old('otros', $valores['otros']))->filter(fn ($f) => is_array($f))->values();

        return view('operacion.reservas.formulario', [
            'reserva' => $reserva, 'valores' => $valores, 'ajustes' => $ajustes,
            'areas' => $areas->map(function (ReservaArea $a) use ($niveles, $padres, $porId, $nombresEspacio) {
                $raiz = $porId[$niveles->raiz($padres, (int) $a->id)];
                $dentro = $raiz->id !== $a->id;
                // Dónde está dentro de su zona: «Piso 1 › 101» (o «Toda la zona»)
                $antes = $a->espacio?->idsAncestros() ?? [];
                $i = array_search((int) $raiz->espacio_id, $antes, true);
                $camino = $dentro && $i !== false ? collect(array_slice($antes, $i + 1))->map(fn ($id) => $nombresEspacio[$id] ?? null)->filter()->join(' › ') : '';

                return [
                    'id' => $a->id, 'nombre' => $a->nombre(), 'sede' => $a->sede?->nombre, 'aforo' => $a->aforo_maximo,
                    'zona' => (int) $raiz->id, 'zona_nombre' => $raiz->nombre(),
                    'texto' => $dentro ? trim(($camino !== '' ? $camino.' › ' : '').$a->nombre()) : ($niveles->descendientes($padres, (int) $a->id) !== [] ? 'Toda la zona' : $a->nombre()),
                    'montajes' => $a->montajes->map(fn ($m) => ['id' => $m->id, 'nombre' => $m->nombre, 'aforo' => $m->pivot->aforo])->values()->all(),
                    'tarifas' => $a->tarifas(), 'montaje' => (int) $a->montaje_minutos, 'desmontaje' => (int) $a->desmontaje_minutos,
                    'inmediata' => (bool) $a->confirmacion_inmediata, 'horario' => $this->horarioTexto($a), 'descripcion' => $a->descripcion,
                    // Ventanas por día (1 = lunes) para «Día completo»; null = cualquier hora
                    'ventanas' => is_array($a->horario) && $a->horario !== [] ? $a->horario : null,
                    'foto' => $a->foto ? route('reservas.foto', $a->id) : null,
                ];
            })->values(),
            // El tipo de uso que la reserva ya tiene se conserva aunque se haya dado de baja
            'tipos' => ReservaTipoUso::where(fn ($q) => $q->where('activo', true)->when($reserva?->tipo_uso_id, fn ($q, $id) => $q->orWhere('id', $id)))
                ->orderBy('orden')->orderBy('nombre')->get(['id', 'nombre']),
            'servicios' => $servicios,
            'grupos' => $this->gruposPorDepartamento($servicios, $departamentos, $deptoMontaje, $otros),
            'departamentos' => $departamentos,
            'deptoMontaje' => $deptoMontaje,
            'otros' => $otros,
            'puedeAjustar' => $actor->can('reservas.aprobar'),
        ]);
    }

    /**
     * Tarjetas «Servicios por departamento» (formato de orden de evento): el
     * departamento que hace el montaje primero, después los demás por nombre y
     * al final «Otros» (servicios sin departamento y otros servicios libres).
     *
     * @param  Collection<int, ReservaServicio>  $servicios
     * @param  Collection<int, Departamento>  $departamentos
     * @param  Collection<int, array<string, mixed>>  $otros
     * @return list<array{id: ?int, nombre: string, servicios: Collection<int, ReservaServicio>, montaje: bool}>
     */
    private function gruposPorDepartamento(Collection $servicios, Collection $departamentos, ?int $deptoMontaje, Collection $otros): array
    {
        $porDepto = $servicios->groupBy(fn ($s) => (int) ($s->departamento_id ?? 0));
        $ids = $porDepto->keys()->map(fn ($k) => (int) $k)
            ->merge($otros->pluck('departamento_id')->filter(fn ($d) => is_numeric($d))->map(fn ($d) => (int) $d))
            ->push((int) $deptoMontaje)->filter()->unique();
        $grupos = $departamentos->filter(fn ($d) => $ids->contains((int) $d->id))
            ->sortBy(fn ($d) => ((int) $d->id === $deptoMontaje ? '0' : '1').Str::lower(Str::ascii($d->nombre)))
            ->map(fn ($d) => ['id' => (int) $d->id, 'nombre' => $d->nombre, 'servicios' => $porDepto->get((int) $d->id, collect()), 'montaje' => (int) $d->id === $deptoMontaje])
            ->values()->all();
        // Servicios cuyo departamento ya no está activo también van en «Otros»
        $sueltos = $servicios->filter(fn ($s) => $s->departamento_id === null || ! $departamentos->contains('id', $s->departamento_id))->values();
        $grupos[] = ['id' => null, 'nombre' => 'Otros', 'servicios' => $sueltos, 'montaje' => false];

        return $grupos;
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
        $reserva->load(['area.espacio', 'area.sede', 'area.montajes', 'sede:id,nombre', 'tipoUso:id,nombre', 'montaje:id,nombre', 'solicitante:id,name,email', 'departamentoSolicitante:id,nombre',
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
        $mostrar = self::aprobacionesParaMostrar($reserva, $vigentes);

        return view('operacion.reservas.show', [
            'reserva' => $reserva, 'vigentes' => $vigentes, 'mostrar' => $mostrar, 'responder' => $responder->pluck('id')->all(),
            'relojes' => $vigentes->mapWithKeys(fn ($a) => [$a->id => $espera->reloj($a, $umbrales)])->all(),
            'ordenes' => $ordenes, 'atiende' => $atiende, 'textos' => $this->reservas->textos(),
            'zona' => $this->disponibilidad->zona($reserva->area),
            'aforo' => $reserva->area->aforoPara($reserva->montaje_id),
            'porDepartamento' => self::porDepartamento($reserva),
            'puede' => [
                'modificar' => $this->reservas->puedeModificar($actor, $reserva), 'cancelar' => $this->reservas->puedeCancelar($actor, $reserva),
                'costo' => $this->reservas->puedeAjustarCosto($actor, $reserva), 'imprimir' => $this->puedeImprimir($actor, $reserva),
                'historial' => $actor->can('auditoria.ver'),
            ],
            'designados' => $mostrar->mapWithKeys(fn ($a) => [$a->id => User::whereIn('id', $this->responsables->designados($a, $reserva))->pluck('name')->join(', ', ' y ')])->all(),
        ]);
    }

    /**
     * Aprobaciones del bloque «Aprobaciones» de la ficha: las vigentes y, si la
     * reserva se rechazó o se canceló, también las que quedaron pendientes en
     * ese momento (se muestran «Ya no aplica», sin botones) para que no
     * desaparezcan. Las reemplazadas por una modificación no se muestran.
     *
     * @param  Collection<int, ReservaAprobacion>  $vigentes
     * @return Collection<int, ReservaAprobacion>
     */
    public static function aprobacionesParaMostrar(Reserva $reserva, Collection $vigentes): Collection
    {
        $desde = match ($reserva->estado) {
            Reserva::RECHAZADA => $vigentes->where('estado', ReservaAprobacion::RECHAZADA)->max('respondida_en'),
            Reserva::CANCELADA => $reserva->cancelada_en,
            default => null,
        };
        if ($desde === null) {
            return $vigentes;
        }
        $claves = $vigentes->map(fn ($a) => $a->clave())->all();
        $yaNoAplican = $reserva->aprobaciones
            ->filter(fn (ReservaAprobacion $a) => ! $a->vigente && $a->estado === ReservaAprobacion::CANCELADA
                && $a->updated_at !== null && $a->updated_at->greaterThanOrEqualTo($desde->copy()->subSeconds(5))
                && ! in_array($a->clave(), $claves, true))
            ->sortByDesc('id')->unique(fn ($a) => $a->clave());

        return $vigentes->concat($yaNoAplican)->sortBy(fn ($a) => ($a->tipo === 'area' ? '0' : '1').'|'.$a->id)->values();
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
        $reserva->load(['area.espacio', 'area.sede', 'area.montajes', 'sede', 'tipoUso:id,nombre', 'montaje:id,nombre', 'solicitante:id,name', 'departamentoSolicitante:id,nombre',
            'items.departamento:id,nombre', 'aprobaciones.departamento:id,nombre', 'aprobaciones.respondidaPor:id,name', 'ordenes.departamento:id,nombre']);
        $completa = $request->boolean('completa');
        $deptoMontaje = $this->ajustes->de((int) $reserva->empresa_id)['departamento_montaje_id'];

        return view('operacion.reservas.imprimir', [
            'reserva' => $reserva, 'textos' => $this->reservas->textos(), 'zona' => $this->disponibilidad->zona($reserva->area),
            'empresa' => Empresa::find($reserva->empresa_id),
            'completa' => $completa,
            'secciones' => $this->seccionesHoja($reserva, $completa, $deptoMontaje),
            'aforo' => $reserva->area->aforoPara($reserva->montaje_id),
        ]);
    }

    /**
     * Renglones de la reserva agrupados por departamento (los sin departamento
     * al final, en «Otros»): [nombre => renglones].
     *
     * @return Collection<string, Collection<int, ReservaServicioItem>>
     */
    public static function porDepartamento(Reserva $reserva): Collection
    {
        return $reserva->items->sortBy(fn ($i) => ($i->departamento ? '0'.Str::lower(Str::ascii($i->departamento->nombre)) : '1').'|'.$i->id)
            ->groupBy(fn ($i) => $i->departamento?->nombre ?? 'Otros');
    }

    /**
     * Secciones de la orden de evento impresa, como la hoja del hotel: una por
     * departamento con sus casillas (☒ lo pedido, con cantidad y nota). Solo
     * los departamentos con algo pedido o, con «hoja completa», también los
     * servicios activos del catálogo sin marcar y los montajes del área.
     *
     * @return list<array{nombre: string, montaje: ?array{elegido: ?string, opciones: list<string>}, renglones: list<array{marcado: bool, nombre: string, detalle: string}>}>
     */
    private function seccionesHoja(Reserva $reserva, bool $completa, ?int $deptoMontaje): array
    {
        $secciones = [];
        $agregar = function (?int $id, string $nombre) use (&$secciones) {
            $clave = $id === null ? 'otros' : (string) $id;
            $secciones[$clave] ??= ['id' => $id, 'nombre' => $nombre, 'montaje' => null, 'renglones' => []];

            return $clave;
        };
        if ($deptoMontaje !== null && ($d = Departamento::find($deptoMontaje)) !== null && ($reserva->montaje_id !== null || $completa)) {
            $clave = $agregar((int) $d->id, $d->nombre);
            $secciones[$clave]['montaje'] = [
                'elegido' => $reserva->montaje?->nombre,
                'opciones' => $completa ? $reserva->area->montajes->pluck('nombre')->all() : [],
            ];
        }
        $pedidos = [];
        foreach ($reserva->items->sortBy('id') as $i) {
            $clave = $agregar($i->departamento_id !== null ? (int) $i->departamento_id : null, $i->departamento?->nombre ?? 'Otros');
            $unidad = ReservaServicio::UNIDADES[$i->unidad][1] ?? '';
            $detalle = trim(((float) $i->cantidad !== 1.0 || $i->unidad === 'persona' ? $i->cantidadTexto().' '.$unidad : '').($i->notas ? ' '.$i->notas : ''));
            $secciones[$clave]['renglones'][] = ['marcado' => true, 'nombre' => $i->nombre, 'detalle' => $detalle];
            if ($i->servicio_id !== null) {
                $pedidos[(int) $i->servicio_id] = true;
            }
        }
        if ($completa) {
            $catalogo = ReservaServicio::with('departamento:id,nombre')->where('activo', true)->orderBy('orden')->orderBy('nombre')->get();
            foreach ($catalogo as $s) {
                if (isset($pedidos[(int) $s->id])) {
                    continue;
                }
                $clave = $agregar($s->departamento_id !== null && $s->departamento ? (int) $s->departamento_id : null, $s->departamento?->nombre ?? 'Otros');
                $secciones[$clave]['renglones'][] = ['marcado' => false, 'nombre' => $s->nombre, 'detalle' => ''];
            }
        }

        return collect($secciones)->sortBy(fn ($s) => ($s['montaje'] !== null ? '0' : ($s['id'] === null ? '2' : '1')).Str::lower(Str::ascii($s['nombre'])))->values()->all();
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
