<?php

namespace App\Http\Controllers\Operacion\Reservas;

use App\Http\Controllers\Controller;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Espacio;
use App\Models\ReservaArea;
use App\Models\ReservaMontaje;
use App\Models\ReservaServicio;
use App\Models\ReservaTipoUso;
use App\Models\Sede;
use App\Models\User;
use App\Services\Permisos\Autorizador;
use App\Services\Reservas\AjustesReservas;
use App\Services\Reservas\CatalogosReservas;
use App\Services\Reservas\NivelesReservas;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use App\Support\Terminos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Configuración de Reservas de áreas (reservas.configurar): qué espacios de
 * Zonas y edificios se reservan y cómo (aforo, montajes, horario, montaje y
 * desmontaje, tarifas, foto, responsable y suplente), los catálogos de la
 * empresa (montajes, tipos de uso y servicios con su departamento) y los
 * ajustes generales (responsable general, IVA, recordatorio y escalamiento).
 * Catálogos y ajustes piden alcance de empresa; las áreas, el de su sede.
 */
class ConfiguracionReservasController extends Controller
{
    /** Secciones de Configuración con «Mostrar quitadas / dadas de baja». */
    public const SECCIONES = ['areas', 'montajes', 'tipos', 'servicios'];

    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly CatalogosReservas $catalogos,
        private readonly AjustesReservas $ajustes,
        private readonly Autorizador $autorizador,
    ) {}

    /**
     * Contenido del diálogo «Agregar área reservable», pedido al abrirlo: con miles
     * de habitaciones la lista pesa megas y no debe viajar en cada pantalla.
     * ?sede= limita a una sede (debe estar en su alcance de reservas.configurar).
     */
    public function opcionesAgregar(Request $request): View
    {
        Gate::authorize('reservas.configurar');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        abort_if($empresaId === null, 404);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $actor, $empresaId) {
            $sedes = $this->autorizador->sedesPermitidas($actor, 'reservas.configurar');
            $sedeId = $request->integer('sede') ?: null;
            if ($sedeId !== null) {
                abort_unless(Sede::whereKey($sedeId)->exists() && ($sedes === null || in_array($sedeId, $sedes, true)), 403);
            }

            return view('operacion.reservas._opciones-agregar', [
                'opciones' => $this->catalogos->opcionesParaAgregar($sedeId !== null ? [$sedeId] : $sedes, Empresa::find($empresaId)),
                'sedeId' => $sedeId,
            ]);
        });
    }

    public function index(Request $request): View
    {
        Gate::authorize('reservas.configurar');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        if ($empresaId === null) {
            return view('operacion.reservas.sedes', ['sinEmpresa' => true]);
        }

        // «Mostrar quitadas / dadas de baja»: por omisión cada sección solo muestra lo activo
        $ver = collect((array) $request->query('ver', []))->filter(fn ($v) => is_string($v))
            ->intersect(self::SECCIONES)->unique()->values()->all();

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $empresaId, $ver) {
            $this->catalogos->asegurar();
            $sedes = $this->autorizador->sedesPermitidas($actor, 'reservas.configurar');
            $todas = ReservaArea::with(['espacio:id,nombre,activo,nivel,ruta', 'sede:id,nombre', 'responsable:id,name', 'montajes'])
                ->when($sedes !== null, fn ($q) => $q->whereIn('sede_id', $sedes))->get()
                ->sortBy(fn ($a) => $a->sede?->nombre.' '.$a->espacio?->ruta)->values();
            $areas = in_array('areas', $ver, true) ? $todas : $todas->where('reservable', true)->values();
            $catalogos = [
                'montajes' => ReservaMontaje::orderBy('orden')->orderBy('nombre')->get(),
                'tipos' => ReservaTipoUso::orderBy('orden')->orderBy('nombre')->get(),
                'servicios' => ReservaServicio::with('departamento:id,nombre')->orderBy('categoria')->orderBy('orden')->orderBy('nombre')->get(),
            ];
            $ocultas = ['areas' => $todas->where('reservable', false)->count()]
                + array_map(fn ($lista) => $lista->where('activo', false)->count(), $catalogos);
            foreach ($catalogos as $clave => $lista) {
                if (! in_array($clave, $ver, true)) {
                    $catalogos[$clave] = $lista->where('activo', true)->values();
                }
            }
            $empresaCompleta = $this->autorizador->alcanceDeEmpresa($actor, 'reservas.configurar');

            return view('operacion.reservas.configuracion', [
                'areas' => $areas,
                'rutas' => self::rutas($areas),
                'interiores' => $this->conteoInteriores($areas),
                'eliminar' => $this->catalogos->resumenParaEliminar($areas->where('reservable', true)->values()),
                // La lista para «Agregar área reservable» se pide al abrir el diálogo (opcionesAgregar)
                'opciones' => session('errors')?->has('espacio_id') ? $this->catalogos->opcionesParaAgregar($sedes, Empresa::find($empresaId)) : null,
                'empresaCompleta' => $empresaCompleta,
                'montajes' => $catalogos['montajes'],
                'tipos' => $catalogos['tipos'],
                'servicios' => $catalogos['servicios'],
                'usados' => $empresaCompleta ? $this->catalogos->usados() : [],
                'ver' => $ver,
                'ocultas' => $ocultas,
                'departamentos' => Departamento::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
                'empresa' => Empresa::find($empresaId),
                'puede' => ['crear' => $actor->can('reservas.crear'), 'ver' => $actor->can('reservas.ver'), 'aprobar' => $actor->can('reservas.aprobar'),
                    'configurar' => true, 'exportar' => $actor->can('reservas.exportar')],
            ]);
        });
    }

    /**
     * Dirección de Configuración que muestra (o deja de mostrar) lo quitado o
     * dado de baja de una sección, conservando las demás.
     *
     * @param  list<string>  $ver
     */
    public static function urlVer(array $ver, string $seccion, string $ancla): string
    {
        $nuevo = in_array($seccion, $ver, true) ? array_values(array_diff($ver, [$seccion])) : [...$ver, $seccion];

        return route('reservas.configuracion', $nuevo === [] ? [] : ['ver' => $nuevo]).'#'.$ancla;
    }

    /**
     * «Agregar área reservable»: uno o varios espacios de Zonas y edificios
     * (casillas espacios[]; espacio_id sigue sirviendo para uno solo) y, para
     * las zonas con espacios interiores, el modo (toda la zona o por sus
     * espacios interiores). Cada espacio pide reservas.configurar en su sede.
     */
    public function marcar(Request $request): RedirectResponse
    {
        Gate::authorize('reservas.configurar');
        abort_if(! $this->tenant->activo(), 404);
        $ids = collect((array) $request->input('espacios', []))->push($request->input('espacio_id'))
            ->filter(fn ($v) => is_numeric($v))->map(fn ($v) => (int) $v)->unique()->take(500)->values();
        if ($ids->isEmpty()) {
            return back()->withErrors(['espacio_id' => 'Marca al menos un espacio.'])->withInput();
        }
        $espacios = Espacio::whereIn('id', $ids)->get();
        if ($espacios->count() !== $ids->count() || $espacios->contains(fn ($e) => ! $request->user()->can('reservas.configurar', $e))) {
            return back()->withErrors(['espacio_id' => 'Elige un espacio de tu sede.'])->withInput();
        }
        $modo = $request->input('modo') === ReservaArea::INTERIORES ? ReservaArea::INTERIORES : ReservaArea::COMPLETA;
        $hecho = $this->catalogos->marcarVarios($request->user(), $espacios, $modo);
        $areas = $hecho['areas'];
        $sede = $request->integer('sede') ?: null;
        $volver = $sede !== null ? route('reservas.index', ['sede' => $sede]) : route('reservas.configuracion');

        if (count($areas) === 1 && $hecho['interiores'] === 0) {
            $area = $areas[0];

            return redirect()->route('reservas.configuracion.area', $area->id)
                ->with('ok', "«{$area->nombre()}» ya se puede reservar. Completa su aforo, montajes, horario y tarifas (todo es opcional).")
                ->with('siguientes', [['Volver a las áreas', $volver, 'bi-arrow-left']]);
        }
        $texto = count($areas) === 1 ? '«'.$areas[0]->nombre().'» ya se puede reservar' : count($areas).' áreas ya se pueden reservar';
        if ($hecho['interiores'] > 0) {
            $texto .= ' con '.$hecho['interiores'].($hecho['interiores'] === 1 ? ' espacio interior' : ' espacios interiores').' (cada uno empieza con la configuración de su zona)';
        }

        return redirect()->to($volver)->with('ok', $texto.'. Completa su aforo, horario y tarifas desde el botón Editar (lápiz) de cada ficha.')
            ->with('siguientes', [['Editar '.$areas[0]->nombre(), route('reservas.configuracion.area', $areas[0]->id), 'bi-pencil']]);
    }

    /**
     * «Eliminar de reservas» (botón del bote de basura en las fichas de áreas).
     * Nunca toca Zonas y edificios (ver CatalogosReservas::quitar): con
     * reservas próximas no se elimina; sin ninguna reserva se borra su
     * configuración; con solo reservas pasadas se archiva con su historial y
     * deja de verse (se ve con «Mostrar quitadas»).
     */
    public function quitar(Request $request, int $area): RedirectResponse
    {
        $area = $this->cargarArea($request, $area);
        $area->load('espacio');
        $nombre = $area->nombre();
        $sede = (int) $area->sede_id;
        $volver = $request->input('volver') === 'sede' ? route('reservas.index', ['sede' => $sede]) : route('reservas.configuracion');
        try {
            $r = $this->catalogos->quitar($request->user(), $area);
        } catch (ValidationException $e) {
            return redirect()->to($volver)->with('error', "No se eliminó «{$nombre}» de reservas: ".lcfirst((string) $e->validator->errors()->first('quitar')))
                ->with('siguiente_paso', ['texto' => 'Ver su calendario', 'url' => route('reservas.calendario', ['area' => $area->id, 'sede' => $sede]), 'icono' => 'bi-calendar3']);
        }
        $detalle = match (true) {
            $r['desactivadas'] > 0 && $r['borradas'] > 0 => ' Los espacios con reservas pasadas se guardaron con su historial; los ves con «Mostrar quitadas».',
            $r['desactivadas'] > 0 => ' Como tiene reservas pasadas, se guardó con su historial y su configuración; la ves con «Mostrar quitadas» y puedes reactivarla.',
            default => '',
        };
        $total = $r['desactivadas'] + $r['borradas'];
        $siguientes = $r['desactivadas'] > 0
            ? [['Ver áreas quitadas', self::urlVer([], 'areas', 'areas'), 'bi-eye']]
            : [['Agregar área reservable', route('reservas.configuracion').'#areas', 'bi-plus-circle']];

        return redirect()->to($volver)
            ->with('ok', "«{$nombre}»".($total > 1 ? ' y sus espacios interiores se eliminaron' : ' se eliminó').' de reservas. Sigue en Zonas y edificios.'.$detalle)
            ->with('siguientes', $siguientes);
    }

    public function area(Request $request, int $area): View
    {
        $area = $this->cargarArea($request, $area);
        $this->catalogos->asegurar();

        return view('operacion.reservas.area', [
            'area' => $area->load(['espacio', 'sede', 'montajes']),
            'interiores' => $this->catalogos->interioresDe($area)->where('reservable', true)->count(),
            'montajes' => ReservaMontaje::where('activo', true)->orWhereIn('id', $area->montajes->pluck('id'))->orderBy('orden')->orderBy('nombre')->get(),
            'usuarios' => $this->usuarios((int) $area->empresa_id),
            'general' => $this->ajustes->de((int) $area->empresa_id),
            'moneda' => (string) (Empresa::whereKey($area->empresa_id)->value('moneda') ?: 'MXN'),
        ]);
    }

    public function guardarArea(Request $request, int $area): RedirectResponse
    {
        $area = $this->cargarArea($request, $area);
        $request->validate(['foto' => ['nullable', 'file', 'max:5120']], ['foto.max' => 'La foto debe pesar menos de 5 MB.']);
        $this->catalogos->guardarArea($request->user(), $area, $request->except('foto'), $request->file('foto'));
        $copiadas = $request->boolean('copiar_interiores') ? $this->catalogos->copiarAInteriores($request->user(), $area->refresh()) : 0;

        // «Reservar esta área» solo si de verdad se ofrece: una quitada (o una zona
        // que se reserva por sus espacios interiores) no aparece en Nueva reserva.
        $area->loadMissing('espacio');
        $seOfrece = $area->reservable && ! $area->porInteriores() && (bool) $area->espacio?->activo;
        $siguientes = $seOfrece
            ? [['Reservar esta área', route('reservas.create', ['area' => $area->id]), 'bi-calendar-plus'],
                ['Volver a la configuración', route('reservas.configuracion'), 'bi-arrow-left']]
            : (! $area->reservable
                ? [['Ver áreas quitadas para reactivarla', self::urlVer([], 'areas', 'areas'), 'bi-eye']]
                : [['Volver a la configuración', route('reservas.configuracion'), 'bi-arrow-left']]);

        return redirect()->route('reservas.configuracion.area', $area->id)
            ->with('ok', 'Configuración del área guardada.'.($copiadas > 0 ? " También se copió a sus {$copiadas} espacios interiores." : '')
                .(! $area->reservable ? ' Sigue quitada de reservas: no se ofrece hasta que la reactives.' : ''))
            ->with('siguientes', $siguientes);
    }

    public function estadoArea(Request $request, int $area): RedirectResponse
    {
        $area = $this->cargarArea($request, $area);
        $reservable = $request->boolean('reservable');
        $this->catalogos->cambiarReservable($request->user(), $area, $reservable);

        return redirect()->route('reservas.configuracion')->with('ok', $reservable
            ? "«{$area->nombre()}» se puede reservar de nuevo."
            : "«{$area->nombre()}» ya no se puede reservar. Sus reservas ya hechas se conservan.");
    }

    public function crearCatalogo(Request $request): RedirectResponse
    {
        $this->exigirEmpresa($request);
        $catalogo = (string) $request->input('catalogo');
        abort_unless(array_key_exists($catalogo, CatalogosReservas::CATALOGOS), 404);
        $registro = $this->catalogos->guardarCatalogo($request->user(), $catalogo, null, $request->all());

        return redirect()->to(route('reservas.configuracion').'#cat-'.$catalogo)->with('ok', CatalogosReservas::CATALOGOS[$catalogo][1]." «{$registro->nombre}» guardado. Agrega otro si lo necesitas.");
    }

    public function actualizarCatalogo(Request $request, string $catalogo, int $id): RedirectResponse
    {
        $this->exigirEmpresa($request);
        $registro = CatalogosReservas::CATALOGOS[$catalogo][0]::findOrFail($id);
        $this->catalogos->guardarCatalogo($request->user(), $catalogo, $registro, $request->all());

        return redirect()->to(route('reservas.configuracion').'#cat-'.$catalogo)->with('ok', "«{$registro->nombre}» guardado.");
    }

    public function estadoCatalogo(Request $request, string $catalogo, int $id): RedirectResponse
    {
        $this->exigirEmpresa($request);
        $registro = CatalogosReservas::CATALOGOS[$catalogo][0]::findOrFail($id);
        $activo = $request->boolean('activo');
        $this->catalogos->cambiarEstado($request->user(), $registro, $activo);

        return redirect()->to(route('reservas.configuracion').'#cat-'.$catalogo)
            ->with('ok', $activo ? "«{$registro->nombre}» reactivado." : "«{$registro->nombre}» dado de baja: ya no se ofrece en las reservas nuevas.");
    }

    /**
     * «Eliminar» definitivo de un montaje, tipo de uso o servicio: solo si
     * nunca se usó en una reserva ni (los montajes) en un área; si se usó,
     * explica por qué y ofrece darlo de baja.
     */
    public function eliminarCatalogo(Request $request, string $catalogo, int $id): RedirectResponse
    {
        $this->exigirEmpresa($request);
        $registro = CatalogosReservas::CATALOGOS[$catalogo][0]::findOrFail($id);
        $volver = route('reservas.configuracion').'#cat-'.$catalogo;
        try {
            $this->catalogos->eliminarCatalogo($request->user(), $catalogo, $registro);
        } catch (ValidationException $e) {
            return redirect()->to($volver)->with('error', "No se eliminó «{$registro->nombre}»: ".$e->validator->errors()->first('eliminar'));
        }

        return redirect()->to($volver)->with('ok', CatalogosReservas::CATALOGOS[$catalogo][1]." «{$registro->nombre}» eliminado.");
    }

    /**
     * «Cargar servicios sugeridos de hotel»: los servicios de la hoja de orden
     * de evento por departamento y los montajes Tipo U, Escuela, Auditorio y
     * Banquete. Solo al pulsar el botón; idempotente; no crea departamentos.
     */
    public function sugeridosHotel(Request $request): RedirectResponse
    {
        $this->exigirEmpresa($request);
        // Giros: la lista sugerida es la orden de evento de un hotel; los demás giros dan de alta sus propios servicios
        abort_unless(Terminos::deEmpresaId($this->tenant->empresaId())->esHotel(), 404);
        $r = $this->catalogos->cargarSugeridosHotel($request->user());
        $volver = route('reservas.configuracion').'#cat-servicios';
        if ($r['servicios'] === 0 && $r['montajes'] === 0) {
            return redirect()->to($volver)->with('ok', 'Tu catálogo ya tenía todos los servicios y montajes sugeridos: no se agregó nada.');
        }
        $texto = 'Se agregaron '.$r['servicios'].($r['servicios'] === 1 ? ' servicio' : ' servicios').' y '.$r['montajes'].($r['montajes'] === 1 ? ' montaje' : ' montajes').' sugeridos, sin costo.';
        if ($r['sin_departamento'] > 0) {
            $texto .= ' Asigna el departamento de '.$r['sin_departamento'].($r['sin_departamento'] === 1 ? ' servicio' : ' servicios')
                .' (no encontramos: '.implode(', ', $r['grupos_sin_departamento']).'): toca el lápiz de cada uno.';
        }
        if ($r['montajes'] > 0) {
            $texto .= ' Para ofrecer los montajes nuevos, márcalos en cada área con su aforo.';
        }

        return redirect()->to($volver)->with('ok', $texto)
            ->with('siguientes', [['Ver los servicios', $volver, 'bi-cup-hot'], ['Configurar las áreas', route('reservas.configuracion').'#areas', 'bi-door-open']]);
    }

    /** Ajustes generales (mismo formulario en Configuración → Reservas de áreas). */
    public function ajustes(Request $request): RedirectResponse
    {
        $this->exigirEmpresa($request);
        $empresa = Empresa::findOrFail($this->tenant->empresaId());
        $this->ajustes->guardar($request->user(), $empresa, $request->all());
        $desde = $request->input('desde') === 'configuracion' ? route('configuracion.index').'#sec-reservas' : route('reservas.configuracion').'#ajustes';

        return redirect()->to($desde)->with('ok', 'Ajustes de reservas guardados.');
    }

    // ------------------------------------------------------------------ Apoyo

    private function cargarArea(Request $request, int $id): ReservaArea
    {
        Gate::authorize('reservas.configurar');
        abort_if(! $this->tenant->activo(), 404);
        $area = ReservaArea::findOrFail($id);
        abort_unless($request->user()->can('reservas.configurar', $area), 403);

        return $area;
    }

    /**
     * Dónde está cada área («Torre A › Piso 1»), con una sola consulta.
     *
     * @param  Collection<int, ReservaArea>  $areas
     * @return array<int, string>
     */
    public static function rutas(Collection $areas): array
    {
        $ids = $areas->flatMap(fn ($a) => $a->espacio?->idsAncestros() ?? [])->unique()->values();
        $nombres = $ids->isEmpty() ? collect() : Espacio::whereIn('id', $ids)->pluck('nombre', 'id');

        return $areas->mapWithKeys(fn ($a) => [(int) $a->id => collect($a->espacio?->idsAncestros() ?? [])->map(fn ($id) => $nombres[$id] ?? null)->filter()->join(' › ')])->all();
    }

    /**
     * Cuántas áreas reservables tiene dentro cada área (0 = ninguna).
     *
     * @param  Collection<int, ReservaArea>  $areas
     * @return array<int, int>
     */
    private function conteoInteriores(Collection $areas): array
    {
        $niveles = app(NivelesReservas::class);
        $activas = $areas->where('reservable', true)->values();
        $padres = $niveles->padres($activas);

        return $activas->mapWithKeys(fn ($a) => [(int) $a->id => count($niveles->descendientes($padres, (int) $a->id))])->all();
    }

    private function exigirEmpresa(Request $request): void
    {
        Gate::authorize('reservas.configurar');
        abort_if(! $this->tenant->activo(), 404);
        abort_unless($this->autorizador->alcanceDeEmpresa($request->user(), 'reservas.configurar'), 403,
            'Los catálogos y los ajustes de reservas son de toda la empresa: pide a tu administrador que los cambie.');
    }

    /**
     * @return Collection<int, User>
     */
    private function usuarios(int $empresaId): Collection
    {
        return User::where('empresa_id', $empresaId)->where('activo', true)->where('es_superadmin', false)->orderBy('name')->get(['id', 'name']);
    }
}
