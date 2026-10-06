<?php

namespace App\Http\Controllers\Seguridad;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\EquipoPc;
use App\Models\Espacio;
use App\Models\RecorridoPc;
use App\Models\RevisionRecorridoPc;
use App\Models\User;
use App\Services\Novedades\AdministradorNovedades;
use App\Services\RecorridosPc\AdministradorRecorridosPc;
use App\Services\RecorridosPc\CatalogoEquiposPc;
use App\Services\RecorridosPc\Ubicaciones;
use App\Support\Csv;
use App\Support\HoraLocal;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Recorridos de Protección Civil (réplica de modules/bitacora/recorridos_pc_*
 * de SEGCAT): lista de recorridos, «Nuevo Recorrido», el recorrido en curso
 * (un punto de inspección a la vez, escaneando el equipo), «Guardar y
 * Continuar Después» / «Finalizar Recorrido», el detalle, el «Reporte de
 * Auditoría» para imprimir y la exportación a CSV.
 *
 * Las reglas viven en App\Services\RecorridosPc\AdministradorRecorridosPc.
 */
class RecorridoPcController extends Controller
{
    /** Recorridos finalizados que se muestran en la lista (SEGCAT: 100); los en proceso, todos. */
    public const MAX_LISTA = 100;

    /** Días máximos del Reporte de Auditoría y la exportación. */
    public const MAX_DIAS_REPORTE = 366;

    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly AdministradorRecorridosPc $recorridos,
        private readonly Ubicaciones $ubicaciones,
        private readonly HoraLocal $hora,
    ) {}

    // ------------------------------------------------------------------ Lista

    public function index(Request $request): View
    {
        Gate::authorize('recorridos_pc.ver');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);

        if ($empresaId === null) {
            return view('seguridad.recorridos-pc.index', ['sinEmpresa' => true]);
        }

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $empresaId) {
            $base = fn () => $this->recorridos->limitar(RecorridoPc::query(), $actor, 'recorridos_pc.ver')->with($this->relacionesLista())
                ->withCount(['revisiones', 'revisiones as fallas_count' => fn ($q) => $q->where('resultado', RevisionRecorridoPc::FALLA)]);
            $abiertos = $base()->where('estatus', RecorridoPc::EN_PROCESO)->orderByDesc('created_at')->get();
            $cerrados = $base()->where('estatus', '!=', RecorridoPc::EN_PROCESO)->orderByDesc('created_at')->orderByDesc('id')->limit(self::MAX_LISTA)->get();
            $lista = $abiertos->concat($cerrados)->values();

            $sedesAlta = $this->recorridos->sedes($actor, 'recorridos_pc.crear');
            $puede = [
                'crear' => $sedesAlta->isNotEmpty(),
                'imprimir' => $actor->can('recorridos_pc.imprimir'),
                'exportar' => $actor->can('recorridos_pc.exportar'),
            ];
            $hoy = CarbonImmutable::now($this->hora->zona());

            return view('seguridad.recorridos-pc.index', [
                'sinEmpresa' => false,
                'recorridos' => $lista,
                'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial'),
                'sedesFiltro' => $lista->pluck('sede')->filter()->unique('id')->sortBy('nombre')->values(),
                'sedesAlta' => $sedesAlta,
                'edificios' => $puede['crear'] ? $this->ubicaciones->nodos($sedesAlta->pluck('id')->all())->where('nivel', Espacio::EDIFICIO)->where('activo', true)->values() : collect(),
                'continuables' => $abiertos->filter(fn (RecorridoPc $r) => $puede['crear'] && $this->recorridos->puede($actor, 'recorridos_pc.crear', $r))->pluck('id')->all(),
                'periodo' => ['desde' => $hoy->startOfMonth()->toDateString(), 'hasta' => $hoy->toDateString()],
                'puede' => $puede,
            ]);
        });
    }

    // ------------------------------------------------------------- Escritura

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('recorridos_pc.crear');
        $empresaId = $this->empresaDeTrabajo($request);

        $recorrido = $this->tenant->conEmpresa($empresaId, fn () => $this->recorridos->iniciar($request->user(), $request->all()));

        return redirect()->route('recorridos_pc.show', $recorrido->id)
            ->with('ok', "Recorrido {$recorrido->folio()} iniciado. Escanea el primer equipo.");
    }

    /**
     * El recorrido: detalle y, si sigue En Proceso, el punto de inspección
     * (?equipo=ID tras escanear o elegir un pendiente; ?manual=1 para
     * capturarlo a mano).
     */
    public function show(Request $request, int $recorrido, CatalogoEquiposPc $catalogo, AdministradorNovedades $novedades): View
    {
        Gate::authorize('recorridos_pc.ver');
        $actor = $request->user();
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $actor, $recorrido, $catalogo, $novedades, $empresaId) {
            $r = $this->visible($actor, $recorrido, 'recorridos_pc.ver');
            $r->load([...$this->relacionesLista(), 'editor:id,name', 'revisiones.creador:id,name']);
            $editable = $r->enProceso() && $actor->can('recorridos_pc.crear') && $this->recorridos->puede($actor, 'recorridos_pc.crear', $r);

            // Punto de inspección en pantalla (uno a la vez)
            $punto = null;
            $errorPunto = null;
            if ($editable) {
                $equipoId = $request->query('equipo', old('equipo_pc_id'));
                if (is_numeric($equipoId)) {
                    $equipo = $catalogo->limitar(EquipoPc::query(), $actor, 'recorridos_pc.ver')->with('espacio:id,nombre,ruta')->find((int) $equipoId);
                    if ($equipo === null || (int) $equipo->sede_id !== (int) $r->sede_id) {
                        $errorPunto = 'Ese equipo no es de '.($r->sede?->nombre ?? 'la sede de este recorrido').'. Escanea un equipo de esta sede o captúralo a mano.';
                    } elseif (! $equipo->activo) {
                        $errorPunto = "El equipo {$equipo->numero_serie} está dado de baja en el catálogo. Si sigue instalado, captúralo a mano y pide que lo reactiven.";
                    } else {
                        $previa = $r->revisiones->where('equipo_pc_id', $equipo->id)->last();
                        $punto = ['modo' => 'equipo', 'equipo' => $equipo, 'ubicacion' => $equipo->espacio ? $this->ubicaciones->texto($equipo->espacio) : null, 'previa' => $previa];
                    }
                } elseif ($request->boolean('manual') || old('_punto') === 'manual') {
                    $punto = ['modo' => 'manual', 'equipo' => null, 'ubicacion' => null, 'previa' => null];
                }
            }

            $nodos = $this->ubicaciones->nodos([(int) $r->sede_id]);

            return view('seguridad.recorridos-pc.show', [
                'r' => $r,
                'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial'),
                'progreso' => $this->recorridos->progreso($r),
                'editable' => $editable,
                'punto' => $punto,
                'errorPunto' => $errorPunto,
                'nodos' => $nodos,
                'puedeVerTicket' => $r->novedad !== null && $novedades->permite($actor, 'ver', $r->novedad),
                'puede' => ['imprimir' => $actor->can('recorridos_pc.imprimir')],
            ]);
        });
    }

    /** Guarda un punto de inspección y vuelve listo para escanear el siguiente. */
    public function registrarPunto(Request $request, int $recorrido): RedirectResponse
    {
        Gate::authorize('recorridos_pc.crear');
        $empresaId = $this->empresaDeTrabajo($request);

        [$r, $revision] = $this->tenant->conEmpresa($empresaId, function () use ($request, $recorrido) {
            $r = $this->visible($request->user(), $recorrido, 'recorridos_pc.crear');
            $revision = $this->recorridos->registrarPunto($request->user(), $r, $request->all());

            return [$r->fresh('novedad'), $revision];
        });

        $destino = redirect()->to(route('recorridos_pc.show', $r->id).'#escanear');
        if (! $revision->esFalla()) {
            return $destino->with('ok', "{$revision->identificador} revisado: OK. Escanea el siguiente equipo.");
        }

        return $destino->with('aviso', "{$revision->identificador} quedó CON HALLAZGO y se reportó en la Bitácora de Novedades"
            .($r->novedad ? " (ticket {$r->novedad->folio()})" : '').'. Escanea el siguiente equipo.');
    }

    /** «Guardar y Continuar Después» o «Finalizar Recorrido» (finalizar=1). */
    public function update(Request $request, int $recorrido): RedirectResponse
    {
        Gate::authorize('recorridos_pc.crear');
        $empresaId = $this->empresaDeTrabajo($request);
        $finalizar = $request->boolean('finalizar');

        $r = $this->tenant->conEmpresa($empresaId, function () use ($request, $recorrido, $finalizar) {
            $r = $this->visible($request->user(), $recorrido, 'recorridos_pc.crear');

            return $this->recorridos->guardar($request->user(), $r, $request->all(), $finalizar);
        });

        if (! $finalizar) {
            return redirect()->to(route('recorridos_pc.index').'#recorrido-'.$r->id)
                ->with('ok', "Recorrido {$r->folio()} guardado En Proceso. Puedes continuarlo cuando quieras con «Continuar Recorrido».");
        }

        return redirect()->route('recorridos_pc.show', $r->id)->with($r->estatus === RecorridoPc::CON_HALLAZGOS ? 'aviso' : 'ok',
            "Recorrido {$r->folio()} finalizado: ".mb_strtoupper($r->etiquetaEstatus()).'.');
    }

    // --------------------------------------------------------------- Impresos

    /**
     * «Reporte de Auditoría» (SEGCAT: recorridos_pc_pdf.php): recorridos por
     * rango de fechas (y sede), listo para imprimir o guardar como PDF.
     */
    public function reporte(Request $request): View
    {
        Gate::authorize('recorridos_pc.imprimir');
        $actor = $request->user();
        $empresaId = $this->empresaDeTrabajo($request);
        $filtros = $this->filtros($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $empresaId, $filtros) {
            $recorridos = $this->consultaPeriodo($actor, 'recorridos_pc.imprimir', $filtros)
                ->with([...$this->relacionesLista(), 'revisiones'])->orderBy('created_at')->orderBy('id')->get();
            $revisiones = $recorridos->flatMap->revisiones;

            return view('seguridad.recorridos-pc.reporte', [
                'recorridos' => $recorridos,
                'filtros' => $filtros,
                'resumen' => ['recorridos' => $recorridos->count(), 'equipos' => $revisiones->count(), 'hallazgos' => $revisiones->where('resultado', RevisionRecorridoPc::FALLA)->count()],
                'sedes' => $this->recorridos->sedes($actor, 'recorridos_pc.imprimir'),
                'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial'),
                'puedeExportar' => $actor->can('recorridos_pc.exportar'),
            ]);
        });
    }

    /**
     * CSV UTF-8 con BOM: un renglón por equipo revisado, con los filtros del reporte.
     */
    public function exportar(Request $request): StreamedResponse
    {
        Gate::authorize('recorridos_pc.exportar');
        $actor = $request->user();
        $empresaId = $this->empresaDeTrabajo($request);
        $filtros = $this->filtros($request);

        $recorridos = $this->tenant->conEmpresa($empresaId, fn () => $this->consultaPeriodo($actor, 'recorridos_pc.exportar', $filtros)
            ->with([...$this->relacionesLista(), 'revisiones.creador:id,name'])->orderBy('created_at')->orderBy('id')->limit(5000)->get());
        $hora = $this->hora;

        return response()->streamDownload(function () use ($recorridos, $hora) {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\xEF\xBB\xBF"); // para que Excel respete los acentos
            Csv::fila($salida, ['Recorrido', 'Inicio', 'Sede', 'Edificio / Zona', 'Realizó', 'Estatus', 'Ticket de Novedades', 'Hora del punto', 'Revisó',
                'Identificador', 'Categoría', 'Ubicación', 'Resultado', 'Piezas / criterios con falla', 'Observaciones', 'Observaciones generales']);
            foreach ($recorridos as $r) {
                $comunes = [$r->folio(), $hora->formatear($r->created_at), $r->sede?->nombre, $r->espacio?->nombre, $r->creador?->name, $r->etiquetaEstatus(), $r->novedad?->folio()];
                if ($r->revisiones->isEmpty()) {
                    Csv::fila($salida, [...$comunes, '', '', '', '', '', '', '', '', $r->observaciones_generales]);
                }
                foreach ($r->revisiones as $p) {
                    Csv::fila($salida, [...$comunes, $hora->formatear($p->created_at, 'H:i'), $p->creador?->name, $p->identificador, $p->etiquetaCategoria(), $p->ubicacion,
                        $p->esFalla() ? 'FALLA' : 'OK', implode(', ', $p->criteriosConFalla()), $p->observaciones, $r->observaciones_generales]);
                }
            }
            fclose($salida);
        }, 'recorridos-proteccion-civil-'.$filtros['desde'].'-al-'.$filtros['hasta'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ------------------------------------------------------------------ Apoyo

    /** @return list<string> */
    private function relacionesLista(): array
    {
        return ['sede:id,empresa_id,nombre,zona_horaria', 'espacio:id,nombre,activo,ruta', 'creador:id,name', 'finalizador:id,name', 'novedad:id,numero,estatus,sede_id,creado_por,categoria'];
    }

    /**
     * Fechas del reporte en la hora local (por omisión, del 1.º del mes a hoy) y sede.
     *
     * @return array{desde: string, hasta: string, sede: ?int}
     */
    private function filtros(Request $request): array
    {
        $hoy = CarbonImmutable::now($this->hora->zona());
        $fecha = function (mixed $valor, CarbonImmutable $porDefecto): CarbonImmutable {
            if (is_string($valor) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) && checkdate((int) substr($valor, 5, 2), (int) substr($valor, 8, 2), (int) substr($valor, 0, 4))) {
                return CarbonImmutable::createFromFormat('Y-m-d', $valor, $this->hora->zona())->startOfDay();
            }

            return $porDefecto;
        };
        $desde = $fecha($request->query('desde'), $hoy->startOfMonth());
        $hasta = $fecha($request->query('hasta'), $hoy->startOfDay());
        if ($hasta->lessThan($desde)) {
            [$desde, $hasta] = [$hasta, $desde];
        }
        if ($desde->diffInDays($hasta) > self::MAX_DIAS_REPORTE) {
            $desde = $hasta->subDays(self::MAX_DIAS_REPORTE);
        }
        $sede = $request->query('sede');

        return ['desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString(), 'sede' => is_numeric($sede) ? (int) $sede : null];
    }

    /**
     * @param  array{desde: string, hasta: string, sede: ?int}  $filtros
     * @return Builder<RecorridoPc>
     */
    private function consultaPeriodo(User $actor, string $permiso, array $filtros): Builder
    {
        $zona = $this->hora->zona();
        $inicio = CarbonImmutable::createFromFormat('Y-m-d', $filtros['desde'], $zona)->startOfDay()->utc();
        $fin = CarbonImmutable::createFromFormat('Y-m-d', $filtros['hasta'], $zona)->addDay()->startOfDay()->utc();

        return $this->recorridos->limitar(RecorridoPc::query(), $actor, $permiso)
            ->where('recorridos_pc.created_at', '>=', $inicio)->where('recorridos_pc.created_at', '<', $fin)
            ->when($filtros['sede'] !== null, fn ($q) => $q->where('recorridos_pc.sede_id', $filtros['sede']));
    }

    private function empresaDeTrabajo(Request $request): int
    {
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        return $empresaId;
    }

    /**
     * Un recorrido de otra empresa o fuera del alcance del permiso responde 404.
     */
    private function visible(User $actor, int $id, string $permiso): RecorridoPc
    {
        $r = $this->recorridos->limitar(RecorridoPc::query(), $actor, $permiso)->find($id);
        abort_if($r === null, 404);

        return $r;
    }
}
