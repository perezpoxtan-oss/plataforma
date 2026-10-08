<?php

namespace App\Http\Controllers\Padrones;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\Paradero;
use App\Models\Ruta;
use App\Models\RutaHorario;
use App\Models\Sede;
use App\Models\User;
use App\Services\Rutas\AdministradorRutas;
use App\Support\Entrada;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Rutas de transporte (réplica de modules/transporte/ruta_* de SEGCAT):
 * fichas por sede con sus llegadas, salidas y próximos horarios; la
 * pantalla de la sede con pestañas Llegadas / Salidas / Paraderos (en SEGCAT
 * era un modal cargado por AJAX); la hoja del día para caseta y el
 * itinerario de cada ruta.
 *
 * Las reglas (validación, alcance, clonado, paraderos) viven en AdministradorRutas.
 */
class RutaController extends Controller
{
    public const PESTANAS = ['llegadas' => 'llegada', 'salidas' => 'salida', 'paraderos' => null];

    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly AdministradorRutas $rutas,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('rutas.ver');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);

        if ($empresaId === null) {
            return view('padrones.rutas.index', ['sinEmpresa' => true]);
        }

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $empresaId) {
            $sedes = $this->rutas->sedesVisibles($actor);
            $rutas = Ruta::whereIn('sede_id', $sedes->pluck('id'))
                ->with(['proveedor:id,nombre', 'horarios:id,ruta_id,nombre,dias,hora_inicio,hora_fin'])
                ->get(['id', 'sede_id', 'sentido', 'nombre', 'proveedor_id', 'activo'])
                ->each(fn (Ruta $r) => $r->horarios->each(fn (RutaHorario $h) => $h->setRelation('ruta', $r)))
                ->groupBy('sede_id');
            $paraderos = Paradero::whereIn('sede_id', $sedes->pluck('id'))->where('activo', true)
                ->selectRaw('sede_id, COUNT(*) as total')->groupBy('sede_id')->pluck('total', 'sede_id');

            $resumen = $sedes->mapWithKeys(function (Sede $sede) use ($rutas, $paraderos, $actor) {
                $deSede = $rutas->get($sede->id, collect());
                $ahora = $this->rutas->ahoraEn($sede);
                $proximas = fn (string $sentido) => array_map(
                    fn ($o) => $this->rutas->etiquetaProxima($o, $ahora),
                    $this->rutas->proximas($deSede->where('sentido', $sentido)->flatMap->horarios, $ahora),
                );

                return [$sede->id => [
                    'llegadas' => $deSede->where('sentido', 'llegada')->count(),
                    'salidas' => $deSede->where('sentido', 'salida')->count(),
                    'paraderos' => (int) ($paraderos[$sede->id] ?? 0),
                    'transportistas' => $deSede->where('activo', true)->map(fn ($r) => $r->proveedor?->nombre)->filter()->unique()->sort()->values()->all(),
                    'suspendidas' => $deSede->where('activo', false)->count(),
                    'proximas_llegadas' => $proximas('llegada'),
                    'proximas_salidas' => $proximas('salida'),
                    'rutas' => $deSede->pluck('nombre')->implode(' '),
                    'imprimir' => $this->rutas->puedeEnSede($actor, 'rutas.imprimir', $sede->id),
                ]];
            });

            return view('padrones.rutas.index', [
                'sinEmpresa' => false,
                'sedes' => $sedes,
                'resumen' => $resumen,
                'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial'),
            ]);
        });
    }

    /**
     * Pantalla de una sede (SEGCAT: sede_gestion_modal.php): pestañas
     * Llegadas (n), Salidas (n) y Paraderos (n) con ?tab=.
     */
    public function sede(Request $request, int $sede): View
    {
        Gate::authorize('rutas.ver');
        $actor = $request->user();
        $empresaId = $this->empresaDeTrabajo($request);
        $tab = array_key_exists(Entrada::texto($request->query('tab')), self::PESTANAS) ? Entrada::texto($request->query('tab')) : 'llegadas';

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $sede, $tab, $empresaId) {
            $modelo = $this->sedeVisible($actor, $sede);

            $rutas = Ruta::where('rutas.sede_id', $modelo->id)
                ->with(['turno:id,nombre,hora_inicio,hora_fin,activo', 'proveedor:id,nombre,activo', 'horarios.paradas.paradero:id,nombre'])
                ->leftJoin('users as uc', 'uc.id', '=', 'rutas.creado_por')
                ->leftJoin('users as ua', 'ua.id', '=', 'rutas.actualizado_por')
                ->select(['rutas.*', 'uc.name as creado_por_nombre', 'ua.name as actualizado_por_nombre'])
                ->orderByDesc('rutas.activo')->orderBy('rutas.hora_inicio')->orderBy('rutas.nombre')
                ->get();

            $paraderos = Paradero::where('paraderos.sede_id', $modelo->id)
                ->leftJoin('users as uc', 'uc.id', '=', 'paraderos.creado_por')
                ->leftJoin('users as ua', 'ua.id', '=', 'paraderos.actualizado_por')
                ->select(['paraderos.*', 'uc.name as creado_por_nombre', 'ua.name as actualizado_por_nombre'])
                ->orderByDesc('paraderos.activo')->orderBy('paraderos.nombre')
                ->get();

            // Cuántas rutas activas usan cada paradero (con las rutas ya cargadas)
            $usos = [];
            foreach ($rutas->where('activo', true) as $r) {
                foreach ($r->horarios as $h) {
                    foreach ($h->paradas as $p) {
                        $usos[$p->paradero_id][$r->id] = true;
                    }
                }
            }

            $puedeCrear = $this->rutas->puedeEnSede($actor, 'rutas.crear', $modelo->id);
            $puedeEditar = $this->rutas->puedeEnSede($actor, 'rutas.editar', $modelo->id);
            $conFormulario = $puedeCrear || $puedeEditar;
            $enUso = fn (string $campo) => $rutas->pluck($campo)->unique()->map(fn ($id) => (int) $id)->values()->all();

            return view('padrones.rutas.sede', [
                'sede' => $modelo,
                'tab' => $tab,
                'rutas' => $rutas,
                'paraderos' => $paraderos,
                'usos' => array_map('count', $usos),
                'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial'),
                'turnos' => $conFormulario ? $this->rutas->turnosPara($modelo, $enUso('turno_id')) : collect(),
                'proveedores' => $conFormulario ? $this->rutas->proveedoresPara($modelo, $enUso('proveedor_id')) : collect(),
                'editables' => $rutas->filter(fn ($r) => $this->rutas->puede($actor, 'rutas.editar', $r))->pluck('id')->all(),
                'desactivables' => $rutas->filter(fn ($r) => $this->rutas->puede($actor, 'rutas.eliminar', $r))->pluck('id')->all(),
                'paraderosEditables' => $paraderos->filter(fn ($p) => $this->rutas->puede($actor, 'rutas.editar', $p))->pluck('id')->all(),
                'paraderosDesactivables' => $paraderos->filter(fn ($p) => $this->rutas->puede($actor, 'rutas.eliminar', $p))->pluck('id')->all(),
                'puede' => [
                    'crear' => $puedeCrear,
                    'editar' => $puedeEditar,
                    'imprimir' => $this->rutas->puedeEnSede($actor, 'rutas.imprimir', $modelo->id),
                ],
            ]);
        });
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('rutas.crear');
        $empresaId = $this->empresaDeTrabajo($request);

        $ruta = $this->tenant->conEmpresa($empresaId, function () use ($request) {
            $sede = $this->sedeVisible($request->user(), (int) $request->input('sede_id'));
            abort_unless($this->rutas->puedeEnSede($request->user(), 'rutas.crear', $sede->id), 403);

            return $this->rutas->crear($request->user(), $sede, $request->all());
        });

        return $this->aLaRuta($ruta)->with('ok', "Ruta «{$ruta->nombre}» creada correctamente.");
    }

    public function update(Request $request, int $ruta): RedirectResponse
    {
        Gate::authorize('rutas.editar');
        $empresaId = $this->empresaDeTrabajo($request);

        $modelo = $this->tenant->conEmpresa($empresaId, function () use ($request, $ruta) {
            $modelo = $this->rutaVisible($request->user(), $ruta);
            abort_unless($this->rutas->puede($request->user(), 'rutas.editar', $modelo), 403);

            return $this->rutas->actualizar($request->user(), $modelo, $request->all());
        });

        return $this->aLaRuta($modelo)->with('ok', "Ruta «{$modelo->nombre}» actualizada correctamente.");
    }

    /**
     * Suspender y reactivar con el mismo botón.
     */
    public function estado(Request $request, int $ruta): RedirectResponse
    {
        Gate::authorize('rutas.eliminar');
        $empresaId = $this->empresaDeTrabajo($request);
        $activo = $request->boolean('activo');

        $modelo = $this->tenant->conEmpresa($empresaId, function () use ($request, $ruta, $activo) {
            $modelo = $this->rutaVisible($request->user(), $ruta);
            abort_unless($this->rutas->puede($request->user(), 'rutas.eliminar', $modelo), 403);
            $this->rutas->cambiarEstado($request->user(), $modelo, $activo);

            return $modelo;
        });

        return $this->aLaRuta($modelo)->with($activo ? 'ok' : 'aviso', $activo
            ? "Ruta «{$modelo->nombre}» reactivada."
            : "Ruta «{$modelo->nombre}» suspendida. Puedes reactivarla con el mismo botón cuando quieras.");
    }

    public function clonar(Request $request, int $ruta): RedirectResponse
    {
        Gate::authorize('rutas.crear');
        $empresaId = $this->empresaDeTrabajo($request);

        $copia = $this->tenant->conEmpresa($empresaId, function () use ($request, $ruta) {
            $modelo = $this->rutaVisible($request->user(), $ruta);
            abort_unless($this->rutas->puedeEnSede($request->user(), 'rutas.crear', $modelo->sede_id), 403);

            return $this->rutas->clonar($request->user(), $modelo);
        });

        return $this->aLaRuta($copia)->with('ok', "Ruta clonada como «{$copia->nombre}». Ajusta los horarios de la copia cuando quieras.");
    }

    /**
     * Itinerario de una ruta para imprimir (SEGCAT: ruta_itinerario_pdf.php),
     * ahora con todos sus horarios y los paraderos de cada uno.
     */
    public function itinerario(Request $request, int $ruta): View
    {
        Gate::authorize('rutas.imprimir');
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $ruta, $empresaId) {
            $modelo = $this->rutaVisible($request->user(), $ruta);
            abort_unless($this->rutas->puedeEnSede($request->user(), 'rutas.imprimir', $modelo->sede_id), 403);
            $modelo->load(['sede:id,nombre,zona_horaria,empresa_id', 'turno:id,nombre,hora_inicio,hora_fin', 'proveedor:id,nombre', 'horarios.paradas.paradero:id,nombre']);

            return view('padrones.rutas.itinerario', [
                'ruta' => $modelo,
                'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial'),
                'generado' => $this->rutas->ahoraEn($modelo->sede),
            ]);
        });
    }

    /**
     * Hoja del día para caseta (SEGCAT: ruta_imprimir_dia.php): las rutas
     * activas que salen ese día, por hora, en una hoja para Llegadas y otra
     * para Salidas, con una columna por paradero. ?fecha=AAAA-MM-DD (hoy si no viene).
     */
    /**
     * Hoja del día (opción de la hoja de horarios): solo lo que opera esa fecha.
     * Ronda 8 (RT-07/RT-08): es de consulta para caseta (rutas.ver) y avisa qué
     * horarios no operan ese día y qué rutas están suspendidas.
     */
    public function dia(Request $request, int $sede): View
    {
        Gate::authorize('rutas.ver');
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $sede, $empresaId) {
            $modelo = $this->sedeVisible($request->user(), $sede);
            abort_unless($this->rutas->puedeEnSede($request->user(), 'rutas.ver', $modelo->id), 403);

            $ahora = $this->rutas->ahoraEn($modelo);
            $texto = Entrada::texto($request->query('fecha', ''));
            $fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', $texto) && checkdate((int) substr($texto, 5, 2), (int) substr($texto, 8, 2), (int) substr($texto, 0, 4))
                ? CarbonImmutable::createFromFormat('!Y-m-d', $texto, $ahora->getTimezone())
                : $ahora->startOfDay();

            $activos = $this->horariosDeSede($modelo);
            [$hoy, $otrosDias] = $activos->partition(fn (RutaHorario $h) => $h->aplicaEn($fecha->dayOfWeekIso));

            return view('padrones.rutas.dia', [
                'sede' => $modelo,
                'fecha' => $fecha,
                'hoy' => $ahora->toDateString(),
                'generado' => $ahora,
                'hojas' => $this->hojasPorSentido($hoy->values()),
                'noOperan' => $otrosDias->values(),
                'suspendidas' => $this->rutasSuspendidas($modelo),
                'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial'),
            ]);
        });
    }

    /**
     * Ronda 8 (RT-07/RT-08): Hoja de horarios de la SEMANA (SEGCAT:
     * ruta_imprimir_dia.php era informativa de la semana completa). Todos
     * los horarios activos de la sede, cada uno con los días en que opera
     * (incluidos los horarios alternos de fin de semana o días sueltos),
     * agrupados como SEGCAT: todos los días, lunes a viernes, días sueltos y
     * fin de semana; dentro de cada grupo por hora. La consulta el Agente.
     */
    public function semana(Request $request, int $sede): View
    {
        Gate::authorize('rutas.ver');
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $sede, $empresaId) {
            $modelo = $this->sedeVisible($request->user(), $sede);
            abort_unless($this->rutas->puedeEnSede($request->user(), 'rutas.ver', $modelo->id), 403);
            $ahora = $this->rutas->ahoraEn($modelo);

            $prioridad = fn (RutaHorario $h) => match (Ruta::patronDias($h->dias)) {
                'L-D' => 0, 'L-V' => 1, 'S-D' => 3, default => 2
            };
            $horarios = $this->horariosDeSede($modelo)
                ->sortBy([fn ($a, $b) => $prioridad($a) <=> $prioridad($b), fn ($a, $b) => strcmp((string) $a->hora_inicio, (string) $b->hora_inicio)])
                ->values();

            return view('padrones.rutas.semana', [
                'sede' => $modelo,
                'hoy' => $ahora->toDateString(),
                'generado' => $ahora,
                'hojas' => $this->hojasPorSentido($horarios),
                'suspendidas' => $this->rutasSuspendidas($modelo),
                'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial'),
            ]);
        });
    }

    /**
     * Horarios de las rutas activas de la sede, con lo que pinta la hoja (sin consultas por fila).
     *
     * @return Collection<int, RutaHorario>
     */
    private function horariosDeSede(Sede $sede): Collection
    {
        return RutaHorario::query()
            ->whereHas('ruta', fn ($q) => $q->where('sede_id', $sede->id)->where('activo', true))
            ->with(['ruta.turno:id,nombre', 'ruta.proveedor:id,nombre', 'paradas.paradero:id,nombre'])
            ->orderBy('hora_inicio')->orderBy('id')
            ->get();
    }

    /**
     * Llegadas y Salidas, cada una con sus filas y una columna por paradero (en el orden en que aparecen).
     *
     * @param  Collection<int, RutaHorario>  $horarios
     * @return array<string, array{titulo: string, filas: Collection<int, RutaHorario>, columnas: array<int, ?string>}>
     */
    private function hojasPorSentido(Collection $horarios): array
    {
        $hojas = [];
        foreach (array_keys(Ruta::SENTIDOS) as $sentido) {
            $filas = $horarios->filter(fn ($h) => $h->ruta->sentido === $sentido)->values();
            $columnas = [];
            foreach ($filas as $h) {
                foreach ($h->paradas as $p) {
                    $columnas[$p->paradero_id] ??= $p->paradero?->nombre;
                }
            }
            $hojas[$sentido] = ['titulo' => $sentido === 'llegada' ? 'Llegadas' : 'Salidas', 'filas' => $filas, 'columnas' => $columnas];
        }

        return $hojas;
    }

    /**
     * Rutas suspendidas de la sede (no salen en la hoja; se mencionan al pie para que caseta sepa por qué).
     *
     * @return Collection<int, Ruta>
     */
    private function rutasSuspendidas(Sede $sede): Collection
    {
        return Ruta::where('sede_id', $sede->id)->where('activo', false)->orderBy('sentido')->orderBy('nombre')->get(['id', 'nombre', 'sentido']);
    }

    // ------------------------------------------------------------- Paraderos

    public function guardarParadero(Request $request, int $sede): RedirectResponse
    {
        Gate::authorize('rutas.crear');
        $empresaId = $this->empresaDeTrabajo($request);

        $paradero = $this->tenant->conEmpresa($empresaId, function () use ($request, $sede) {
            $modelo = $this->sedeVisible($request->user(), $sede);
            abort_unless($this->rutas->puedeEnSede($request->user(), 'rutas.crear', $modelo->id), 403);

            return $this->rutas->crearParadero($request->user(), $modelo, $request->input('nombre'));
        });

        return $this->alParadero($paradero)->with('ok', "Paradero «{$paradero->nombre}» agregado a la sede.");
    }

    public function actualizarParadero(Request $request, int $paradero): RedirectResponse
    {
        Gate::authorize('rutas.editar');
        $empresaId = $this->empresaDeTrabajo($request);

        $modelo = $this->tenant->conEmpresa($empresaId, function () use ($request, $paradero) {
            $modelo = $this->paraderoVisible($request->user(), $paradero);
            abort_unless($this->rutas->puede($request->user(), 'rutas.editar', $modelo), 403);

            return $this->rutas->actualizarParadero($request->user(), $modelo, $request->input('nombre'));
        });

        return $this->alParadero($modelo)->with('ok', "Paradero «{$modelo->nombre}» actualizado. Las rutas que lo usan ya muestran el nombre nuevo.");
    }

    public function estadoParadero(Request $request, int $paradero): RedirectResponse
    {
        Gate::authorize('rutas.eliminar');
        $empresaId = $this->empresaDeTrabajo($request);
        $activo = $request->boolean('activo');

        $modelo = $this->tenant->conEmpresa($empresaId, function () use ($request, $paradero, $activo) {
            $modelo = $this->paraderoVisible($request->user(), $paradero);
            abort_unless($this->rutas->puede($request->user(), 'rutas.eliminar', $modelo), 403);
            $this->rutas->cambiarEstadoParadero($request->user(), $modelo, $activo);

            return $modelo;
        });

        return $this->alParadero($modelo)->with($activo ? 'ok' : 'aviso', $activo
            ? "Paradero «{$modelo->nombre}» reactivado."
            : "Paradero «{$modelo->nombre}» desactivado: ya no se sugiere al capturar rutas. Las rutas que ya lo tenían no cambian.");
    }

    // ------------------------------------------------------------- Apoyo

    private function empresaDeTrabajo(Request $request): int
    {
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        return $empresaId;
    }

    /**
     * Sede activa de la empresa y dentro del alcance de "rutas.ver"; si no, 404.
     */
    private function sedeVisible(User $actor, int $id): Sede
    {
        $permitidas = $this->rutas->sedesPermitidas($actor, 'rutas.ver');
        $sede = Sede::where('activo', true)->when($permitidas !== null, fn ($q) => $q->whereIn('id', $permitidas))->find($id);
        abort_if($sede === null, 404);

        return $sede;
    }

    /**
     * Ruta de la empresa en una sede que el usuario ve; si no, 404.
     */
    private function rutaVisible(User $actor, int $id): Ruta
    {
        $ruta = Ruta::find($id);
        abort_if($ruta === null, 404);
        $ruta->setRelation('sede', $this->sedeVisible($actor, $ruta->sede_id));

        return $ruta;
    }

    private function paraderoVisible(User $actor, int $id): Paradero
    {
        $paradero = Paradero::find($id);
        abort_if($paradero === null, 404);
        $paradero->setRelation('sede', $this->sedeVisible($actor, $paradero->sede_id));

        return $paradero;
    }

    private function aLaRuta(Ruta $ruta): RedirectResponse
    {
        return redirect()->to(route('rutas.sede', ['sede' => $ruta->sede_id, 'tab' => $ruta->esLlegada() ? 'llegadas' : 'salidas']).'#ruta-'.$ruta->id);
    }

    private function alParadero(Paradero $paradero): RedirectResponse
    {
        return redirect()->to(route('rutas.sede', ['sede' => $paradero->sede_id, 'tab' => 'paraderos']).'#paradero-'.$paradero->id);
    }
}
