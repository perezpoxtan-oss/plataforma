<?php

namespace App\Http\Controllers\Operacion\Reservas;

use App\Http\Controllers\Controller;
use App\Models\DepartamentoResponsable;
use App\Models\Reserva;
use App\Models\ReservaAprobacion;
use App\Services\Reservas\EsperaReservas;
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
 * Por aprobar: las aprobaciones de reservas que le tocan a la persona
 * (responsable del área o del departamento, su delegado o a quien se le
 * avisó) con reloj y color; quien tiene reservas.aprobar ve además todas las
 * de su alcance. Responder: Aprobar, Rechazar (con motivo) o pedir un cambio.
 */
class AprobacionReservaController extends Controller
{
    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly PendientesReservas $pendientes,
        private readonly FlujoReservas $flujo,
        private readonly EsperaReservas $espera,
        private readonly TextosReservas $textos,
    ) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        if ($empresaId === null) {
            return view('operacion.reservas.index', ['sinEmpresa' => true]);
        }
        $this->espera->escalarSiToca($empresaId);

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $empresaId) {
            $mias = $this->pendientes->aprobacionesPara($actor);
            $todas = $actor->can('reservas.aprobar') ? $this->pendientes->aprobacionesPara($actor, true)->reject(fn ($a) => $mias->contains('id', $a->id))->values() : collect();
            abort_if($mias->isEmpty() && ! $actor->can('reservas.aprobar') && ! $actor->can('reservas.ver') && ! $actor->can('reservas.crear')
                && ! DepartamentoResponsable::where('user_id', $actor->id)->exists(), 403);
            $umbrales = $this->espera->umbrales($empresaId);

            return view('operacion.reservas.aprobaciones', [
                'mias' => $mias, 'todas' => $todas, 'textos' => $this->textos, 'umbrales' => $umbrales,
                'relojes' => $mias->merge($todas)->mapWithKeys(fn ($a) => [$a->id => $this->espera->reloj($a, $umbrales)])->all(),
                'puede' => ['crear' => $actor->can('reservas.crear'), 'ver' => $actor->can('reservas.ver'), 'aprobar' => $actor->can('reservas.aprobar'),
                    'configurar' => $actor->can('reservas.configurar'), 'exportar' => $actor->can('reservas.exportar')],
            ]);
        });
    }

    public function responder(Request $request, int $aprobacion): RedirectResponse
    {
        $actor = $request->user();
        abort_if(! $this->tenant->activo(), 404);
        $a = ReservaAprobacion::with('reserva')->findOrFail($aprobacion);
        if (! $actor->can('reservas.aprobar', $a->reserva) && ! app(ResponsablesReservas::class)->leToca($actor, $a, $a->reserva)) {
            // Quien usa Reservas pero ya no es responsable (lo cambiaron mientras tenía la pantalla abierta) recibe la explicación
            abort_unless($actor->can('reservas.ver') || $actor->can('reservas.crear'), 403);

            return back()->withErrors(['respuesta' => 'Ya no te toca responder esta aprobación: pide a quien configura las reservas que te nombre responsable.']);
        }
        $reserva = $this->flujo->responder($actor, $a, (string) $request->input('respuesta'), $request->input('comentario'));

        $mensaje = match ($reserva->estado) {
            Reserva::CONFIRMADA => "Aprobada. Con eso la reserva {$reserva->folioTexto()} quedó confirmada y avisamos a quien reservó y a los departamentos.",
            Reserva::RECHAZADA => "Reserva {$reserva->folioTexto()} rechazada. Avisamos a quien la hizo con tu motivo.",
            Reserva::CAMBIO_PEDIDO => "Pediste un cambio. La reserva {$reserva->folioTexto()} regresó a quien la hizo para que la modifique.",
            default => 'Aprobada. La reserva sigue esperando la aprobación de las demás partes.',
        };
        $volver = $request->input('volver') === 'bandeja' ? route('reservas.aprobaciones') : route('reservas.show', $reserva->id);

        return redirect()->to($volver)->with('ok', $mensaje)
            ->with('siguientes', [['Ver lo que falta por aprobar', route('reservas.aprobaciones'), 'bi-calendar-check']]);
    }
}
