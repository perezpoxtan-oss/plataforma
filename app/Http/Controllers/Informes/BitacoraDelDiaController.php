<?php

namespace App\Http\Controllers\Informes;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Services\Informes\AlcanceInformes;
use App\Services\Informes\BitacoraDelDia;
use App\Support\Csv;
use App\Support\Entrada;
use App\Support\HoraLocal;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use App\Support\ZonasHorarias;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Informes → Bitácora general del día (ADR-0017): ?fecha=AAAA-MM-DD (por
 * omisión, hoy en la hora de la sede), filtros por sede y por tipo, hoja
 * imprimible para entregar el turno y archivo de Excel (CSV).
 */
class BitacoraDelDiaController extends Controller
{
    private const MESES = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    private const DIAS = [1 => 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];

    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly AlcanceInformes $alcance,
        private readonly BitacoraDelDia $bitacora,
        private readonly HoraLocal $hora,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize(BitacoraDelDia::PERMISO);

        return $this->mostrar($request, 'informes.bitacora');
    }

    public function imprimir(Request $request): View
    {
        Gate::authorize('bitacora_dia.imprimir');

        return $this->mostrar($request, 'informes.bitacora-imprimir');
    }

    private function mostrar(Request $request, string $vista): View
    {
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        if ($empresaId === null) {
            abort_if($vista !== 'informes.bitacora', 404);

            return view($vista, ['sinEmpresa' => true]);
        }

        return $this->tenant->conEmpresa($empresaId, fn () => view($vista, $this->datos($request, $empresaId) + [
            'sinEmpresa' => false,
            'puedeImprimir' => $actor->can('bitacora_dia.imprimir'),
            'puedeExportar' => $actor->can('bitacora_dia.exportar'),
        ]));
    }

    public function exportar(Request $request): StreamedResponse
    {
        Gate::authorize('bitacora_dia.exportar');
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);
        $datos = $this->tenant->conEmpresa($empresaId, fn () => $this->datos($request, $empresaId));

        return response()->streamDownload(function () use ($datos) {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\xEF\xBB\xBF"); // para que Excel respete los acentos
            Csv::fila($salida, ['Fecha', 'Hora', 'Sede', 'Tipo', 'Qué pasó', 'Detalle']);
            foreach ($datos['eventos'] as $e) {
                Csv::fila($salida, [$datos['fecha']->format('d/m/Y'), $e['hora'], $e['sede'], BitacoraDelDia::nombreTipo($e['tipo']), $e['etiqueta'], $e['texto']]);
            }
            fclose($salida);
        }, 'bitacora-del-dia-'.$datos['fecha']->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, mixed> */
    private function datos(Request $request, int $empresaId): array
    {
        $actor = $request->user();
        $sedes = $this->alcance->sedesParaElegir($actor, BitacoraDelDia::PERMISO);
        $seleccion = $this->alcance->seleccion($request->query('sede'), $sedes);
        // La hora de la sede elegida (o la única que tiene); si son varias, la de quien consulta
        $sedeZona = $seleccion !== null ? $sedes->firstWhere('id', $seleccion[0]) : ($sedes->count() === 1 ? $sedes->first() : null);
        $zona = $sedeZona?->zona_horaria ?: $this->hora->zona();
        $hoy = CarbonImmutable::now($zona)->startOfDay();
        $texto = Entrada::texto($request->query('fecha'));
        try {
            $fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', $texto) ? (CarbonImmutable::createFromFormat('!Y-m-d', $texto, $zona) ?: $hoy) : $hoy;
        } catch (\Throwable) {
            $fecha = $hoy;
        }
        if ($fecha->format('Y-m-d') !== $texto) {
            $fecha = $hoy; // una fecha imposible (31 de febrero) vuelve a hoy
        }
        if ($fecha->greaterThan($hoy)) {
            $fecha = $hoy;
        }

        $visibles = $this->bitacora->visibles($actor);
        $tipo = Entrada::texto($request->query('tipo'));
        $tipos = in_array($tipo, $visibles, true) ? [$tipo] : $visibles;
        $resultado = $this->bitacora->eventos($actor, $fecha, $zona, $seleccion, $tipos);

        return $resultado + [
            'fecha' => $fecha,
            'hoy' => $hoy,
            'fechaLegible' => self::DIAS[$fecha->dayOfWeekIso].' '.$fecha->day.' de '.self::MESES[$fecha->month].' de '.$fecha->year,
            'zonaTexto' => ZonasHorarias::etiqueta($zona),
            'sedes' => $sedes,
            'sede' => $seleccion[0] ?? null,
            'textoSedes' => $seleccion !== null ? (string) $sedes->firstWhere('id', $seleccion[0])?->nombre : ($sedes->count() === 1 ? $sedes->first()->nombre : 'Todas tus sedes'),
            'visibles' => $visibles,
            'tipo' => count($tipos) === 1 && count($visibles) > 1 ? $tipos[0] : null,
            'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial'),
            'generado' => now(),
        ];
    }
}
