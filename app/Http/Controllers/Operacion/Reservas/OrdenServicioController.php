<?php

namespace App\Http\Controllers\Operacion\Reservas;

use App\Http\Controllers\Controller;
use App\Models\DepartamentoResponsable;
use App\Models\Reserva;
use App\Models\ReservaOrden;
use App\Services\Reservas\FlujoReservas;
use App\Services\Reservas\PendientesReservas;
use App\Services\Reservas\ResponsablesReservas;
use App\Services\Reservas\TextosReservas;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Órdenes de servicio: lo que cada departamento debe preparar para las
 * reservas confirmadas (qué, cuánto, cuándo, dónde y montaje) y el botón
 * «Listo». Las ve quien es responsable, suplente o delegado del departamento;
 * quien configura las reservas ve todas las de su alcance.
 */
class OrdenServicioController extends Controller
{
    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly PendientesReservas $pendientes,
        private readonly ResponsablesReservas $responsables,
        private readonly FlujoReservas $flujo,
        private readonly TextosReservas $textos,
    ) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        if ($empresaId === null) {
            return view('operacion.reservas.index', ['sinEmpresa' => true]);
        }

        return $this->tenant->conEmpresa($empresaId, function () use ($actor) {
            $esResponsable = DepartamentoResponsable::where('user_id', $actor->id)->exists();
            abort_unless($esResponsable || $actor->can('reservas.configurar') || $actor->can('reservas.ver') || $actor->can('reservas.crear'), 403);
            $pendientes = $this->pendientes->ordenesPara($actor, true);
            // Listas recientes (últimos 7 días y próximas) de sus departamentos, para ver el avance
            $listas = ReservaOrden::with(['reserva.area.espacio', 'reserva.items', 'reserva.montaje', 'departamento:id,nombre', 'listaPor:id,name'])
                ->where('estado', ReservaOrden::LISTA)
                ->whereHas('reserva', fn ($q) => $q->where('estado', Reserva::CONFIRMADA)->where('fin', '>=', now()->subDays(7)))
                ->orderByDesc('lista_en')->limit(100)->get()
                ->filter(fn ($o) => $this->responsables->atiendeDepartamento($actor, (int) $o->departamento_id, (int) $o->reserva->sede_id, (int) $o->empresa_id)
                    || $actor->can('reservas.configurar', $o->reserva))->values();

            return view('operacion.reservas.ordenes', [
                'pendientes' => $pendientes, 'listas' => $listas, 'textos' => $this->textos,
                'puede' => ['crear' => $actor->can('reservas.crear'), 'ver' => $actor->can('reservas.ver'), 'aprobar' => $actor->can('reservas.aprobar'),
                    'configurar' => $actor->can('reservas.configurar'), 'exportar' => $actor->can('reservas.exportar')],
            ]);
        });
    }

    public function listo(Request $request, int $orden): RedirectResponse
    {
        $actor = $request->user();
        abort_if(! $this->tenant->activo(), 404);
        $orden = ReservaOrden::with('reserva')->findOrFail($orden);
        if (! $this->responsables->atiendeDepartamento($actor, (int) $orden->departamento_id, (int) $orden->reserva->sede_id, (int) $orden->empresa_id)
            && ! $actor->can('reservas.configurar', $orden->reserva)) {
            // Quien usa Reservas pero ya no atiende ese departamento recibe la explicación; los demás, 403
            abort_unless($actor->can('reservas.ver') || $actor->can('reservas.crear'), 403);

            return back()->withErrors(['orden' => 'Ya no atiendes este departamento: pide a quien configura los departamentos que te nombre responsable.']);
        }
        $this->flujo->marcarLista($actor, $orden, $request->input('comentario'));

        return redirect()->to($request->input('volver') === 'reserva' ? route('reservas.show', $orden->reserva_id) : route('reservas.ordenes'))
            ->with('ok', 'Marcado como listo. Avisamos a quien reservó y al responsable del área.')
            ->with('siguientes', [['Ver órdenes pendientes', route('reservas.ordenes'), 'bi-clipboard-check']]);
    }
}
