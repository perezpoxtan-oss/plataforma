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
use App\Models\User;
use App\Services\Permisos\Autorizador;
use App\Services\Reservas\AjustesReservas;
use App\Services\Reservas\CatalogosReservas;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
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
    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly CatalogosReservas $catalogos,
        private readonly AjustesReservas $ajustes,
        private readonly Autorizador $autorizador,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('reservas.configurar');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        if ($empresaId === null) {
            return view('operacion.reservas.sedes', ['sinEmpresa' => true]);
        }

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $empresaId) {
            $this->catalogos->asegurar();
            $sedes = $this->autorizador->sedesPermitidas($actor, 'reservas.configurar');
            $areas = ReservaArea::with(['espacio:id,nombre,activo,nivel,ruta', 'sede:id,nombre', 'responsable:id,name', 'montajes'])
                ->when($sedes !== null, fn ($q) => $q->whereIn('sede_id', $sedes))->get()
                ->sortBy(fn ($a) => $a->sede?->nombre.' '.$a->nombre())->values();
            $espacios = Espacio::with('sede:id,nombre')->where('activo', true)->whereIn('nivel', CatalogosReservas::NIVELES)
                ->whereNotIn('id', $areas->pluck('espacio_id'))
                ->when($sedes !== null, fn ($q) => $q->whereIn('sede_id', $sedes))
                ->orderBy('sede_id')->orderBy('ruta')->limit(2000)->get(['id', 'sede_id', 'nombre', 'nivel', 'ruta', 'padre_id']);
            $nombres = Espacio::whereIn('id', $espacios->flatMap(fn ($e) => $e->idsAncestros())->unique())->pluck('nombre', 'id');

            return view('operacion.reservas.configuracion', [
                'areas' => $areas,
                'espacios' => $espacios->map(fn ($e) => ['id' => $e->id, 'sede' => $e->sede?->nombre,
                    'texto' => collect($e->idsAncestros())->map(fn ($id) => $nombres[$id] ?? null)->filter()->push($e->nombre)->join(' › ')])->values(),
                'empresaCompleta' => $this->autorizador->alcanceDeEmpresa($actor, 'reservas.configurar'),
                'montajes' => ReservaMontaje::orderBy('orden')->orderBy('nombre')->get(),
                'tipos' => ReservaTipoUso::orderBy('orden')->orderBy('nombre')->get(),
                'servicios' => ReservaServicio::with('departamento:id,nombre')->orderBy('categoria')->orderBy('orden')->orderBy('nombre')->get(),
                'departamentos' => Departamento::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
                'empresa' => Empresa::find($empresaId),
                'puede' => ['crear' => $actor->can('reservas.crear'), 'ver' => $actor->can('reservas.ver'), 'aprobar' => $actor->can('reservas.aprobar'),
                    'configurar' => true, 'exportar' => $actor->can('reservas.exportar')],
            ]);
        });
    }

    public function marcar(Request $request): RedirectResponse
    {
        Gate::authorize('reservas.configurar');
        abort_if(! $this->tenant->activo(), 404);
        $espacio = Espacio::find((int) $request->input('espacio_id'));
        if ($espacio === null || ! $request->user()->can('reservas.configurar', $espacio)) {
            return back()->withErrors(['espacio_id' => 'Elige un espacio de tu sede.']);
        }
        $area = $this->catalogos->marcarReservable($request->user(), $espacio);

        return redirect()->route('reservas.configuracion.area', $area->id)
            ->with('ok', "«{$espacio->nombre}» ya se puede reservar. Completa su aforo, montajes, horario y tarifas (todo es opcional).");
    }

    public function area(Request $request, int $area): View
    {
        $area = $this->cargarArea($request, $area);
        $this->catalogos->asegurar();

        return view('operacion.reservas.area', [
            'area' => $area->load(['espacio', 'sede', 'montajes']),
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

        return redirect()->route('reservas.configuracion.area', $area->id)->with('ok', 'Configuración del área guardada.')
            ->with('siguientes', [
                ['Reservar esta área', route('reservas.create', ['area' => $area->id]), 'bi-calendar-plus'],
                ['Volver a la configuración', route('reservas.configuracion'), 'bi-arrow-left'],
            ]);
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
