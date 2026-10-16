<?php

namespace App\Http\Controllers\Operacion\Reservas;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\Sede;
use App\Services\Reservas\AdministradorReservas;
use App\Services\Reservas\InformesReservas;
use App\Support\Csv;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Informes de Reservas de áreas: ocupación por área, reservas por tipo de uso
 * e ingresos de eventos para clientes (reservas.ver; exportar a Excel con
 * reservas.exportar).
 */
class InformeReservasController extends Controller
{
    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly InformesReservas $informes,
        private readonly AdministradorReservas $reservas,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('reservas.ver');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        if ($empresaId === null) {
            return view('operacion.reservas.index', ['sinEmpresa' => true]);
        }
        [$desde, $hasta, $sede, $sedes] = $this->filtros($request, $empresaId);

        return view('operacion.reservas.informes', [
            'datos' => $this->tenant->conEmpresa($empresaId, fn () => $this->informes->calcular($actor, $desde, $hasta, $sede)),
            'desde' => $desde, 'hasta' => $hasta->subDay(), 'sede' => $sede, 'sedes' => $sedes,
            'puede' => ['crear' => $actor->can('reservas.crear'), 'ver' => true, 'aprobar' => $actor->can('reservas.aprobar'),
                'configurar' => $actor->can('reservas.configurar'), 'exportar' => $actor->can('reservas.exportar')],
        ]);
    }

    public function exportar(Request $request): StreamedResponse
    {
        Gate::authorize('reservas.exportar');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        abort_if($empresaId === null, 404);
        [$desde, $hasta, $sede] = $this->filtros($request, $empresaId);
        $datos = $this->tenant->conEmpresa($empresaId, fn () => $this->informes->calcular($actor, $desde, $hasta, $sede));
        $periodo = $desde->format('d/m/Y').' al '.$hasta->subDay()->format('d/m/Y');

        return response()->streamDownload(function () use ($datos, $periodo) {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\xEF\xBB\xBF");
            Csv::fila($salida, ['Reservas de áreas', 'Periodo', $periodo]);
            Csv::fila($salida, []);
            Csv::fila($salida, ['Ocupación por área', 'Reservas', 'Horas reservadas', 'Horas disponibles', 'Ocupación (%)']);
            foreach ($datos['ocupacion'] as $f) {
                Csv::fila($salida, [$f['area'], $f['reservas'], $f['horas'], $f['disponibles'], $f['porcentaje'] ?? '']);
            }
            Csv::fila($salida, []);
            Csv::fila($salida, ['Reservas por tipo', 'Total', 'Para clientes', 'Uso interno']);
            foreach ($datos['tipos'] as $f) {
                Csv::fila($salida, [$f['tipo'], $f['reservas'], $f['clientes'], $f['internas']]);
            }
            Csv::fila($salida, []);
            Csv::fila($salida, ['Ingresos de eventos para clientes', 'Eventos', 'Subtotal', 'IVA', 'Total', 'Cortesías', 'Moneda']);
            foreach ($datos['ingresos'] as $f) {
                Csv::fila($salida, [$f['area'], $f['eventos'], $f['subtotal'], $f['iva'], $f['total'], $f['cortesias'], $datos['totales']['moneda']]);
            }
            fclose($salida);
        }, 'reservas-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: ?int, 3: Collection<int, Sede>}
     */
    private function filtros(Request $request, int $empresaId): array
    {
        $datos = $request->validate(['desde' => ['nullable', 'date_format:Y-m-d'], 'hasta' => ['nullable', 'date_format:Y-m-d'], 'sede' => ['nullable', 'integer']]);
        $zona = Empresa::whereKey($empresaId)->value('zona_horaria') ?: 'America/Mexico_City';
        $hoy = now($zona)->startOfDay()->toImmutable();
        $desde = isset($datos['desde']) ? CarbonImmutable::createFromFormat('Y-m-d', $datos['desde'], $zona)->startOfDay() : $hoy->startOfMonth();
        $hasta = isset($datos['hasta']) ? CarbonImmutable::createFromFormat('Y-m-d', $datos['hasta'], $zona)->startOfDay()->addDay() : $hoy->endOfMonth()->startOfDay()->addDay();
        if ($hasta->lessThanOrEqualTo($desde) || $desde->diffInDays($hasta) > 370) {
            $hasta = $desde->addMonth();
        }
        $permitidas = $this->reservas->sedes($request->user(), 'reservas.ver');
        $sedes = $this->tenant->conEmpresa($empresaId, fn () => Sede::orderBy('nombre')->get(['id', 'nombre']))
            ->filter(fn ($s) => $permitidas === null || in_array((int) $s->id, $permitidas, true))->values();
        $sede = isset($datos['sede']) && $sedes->contains('id', (int) $datos['sede']) ? (int) $datos['sede'] : null;

        return [$desde, $hasta, $sede, $sedes];
    }
}
