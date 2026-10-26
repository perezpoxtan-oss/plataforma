<?php

namespace App\Http\Controllers\Operacion;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Llave;
use App\Models\PrestamoLlave;
use App\Models\Sede;
use App\Models\User;
use App\Services\Lector\Lector;
use App\Services\PrestamoLlaves\AdministradorPrestamosLlaves;
use App\Support\AjustesCaseta;
use App\Support\Csv;
use App\Support\Entrada;
use App\Support\EstadoActivo;
use App\Support\HoraLocal;
use App\Support\Lector\Etiqueta;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Préstamo de llaves (réplica de modules/llaves/bitacora_llaves.php de
 * SEGCAT, "Bitácora de Llaves"): pestañas Llaves en Uso / Historial de
 * Entregas, diálogo "Prestar Llave" con el lector universal y "Registrar y
 * Capturar Siguiente", recibir, anular, reactivar, historial por llave y el
 * Excel de auditoría.
 *
 * Permisos: prestamo_llaves.ver | crear (prestar) | editar (recibir) |
 * eliminar (anular y reactivar) | exportar. SEGCAT usaba los de llaves.*.
 */
class PrestamoLlaveController extends Controller
{
    /** Préstamos del Historial de Entregas en pantalla (SEGCAT: 300). */
    public const HISTORIAL = 300;

    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly AdministradorPrestamosLlaves $prestamos,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('prestamo_llaves.ver');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);

        if ($empresaId === null) {
            return view('operacion.prestamo-llaves.index', ['sinEmpresa' => true]);
        }
        $prestar = is_numeric($request->query('prestar')) ? (int) $request->query('prestar') : null;

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $empresaId, $prestar) {
            $enUso = $this->consulta($actor, 'prestamo_llaves.ver')->vigentes()
                ->orderByDesc('prestamos_llaves.prestado_en')->orderByDesc('prestamos_llaves.id')->get();
            // C-14: las que no regresaron siguen a la vista (con «Recibir») mientras la llave no se dé de baja
            $noRegresaron = $this->consulta($actor, 'prestamo_llaves.ver')->where('prestamos_llaves.estado', PrestamoLlave::NO_REGRESO)
                ->where('prestamos_llaves.anulado', false)->whereHas('llave', fn ($q) => $q->where('estado', EstadoActivo::NO_REGRESO))
                ->orderByDesc('prestamos_llaves.prestado_en')->get();
            $historial = $this->consulta($actor, 'prestamo_llaves.ver')
                ->where(fn ($q) => $q->whereIn('prestamos_llaves.estado', [PrestamoLlave::DEVUELTA, PrestamoLlave::NO_REGRESO])->orWhere('prestamos_llaves.anulado', true))
                ->whereNotIn('prestamos_llaves.id', $noRegresaron->pluck('id'))
                ->orderByDesc('prestamos_llaves.prestado_en')->orderByDesc('prestamos_llaves.id')->limit(self::HISTORIAL)->get();
            $todos = $enUso->concat($noRegresaron)->concat($historial);

            // ADR-0014: semáforo de llaves fuera (Configuración → Caseta y activos; apagado por omisión)
            $ajustes = AjustesCaseta::de($empresaId);
            $semaforo = AdministradorPrestamosLlaves::conSemaforo($ajustes)
                ? $enUso->mapWithKeys(fn (PrestamoLlave $p) => [$p->id => $this->prestamos->semaforo($p, $ajustes)])->all() : null;

            $puedePrestar = $actor->can('prestamo_llaves.crear');
            $sedesPrestar = $puedePrestar ? $this->prestamos->sedesParaPrestar($actor) : collect();

            return view('operacion.prestamo-llaves.index', [
                'sinEmpresa' => false,
                'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial'),
                'enUso' => $enUso,
                'noRegresaron' => $noRegresaron,
                'historial' => $historial,
                'sedesFiltro' => $todos->pluck('sede')->filter()->unique('id')->sortBy('nombre')->values(),
                'sedesPrestar' => $sedesPrestar,
                'sedeSugerida' => $this->sedeSugerida($actor, $sedesPrestar),
                // Para avisar en el diálogo si la llave escaneada ya está fuera
                'llavesFuera' => $enUso->pluck('llave_id')->map(fn ($id) => (int) $id)->values(),
                'recibibles' => $this->prestamos->idsEnAlcance($actor, 'prestamo_llaves.editar', $enUso->concat($noRegresaron)),
                'anulables' => $this->prestamos->idsEnAlcance($actor, 'prestamo_llaves.eliminar', $todos),
                'hoy' => Carbon::now(app(HoraLocal::class)->zona())->format('Y-m-d'),
                'semaforo' => $semaforo,
                // ADR-0014: «Prestar» desde el lector (QR de una llave en caseta): el diálogo abre con la llave elegida
                'llavePrestar' => $prestar !== null && $puedePrestar
                    ? Llave::enCaseta()->whereIn('sede_id', $sedesPrestar->pluck('id'))->whereNotIn('id', $enUso->pluck('llave_id'))->find($prestar)
                    : null,
                'puede' => [
                    'prestar' => $puedePrestar && $sedesPrestar->isNotEmpty(),
                    'recibir' => $actor->can('prestamo_llaves.editar'),
                    'anular' => $actor->can('prestamo_llaves.eliminar'),
                    'exportar' => $actor->can('prestamo_llaves.exportar'),
                ],
            ]);
        });
    }

    /**
     * Prestar. Con "Registrar y Capturar Siguiente" llega por fetch y responde
     * JSON con la ficha nueva ya dibujada; sin JavaScript, redirige.
     */
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        Gate::authorize('prestamo_llaves.crear');
        $empresaId = $this->empresaDeTrabajo($request);
        $actor = $request->user();

        try {
            $prestamo = $this->tenant->conEmpresa($empresaId, fn () => $this->prestamos->prestar($actor, $request->all()));
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'mensaje' => 'Revisa los datos del préstamo.', 'errores' => $e->errors()], 422);
            }
            throw $e;
        }

        $prestamo->load(['llave:id,nomenclatura,descripcion', 'colaborador:id,num_empleado,nombre,apellido_paterno,apellido_materno', 'entrego:id,name', 'sede:id,nombre']);
        $mensaje = "Llave «{$prestamo->llave->nomenclatura}» entregada a {$prestamo->nombreReceptor()}.";

        if ($request->expectsJson()) {
            $html = $this->tenant->conEmpresa($empresaId, fn () => view('operacion.prestamo-llaves._ficha', [
                'p' => $prestamo,
                'hoy' => Carbon::now(app(HoraLocal::class)->zona())->format('Y-m-d'),
                'puedeRecibir' => $this->enAlcance($actor, 'prestamo_llaves.editar', $prestamo),
                'puedeAnular' => $this->enAlcance($actor, 'prestamo_llaves.eliminar', $prestamo),
            ])->render());

            return response()->json(['ok' => true, 'mensaje' => $mensaje, 'llave_id' => $prestamo->llave_id, 'ficha' => $html], 201);
        }

        return redirect()->to(route('prestamo_llaves.index').'#prestamo-'.$prestamo->id)->with('ok', $mensaje);
    }

    public function recibir(Request $request, int $prestamo): RedirectResponse
    {
        Gate::authorize('prestamo_llaves.editar');

        $modelo = $this->accion($request, $prestamo, 'prestamo_llaves.editar', fn (PrestamoLlave $p) => $this->prestamos->recibir($request->user(), $p));

        return redirect()->route('prestamo_llaves.index')->with('ok', "Llave «{$modelo->llave?->nomenclatura}» recibida de vuelta correctamente. Devuelve la identificación en garantía.");
    }

    /**
     * C-14: «No regresó». Quien puede dar de baja llaves pasa directo a la
     * baja con voucher (con quien la tenía como responsable propuesto); si
     * no, su supervisor lo ve en la campana con el botón «Dar de baja».
     */
    public function noRegreso(Request $request, int $prestamo): RedirectResponse
    {
        Gate::authorize('prestamo_llaves.editar');
        $puedeBaja = false;
        $modelo = $this->accion($request, $prestamo, 'prestamo_llaves.editar', function (PrestamoLlave $p) use ($request, &$puedeBaja) {
            $puedeBaja = $this->prestamos->noRegreso($request->user(), $p);
        });
        $llave = $modelo->llave?->nomenclatura;

        if ($puedeBaja) {
            return redirect()->to(route('llaves.index', ['baja' => $modelo->llave_id]).'#llave-'.$modelo->llave_id)
                ->with('aviso', "La llave «{$llave}» quedó como «No regresó». Si no aparece, dala de baja aquí: el voucher ya propone a quien la tenía.");
        }

        return redirect()->route('prestamo_llaves.index')
            ->with('aviso', "La llave «{$llave}» quedó como «No regresó» y ya no se puede prestar. Se avisó a tu supervisor para que la dé de baja; si aparece, recíbela con «Recibir».");
    }

    /**
     * ADR-0014: «Escanea la llave que te devuelven» en la pantalla principal
     * (como «Dar salida» de accesos): QR, NFC, lector USB o la nomenclatura.
     * Encuentra el préstamo vigente de esa llave dentro del alcance y lo recibe.
     */
    public function recibirEscaneada(Request $request, Lector $lector): RedirectResponse
    {
        Gate::authorize('prestamo_llaves.editar');
        $empresaId = $this->empresaDeTrabajo($request);
        $lectura = trim(Entrada::texto($request->input('lectura')));
        if ($lectura === '' || mb_strlen($lectura) > Etiqueta::MAXIMO) {
            throw ValidationException::withMessages(['lectura' => 'Escanea la llave que te devuelven o escribe su nombre (por ejemplo HDC-101).']);
        }
        $actor = $request->user();

        $prestamo = $this->tenant->conEmpresa($empresaId, function () use ($actor, $empresaId, $lectura, $lector) {
            $llaves = $lector->resolver($actor, $empresaId, $lectura, ['llave'], 10)->pluck('id')->all();
            if ($llaves === []) {
                throw ValidationException::withMessages(['lectura' => "No encontré ninguna llave con «{$lectura}». Revisa la etiqueta o escribe su nombre completo."]);
            }
            $vigentes = $this->prestamos->limitar(PrestamoLlave::query(), $actor, 'prestamo_llaves.editar')->vigentes()
                ->whereIn('prestamos_llaves.llave_id', $llaves)->with('llave:id,nomenclatura', 'colaborador:id,nombre,apellido_paterno,apellido_materno')->get();
            if ($vigentes->isEmpty()) {
                // C-14: una llave marcada «No regresó» que apareció
                $vigentes = $this->prestamos->limitar(PrestamoLlave::query(), $actor, 'prestamo_llaves.editar')
                    ->where('prestamos_llaves.estado', PrestamoLlave::NO_REGRESO)->where('prestamos_llaves.anulado', false)
                    ->whereIn('prestamos_llaves.llave_id', Llave::whereIn('id', $llaves)->where('estado', EstadoActivo::NO_REGRESO)->pluck('id'))
                    ->with('llave:id,nomenclatura', 'colaborador:id,nombre,apellido_paterno,apellido_materno')->latest('prestamos_llaves.prestado_en')->limit(1)->get();
            }
            if ($vigentes->isEmpty()) {
                $fuera = PrestamoLlave::vigentes()->whereIn('llave_id', $llaves)->exists();
                throw ValidationException::withMessages(['lectura' => $fuera
                    ? 'Esa llave está prestada en una sede donde no puedes recibir llaves.'
                    : 'Esa llave no está prestada: ya está en caseta.']);
            }
            if ($vigentes->count() > 1) {
                throw ValidationException::withMessages(['lectura' => 'Varias llaves prestadas coinciden con «'.$lectura.'»: escanea su QR o escribe su nombre completo.']);
            }
            $p = $vigentes->first();
            $this->prestamos->recibir($actor, $p);

            return $p;
        });

        return redirect()->route('prestamo_llaves.index')->with('ok', "Llave «{$prestamo->llave?->nomenclatura}» recibida de {$prestamo->nombreReceptor()}. "
            .($prestamo->tipo_garantia === 'ninguna' ? 'No dejó identificación en garantía.' : 'Devuelve su garantía: '.$prestamo->textoGarantia().'.').' Escanea la siguiente si hay más.');
    }

    public function anular(Request $request, int $prestamo): RedirectResponse
    {
        Gate::authorize('prestamo_llaves.eliminar');

        $modelo = $this->accion($request, $prestamo, 'prestamo_llaves.eliminar', fn (PrestamoLlave $p) => $this->prestamos->anular($request->user(), $p));

        return redirect()->route('prestamo_llaves.index')->with('aviso', "Préstamo {$modelo->folio()} anulado — la llave «{$modelo->llave?->nomenclatura}» vuelve a estar disponible.");
    }

    public function reactivar(Request $request, int $prestamo): RedirectResponse
    {
        Gate::authorize('prestamo_llaves.eliminar');

        $modelo = $this->accion($request, $prestamo, 'prestamo_llaves.eliminar', fn (PrestamoLlave $p) => $this->prestamos->reactivar($request->user(), $p));

        return redirect()->to(route('prestamo_llaves.index').'#prestamo-'.$modelo->id)->with('ok', "Préstamo {$modelo->folio()} reactivado — vuelve a contar como válido.");
    }

    /**
     * Historial de movimientos de una llave (SEGCAT: llave_historial_ajax.php):
     * un fragmento HTML para el diálogo "Historial de Movimientos".
     */
    public function historial(Request $request, int $llave): View
    {
        Gate::authorize('prestamo_llaves.ver');
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $llave) {
            $sedes = $this->prestamos->sedes($request->user(), 'prestamo_llaves.ver');
            $modelo = Llave::query()->when($sedes !== null, fn ($q) => $q->whereIn('sede_id', $sedes))->find($llave);
            abort_if($modelo === null, 404);

            return view('operacion.prestamo-llaves._historial', ['llave' => $modelo, 'movimientos' => $this->prestamos->historialDe($modelo, $request->user())]);
        });
    }

    /**
     * Excel de auditoría (SEGCAT: reporte_llaves_excel.php, "Excel
     * (Auditoría)"): CSV con BOM para que Excel respete los acentos.
     */
    public function exportar(Request $request, HoraLocal $hora): StreamedResponse
    {
        Gate::authorize('prestamo_llaves.exportar');
        $empresaId = $this->empresaDeTrabajo($request);
        $filtros = $request->validate([
            'sede' => ['nullable', 'integer'],
            'estado' => ['nullable', 'in:en_uso,devuelta,anulado'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $filas = $this->tenant->conEmpresa($empresaId, function () use ($request, $filtros, $hora) {
            $zona = $hora->zona();

            return $this->consulta($request->user(), 'prestamo_llaves.exportar')
                ->with('recibio:id,name', 'anulo:id,name')
                ->when(isset($filtros['sede']), fn ($q) => $q->where('prestamos_llaves.sede_id', (int) $filtros['sede']))
                ->when(($filtros['estado'] ?? null) === 'en_uso', fn ($q) => $q->vigentes())
                ->when(($filtros['estado'] ?? null) === 'devuelta', fn ($q) => $q->where('prestamos_llaves.estado', PrestamoLlave::DEVUELTA)->where('prestamos_llaves.anulado', false))
                ->when(($filtros['estado'] ?? null) === 'anulado', fn ($q) => $q->where('prestamos_llaves.anulado', true))
                // Las fechas del filtro son días de la hora local; se guardan en UTC
                ->when(isset($filtros['desde']), fn ($q) => $q->where('prestamos_llaves.prestado_en', '>=', Carbon::createFromFormat('Y-m-d', $filtros['desde'], $zona)->startOfDay()->utc()))
                ->when(isset($filtros['hasta']), fn ($q) => $q->where('prestamos_llaves.prestado_en', '<=', Carbon::createFromFormat('Y-m-d', $filtros['hasta'], $zona)->endOfDay()->utc()))
                ->orderByDesc('prestamos_llaves.prestado_en')->orderByDesc('prestamos_llaves.id')
                ->get();
        });

        return response()->streamDownload(function () use ($filas, $hora) {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\xEF\xBB\xBF"); // para que Excel respete los acentos
            Csv::fila($salida, ['Folio', 'Sede', 'Código de llave', 'Descripción de la llave', 'Nómina del colaborador', 'Nombre del colaborador',
                'ID en garantía', 'Folio / detalle de la ID', 'Fecha de salida', 'Guardia que entrega', 'Fecha de regreso', 'Guardia que recibe',
                'Estado actual', 'Anulado por', 'Fecha de anulación']);
            foreach ($filas as $p) {
                Csv::fila($salida, [
                    $p->folio(), $p->sede?->nombre, $p->llave?->nomenclatura, $p->llave?->descripcion, $p->colaborador?->num_empleado,
                    $p->nombreReceptor(), $p->etiquetaGarantia(), $p->folio_garantia, $hora->formatear($p->prestado_en), $p->entrego?->name,
                    $p->devuelto_en ? $hora->formatear($p->devuelto_en) : 'PENDIENTE', $p->recibio?->name ?? ($p->devuelto_en ? '' : 'PENDIENTE'),
                    $p->etiquetaEstado(), $p->anulo?->name, $hora->formatear($p->anulado_en),
                ]);
            }
            fclose($salida);
        }, 'Auditoria_Llaves_'.Carbon::now($hora->zona())->format('Ymd_His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Préstamos visibles con un permiso, con lo que se muestra ya cargado.
     *
     * @return Builder<PrestamoLlave>
     */
    private function consulta(User $actor, string $permiso): Builder
    {
        return $this->prestamos->limitar(PrestamoLlave::query(), $actor, $permiso)->with([
            'sede:id,nombre', 'llave:id,nomenclatura,descripcion,sede_id',
            'colaborador:id,num_empleado,nombre,apellido_paterno,apellido_materno',
            'entrego:id,name', 'recibio:id,name', 'anulo:id,name',
        ]);
    }

    /**
     * Sede elegida de inicio en "Prestar Llave": la única que tiene, o la de
     * su colaborador si está entre las suyas.
     *
     * @param  Collection<int, Sede>  $sedes
     */
    private function sedeSugerida(User $actor, Collection $sedes): ?int
    {
        if ($sedes->count() === 1) {
            return (int) $sedes->first()->id;
        }
        $colaboradorId = User::whereKey($actor->id)->value('colaborador_id');
        $propia = $colaboradorId ? Colaborador::whereKey($colaboradorId)->value('sede_id') : null;

        return $propia !== null && $sedes->contains('id', (int) $propia) ? (int) $propia : null;
    }

    private function enAlcance(User $actor, string $permiso, PrestamoLlave $prestamo): bool
    {
        return $actor->can($permiso) && $this->prestamos->limitar(PrestamoLlave::query(), $actor, $permiso)->whereKey($prestamo->id)->exists();
    }

    /**
     * Busca el préstamo dentro del alcance del permiso (otra empresa o fuera
     * de sus sedes: 404) y aplica la acción.
     *
     * @param  callable(PrestamoLlave): void  $hacer
     */
    private function accion(Request $request, int $id, string $permiso, callable $hacer): PrestamoLlave
    {
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $id, $permiso, $hacer) {
            $modelo = $this->prestamos->limitar(PrestamoLlave::query(), $request->user(), $permiso)->with('llave:id,nomenclatura')->find($id);
            abort_if($modelo === null, 404);
            $hacer($modelo);

            return $modelo;
        });
    }

    private function empresaDeTrabajo(Request $request): int
    {
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        return $empresaId;
    }
}
