<?php

namespace App\Http\Controllers\Operacion\Reservas;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\Reserva;
use App\Models\ReservaBloqueo;
use App\Services\Reservas\BloqueosReservas;
use App\Services\Reservas\Disponibilidad;
use App\Services\Reservas\TextosReservas;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Bloqueos de sitios (docs/tecnico/reservas.md, «Bloqueos»): lista, bloquear
 * (con el impacto antes de guardar) y «Dar de baja» el bloqueo.
 *
 * Bloquean por designación el responsable general de reservas y su suplente
 * (cualquier sitio) y el responsable y el suplente de un área (la suya), y por
 * permiso quien tiene `reservas.bloquear` en la sede (BloqueosReservas).
 */
class BloqueoReservaController extends Controller
{
    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly BloqueosReservas $bloqueos,
        private readonly Disponibilidad $disponibilidad,
    ) {}

    /** Bloqueos vigentes y programados (o el historial con ?ver=historial). */
    public function index(Request $request): View
    {
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        if ($empresaId === null) {
            abort_unless($actor->can('reservas.ver') || $actor->can('reservas.crear') || $actor->can('reservas.bloquear'), 403);

            return view('operacion.reservas.sedes', ['sinEmpresa' => true]);
        }
        $historial = $request->query('ver') === 'historial';

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $empresaId, $historial) {
            abort_unless($this->bloqueos->puedeBloquearAlgo($actor, $empresaId), 403);
            $lista = ReservaBloqueo::with(['area.espacio', 'area.sede', 'espacio:id,nombre', 'sede:id,nombre', 'creadoPor:id,name', 'dadoDeBajaPor:id,name'])
                ->when(! $historial, fn ($q) => $q->vigentes()->where('hasta', '>', now())->orderBy('desde'))
                ->when($historial, fn ($q) => $q->where(fn ($q) => $q->where('activo', false)->orWhere('hasta', '<=', now()))->orderByDesc('desde')->limit(200))
                ->get();
            $afectadas = Reserva::ocupan()->whereIn('bloqueo_id', $lista->pluck('id'))->where('fin', '>', now())
                ->selectRaw('bloqueo_id, COUNT(*) AS total')->groupBy('bloqueo_id')->pluck('total', 'bloqueo_id');
            $enHistorial = $historial ? $lista->count() : ReservaBloqueo::where(fn ($q) => $q->where('activo', false)->orWhere('hasta', '<=', now()))->count();

            return view('operacion.reservas.bloqueos', [
                'bloqueos' => $lista, 'historial' => $historial, 'afectadas' => $afectadas, 'enHistorial' => $enHistorial,
                'puede' => $this->permisos($actor), 'gestiona' => $lista->mapWithKeys(fn ($b) => [$b->id => $b->area !== null && $this->bloqueos->puedeBloquear($actor, $b->area)])->all(),
                'zona' => fn (ReservaBloqueo $b) => $b->area ? $this->disponibilidad->zona($b->area) : config('app.timezone'),
            ]);
        });
    }

    /** Formulario «Bloquear un sitio» (?area= y ?fecha= lo llenan desde el calendario). */
    public function create(Request $request): View
    {
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        abort_if($empresaId === null, 404);

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $empresaId, $request) {
            abort_unless($this->bloqueos->puedeBloquearAlgo($actor, $empresaId), 403);
            $areas = $this->bloqueos->areasQuePuede($actor);
            $fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('fecha')) === 1 ? (string) $request->query('fecha') : '';
            $hoy = now((string) (Empresa::whereKey($empresaId)->value('zona_horaria') ?: config('app.timezone')))->toDateString();

            return view('operacion.reservas.bloqueo-formulario', [
                'areas' => $areas, 'puede' => $this->permisos($actor), 'motivos' => BloqueosReservas::MOTIVOS,
                'valores' => [
                    'reserva_area_id' => (int) $request->query('area', $areas->count() === 1 ? $areas->first()->id : 0),
                    'fecha_desde' => $fecha !== '' && $fecha >= $hoy ? $fecha : $hoy, 'hora_desde' => '08:00',
                    'fecha_hasta' => $fecha !== '' && $fecha >= $hoy ? $fecha : $hoy, 'hora_hasta' => '18:00',
                ],
                'impacto' => session('impacto_bloqueo'),
            ]);
        });
    }

    /**
     * Impacto antes de guardar (formulario con JavaScript): cuántas reservas
     * caen en el bloqueo y de quién son.
     */
    public function impacto(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_if(! $this->tenant->activo(), 404);
        abort_unless($this->bloqueos->puedeBloquearAlgo($actor, (int) $this->tenant->empresaId()), 403);
        try {
            [$area, $desde, $hasta] = $this->bloqueos->leer($actor, $request->all(), false);
        } catch (ValidationException $e) {
            return response()->json(['valido' => false, 'mensaje' => collect($e->errors())->flatten()->first()]);
        }
        $afectadas = $this->bloqueos->afectadas($area, $desde, $hasta);

        return response()->json([
            'valido' => true, 'total' => $afectadas->count(),
            'texto' => self::textoImpacto($afectadas->count()),
            'reservas' => $afectadas->take(20)->map(fn (Reserva $r) => $r->folioTexto().' · '.$r->titulo.' · '.app(TextosReservas::class)->cuando($r).' · '.($r->solicitante?->name ?? '—'))->values()->all(),
        ]);
    }

    public static function textoImpacto(int $n): string
    {
        return match (true) {
            $n === 0 => 'No hay reservas en esas fechas.',
            $n === 1 => 'Hay 1 reserva en esas fechas; quedará «Afectada por bloqueo» y se avisará a quien la hizo para que la mueva o la dé de baja.',
            default => "Hay {$n} reservas en esas fechas; quedarán «Afectada por bloqueo» y se avisará a sus solicitantes para que las muevan o las den de baja.",
        };
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        abort_if(! $this->tenant->activo(), 404);
        abort_unless($this->bloqueos->puedeBloquearAlgo($actor, (int) $this->tenant->empresaId()), 403);

        // Sin JavaScript: si hay reservas en esas fechas, primero se muestra el impacto y se pide guardar otra vez
        [$area, $desde, $hasta] = $this->bloqueos->leer($actor, $request->all());
        $afectadas = $this->bloqueos->afectadas($area, $desde, $hasta);
        if ($afectadas->isNotEmpty() && (int) $request->input('impacto_visto', -1) < $afectadas->count()) {
            return back()->withInput()->with('impacto_bloqueo', [
                'total' => $afectadas->count(), 'texto' => self::textoImpacto($afectadas->count()),
                'reservas' => $afectadas->take(20)->map(fn (Reserva $r) => $r->folioTexto().' · '.$r->titulo.' · '.app(TextosReservas::class)->cuando($r).' · '.($r->solicitante?->name ?? '—'))->values()->all(),
            ]);
        }

        [$bloqueo, $afectadas] = $this->bloqueos->crear($actor, $request->all());
        $rango = Disponibilidad::rangoTexto($bloqueo->desde, $bloqueo->hasta, $this->disponibilidad->zona($area));

        return redirect()->route('reservas.bloqueos')
            ->with('ok', "«{$bloqueo->sitio()}» quedó bloqueado {$rango}: nadie puede reservarlo en ese horario."
                .($afectadas->isNotEmpty() ? ' '.$afectadas->count().($afectadas->count() === 1 ? ' reserva quedó afectada; avisamos a quien la hizo.' : ' reservas quedaron afectadas; avisamos a quienes las hicieron.') : ''))
            ->with('siguientes', [
                ['Ver su calendario', route('reservas.calendario', ['area' => $area->id, 'sede' => $area->sede_id, 'fecha' => $bloqueo->desde->copy()->setTimezone($this->disponibilidad->zona($area))->toDateString()]), 'bi-calendar3'],
                ['Bloquear otro sitio', route('reservas.bloqueos.create'), 'bi-lock'],
            ]);
    }

    /** «Dar de baja» el bloqueo (queda en el historial y en la Bitácora de auditoría). */
    public function baja(Request $request, int $bloqueo): RedirectResponse
    {
        $actor = $request->user();
        abort_if(! $this->tenant->activo(), 404);
        $bloqueo = ReservaBloqueo::findOrFail($bloqueo);
        $liberadas = $this->bloqueos->darDeBaja($actor, $bloqueo, (string) $request->input('motivo_baja'));

        return redirect()->route('reservas.bloqueos')
            ->with('ok', "Bloqueo de «{$bloqueo->sitio()}» dado de baja: ya se puede reservar ese horario."
                .($liberadas > 0 ? ' '.($liberadas === 1 ? 'La reserva afectada ya no lo está; avisamos a quien la hizo.' : "Las {$liberadas} reservas afectadas ya no lo están; avisamos a quienes las hicieron.") : ''))
            ->with('siguientes', [['Ver su calendario', route('reservas.calendario', ['area' => $bloqueo->reserva_area_id, 'sede' => $bloqueo->sede_id]), 'bi-calendar3']]);
    }

    /** @return array<string, bool> */
    private function permisos($actor): array
    {
        return [
            'crear' => $actor->can('reservas.crear'), 'ver' => $actor->can('reservas.ver'), 'aprobar' => $actor->can('reservas.aprobar'),
            'configurar' => $actor->can('reservas.configurar'), 'exportar' => $actor->can('reservas.exportar'),
        ];
    }
}
