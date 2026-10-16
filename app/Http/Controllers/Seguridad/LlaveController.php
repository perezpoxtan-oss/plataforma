<?php

namespace App\Http\Controllers\Seguridad;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Espacio;
use App\Models\GrupoEspacio;
use App\Models\Llave;
use App\Models\Puesto;
use App\Models\Sede;
use App\Models\User;
use App\Services\Lector\ImpresionDesdePadron;
use App\Services\Llaves\AdministradorLlaves;
use App\Services\Vouchers\RecuperacionVouchers;
use App\Support\Csv;
use App\Support\HoraLocal;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Catálogo de llaves (réplica de modules/llaves de SEGCAT: "Control de
 * Llaves"): fichas con horarios y caducidad, alta y edición con lo que abre
 * cada llave (tomado de Zonas y áreas), baja con voucher de reposición,
 * etiquetas de llaveros con QR y exportación.
 *
 * El préstamo de llaves (bitácora) vive en Operación y usa este catálogo.
 */
class LlaveController extends Controller
{
    /** Máximo de etiquetas por hoja de impresión. */
    public const MAX_ETIQUETAS = 120;

    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly AdministradorLlaves $llaves,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('llaves.ver');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);

        if ($empresaId === null) {
            return view('seguridad.llaves.index', ['sinEmpresa' => true]);
        }

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $actor, $empresaId) {
            $lista = $this->consulta($actor, 'llaves.ver')
                ->leftJoin('users as uc', 'uc.id', '=', 'llaves.creado_por')
                ->leftJoin('users as ua', 'ua.id', '=', 'llaves.actualizado_por')
                // addSelect: consulta() ya trae llaves.* y "en_uso" (Préstamo de llaves)
                ->addSelect(['uc.name as creado_por_nombre', 'ua.name as actualizado_por_nombre'])
                ->orderByDesc('llaves.id')
                ->get();

            $puedeCrear = $actor->can('llaves.crear');
            $puedeEditar = $actor->can('llaves.editar');
            $conFormulario = $puedeCrear || $puedeEditar;

            $sedes = Sede::orderBy('nombre')->get(['id', 'nombre', 'activo']);
            $verSedes = $this->llaves->sedes($actor, 'llaves.ver');
            $crearSedes = $this->llaves->sedes($actor, 'llaves.crear');
            $editarSedes = $this->llaves->sedes($actor, 'llaves.editar');
            $idsFormulario = $this->sedesDelFormulario($actor, $sedes, $lista);
            // Los lugares (miles con un hotel grande) se piden al abrir el diálogo (lugares());
            // solo vienen ya armados cuando un formulario regresa con errores
            $dialogo = $request->old('_dialogo');
            $trasError = $dialogo === 'crear' || (is_string($dialogo) && str_starts_with($dialogo, 'editar-'));

            return view('seguridad.llaves.index', [
                'sinEmpresa' => false,
                'llaves' => $lista,
                'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial'),
                'sedesFiltro' => $sedes->filter(fn ($s) => $verSedes === null || in_array($s->id, $verSedes, true))
                    ->filter(fn ($s) => $s->activo || $lista->contains('sede_id', $s->id))->values(),
                'sedesAlta' => $sedes->filter(fn ($s) => $s->activo && ($crearSedes === null || in_array($s->id, $crearSedes, true)))->values(),
                'sedesEdicion' => $sedes->filter(fn ($s) => $editarSedes === null || in_array($s->id, $editarSedes, true))->values(),
                'catalogos' => $conFormulario ? $this->catalogos($idsFormulario, $lista, $trasError) : null,
                'editables' => $this->llaves->idsEnAlcance($actor, 'llaves.editar'),
                'desactivables' => $this->llaves->idsEnAlcance($actor, 'llaves.eliminar'),
                'imprimibles' => $this->llaves->idsEnAlcance($actor, 'llaves.imprimir'),
                'costos' => $actor->can('llaves.eliminar') ? $this->llaves->costosSugeridos() : [],
                // Para volver a mostrar al responsable elegido si el formulario regresa con errores
                'responsableAnterior' => old('colaborador_id') ? Colaborador::whereKey((int) old('colaborador_id'))->first(['id', 'num_empleado', 'nombre', 'apellido_paterno', 'apellido_materno']) : null,
                'puede' => [
                    'crear' => $puedeCrear && $crearSedes !== [],
                    'editar' => $puedeEditar,
                    'estado' => $actor->can('llaves.eliminar'),
                    'imprimir' => $actor->can('llaves.imprimir'),
                    'exportar' => $actor->can('llaves.exportar'),
                ],
                // Mejoras padrones (ADR-0015): motivo y enlace del voucher de las llaves de baja
                'vouchersBaja' => app(RecuperacionVouchers::class)->ultimosPorOrigen('llave', $lista->where('activo', false)->pluck('id')),
                'puedeVerVouchers' => $actor->can('vouchers.ver'),
            ]);
        });
    }

    /**
     * Lugares del diálogo «Nueva Llave» o «Editar Llave» (edificios, pisos,
     * cuartos y secciones de Zonas y edificios), pedidos al abrirlo: con miles
     * de habitaciones pesan megas y no deben viajar en cada visita. Mismo
     * permiso y mismas sedes que el formulario de la pantalla; las casillas
     * llegan sin marcar y el diálogo de edición marca las de la llave.
     */
    public function lugares(Request $request): View
    {
        Gate::authorize('llaves.ver');
        $modo = $request->query('modo') === 'editar' ? 'editar' : 'nueva';
        Gate::authorize($modo === 'editar' ? 'llaves.editar' : 'llaves.crear');
        $empresaId = $this->empresaDeTrabajo($request);
        $actor = $request->user();

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $modo) {
            // Solo lo que el formulario usa de las llaves visibles: su sede y sus lugares (también los ya desactivados)
            $lista = $this->llaves->limitar(Llave::query(), $actor, 'llaves.ver')
                ->with(['espacios:espacios.id', 'grupos:grupos_espacio.id'])->get(['llaves.id', 'llaves.sede_id']);
            $sedes = Sede::orderBy('nombre')->get(['id', 'nombre', 'activo']);

            return view('seguridad.llaves._lugares', [
                'p' => $modo.'_ll_',
                'lugares' => $this->lugaresDelFormulario($this->sedesDelFormulario($actor, $sedes, $lista), $lista),
                'marcados' => [],
                'gruposMarcados' => [],
            ]);
        });
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('llaves.crear');
        $empresaId = $this->empresaDeTrabajo($request);

        $llave = $this->tenant->conEmpresa($empresaId, fn () => $this->llaves->crear($request->user(), $request->all()));

        $redireccion = redirect()->to(route('llaves.index').'#llave-'.$llave->id)
            ->with('ok', "Llave {$llave->nomenclatura} registrada correctamente.");
        // Mejoras padrones (criterio 5): el siguiente paso es su etiqueta
        if ($request->user()->can('llaves.imprimir')) {
            $redireccion->with('siguiente_paso', ['texto' => 'Imprimir su etiqueta', 'icono' => 'bi-printer', 'nueva_pestana' => true,
                'url' => route('llaves.imprimir', ['llaves' => [$llave->id]])]);
        }

        return $redireccion;
    }

    public function update(Request $request, int $llave): RedirectResponse
    {
        Gate::authorize('llaves.editar');
        $empresaId = $this->empresaDeTrabajo($request);

        $modelo = $this->tenant->conEmpresa($empresaId, function () use ($request, $llave) {
            $modelo = $this->buscarEnAlcance($request->user(), $llave, 'llaves.editar');

            return $this->llaves->actualizar($request->user(), $modelo, $request->all());
        });

        return redirect()->to(route('llaves.index').'#llave-'.$modelo->id)->with('ok', "Llave {$modelo->nomenclatura} actualizada correctamente.");
    }

    /**
     * Baja con voucher de reposición (SEGCAT: llave_proceso.php?accion=eliminar).
     */
    public function baja(Request $request, int $llave): RedirectResponse
    {
        Gate::authorize('llaves.eliminar');
        $empresaId = $this->empresaDeTrabajo($request);

        [$modelo, $voucher] = $this->tenant->conEmpresa($empresaId, function () use ($request, $llave) {
            $modelo = $this->buscarEnAlcance($request->user(), $llave, 'llaves.eliminar');

            return [$modelo, $this->llaves->darDeBaja($request->user(), $modelo, $request->all())];
        });

        return redirect()->to(route('llaves.index').'#llave-'.$modelo->id)->with('aviso', "Llave {$modelo->nomenclatura} dada de baja. "
            ."Se generó el voucher de reposición {$voucher->folio}".($voucher->aplica_cobro ? ' con cobro de $'.number_format((float) $voucher->monto, 2).'.' : ' sin cobro.')
            .($voucher->firma_modo === 'fisica' ? ' Imprímelo en Vouchers de reposición para firmarlo a mano.' : '')
            .' Si aparece, puedes reactivarla con un clic.');
    }

    public function reactivar(Request $request, int $llave): RedirectResponse
    {
        Gate::authorize('llaves.eliminar');
        $empresaId = $this->empresaDeTrabajo($request);

        [$modelo, $resultado, $voucher] = $this->tenant->conEmpresa($empresaId, function () use ($request, $llave) {
            $modelo = $this->buscarEnAlcance($request->user(), $llave, 'llaves.eliminar');
            // Mejoras padrones (ADR-0015): reactivar = «Recuperado» de su voucher (un solo camino, mismo resultado)
            [$resultado, $voucher] = app(RecuperacionVouchers::class)->desdeFicha($request->user(), 'llave', $modelo);
            if ($resultado === 'sin_voucher') {
                $this->llaves->reactivar($request->user(), $modelo);
            }

            return [$modelo, $resultado, $voucher];
        });

        if ($resultado === 'con_cobro') {
            return redirect()->to(route('vouchers.index', ['q' => $voucher->folio, 'recuperar' => $voucher->id]).'#voucher-'.$voucher->id)
                ->with('aviso', "La llave {$modelo->nomenclatura} tiene el voucher {$voucher->folio} con cobro: confirma aquí si ya se le cobró al responsable y se reactivará al guardar.");
        }

        // Mejoras padrones: ya no se abre solo su «Código e identificación»; se ofrece como siguiente paso
        return redirect()->to(route('llaves.index').'#llave-'.$modelo->id)
            ->with('ok', "Llave {$modelo->nomenclatura} reactivada correctamente."
                .match (true) {
                    $resultado === 'recuperado' => " Su voucher {$voucher->folio} quedó «Cancelado por recuperación».",
                    $voucher !== null => " Su voucher {$voucher->folio} sigue vigente: márcalo «Recuperado» en Vouchers de reposición.",
                    default => '',
                })
            ->with('siguiente_paso', ['texto' => 'Ver su código QR (reimprimir o cambiar tarjeta)', 'icono' => 'bi-qr-code',
                'url' => route('llaves.index', ['qr' => $modelo->id]).'#llave-'.$modelo->id]);
    }

    /**
     * Etiquetas de llaveros (SEGCAT: llave_imprimir.php, "Etiquetas de
     * Llaveros Listas"). El QR se dibuja aquí en SVG y solo lleva la
     * dirección /e/{código}: nada de datos y nada de servicios externos.
     */
    public function imprimir(Request $request): View|RedirectResponse
    {
        Gate::authorize('llaves.imprimir');
        $empresaId = $this->empresaDeTrabajo($request);
        $ids = collect((array) $request->query('llaves', []))->filter(fn ($v) => is_scalar($v) && ctype_digit((string) $v))
            ->map(fn ($v) => (int) $v)->unique()->take(self::MAX_ETIQUETAS)->values()->all();

        if ($ids === []) {
            return redirect()->route('llaves.index')->with('aviso', 'Marca al menos una llave para imprimir sus etiquetas.');
        }

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $ids, $empresaId) {
            $llaves = $this->consulta($request->user(), 'llaves.imprimir')->whereIn('llaves.id', $ids)->orderBy('llaves.nomenclatura')->get();
            abort_if($llaves->isEmpty(), 404);
            // Mejoras padrones (ADR-0015): si la empresa imprime con plantillas, un solo camino (Etiquetas QR)
            if ($plantillas = app(ImpresionDesdePadron::class)->redirigir($request->user(), $empresaId, 'llave', $llaves->pluck('id')->map(fn ($id) => (int) $id)->all())) {
                return $plantillas;
            }

            $escritor = new Writer(new ImageRenderer(new RendererStyle(160, 1), new SvgImageBackEnd));

            return view('seguridad.llaves.etiquetas', [
                'llaves' => $llaves,
                'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial'),
                'qrs' => $llaves->mapWithKeys(fn (Llave $l) => [$l->id => preg_replace('/^<\?xml[^>]*>\s*/', '', $escritor->writeString(route('lector.ir', $l->codigo_qr)))]),
            ]);
        });
    }

    /**
     * CSV del catálogo con los mismos filtros de la pantalla (sede, tipo,
     * estado, caducidad y texto), con BOM para que Excel respete los acentos.
     */
    public function exportar(Request $request, HoraLocal $hora): StreamedResponse
    {
        Gate::authorize('llaves.exportar');
        $empresaId = $this->empresaDeTrabajo($request);
        $filtros = $request->validate([
            'sede' => ['nullable', 'integer'],
            'tipo' => ['nullable', 'string', 'in:'.implode(',', array_keys(Llave::TIPOS_DISPOSITIVO))],
            'estado' => ['nullable', 'in:1,0'],
            'caducidad' => ['nullable', 'in:vencida,pronto,ok,sin'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $llaves = $this->tenant->conEmpresa($empresaId, function () use ($request, $filtros) {
            $consulta = $this->consulta($request->user(), 'llaves.exportar')
                ->leftJoin('users as uc', 'uc.id', '=', 'llaves.creado_por')
                ->select(['llaves.*', 'uc.name as creado_por_nombre'])
                ->when(isset($filtros['sede']), fn ($q) => $q->where('llaves.sede_id', (int) $filtros['sede']))
                ->when(isset($filtros['tipo']), fn ($q) => $q->where('llaves.tipo_dispositivo', $filtros['tipo']))
                ->when(isset($filtros['estado']), fn ($q) => $q->where('llaves.activo', $filtros['estado'] === '1'))
                ->orderBy('llaves.nomenclatura');

            $lista = $consulta->get();
            if (isset($filtros['caducidad'])) {
                $lista = $lista->filter(fn (Llave $l) => ($l->caducidad()['clase'] ?? 'sin') === $filtros['caducidad'])->values();
            }
            if (! empty($filtros['q'])) {
                $texto = mb_strtolower($filtros['q']);
                $lista = $lista->filter(fn (Llave $l) => str_contains(self::textoBusqueda($l), $texto))->values();
            }

            return $lista;
        });

        return response()->streamDownload(function () use ($llaves, $hora) {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\xEF\xBB\xBF"); // para que Excel respete los acentos
            Csv::fila($salida, ['Nombre de la llave', 'Descripción de accesos', 'Sede', 'Tipo de dispositivo', 'Alcance de apertura', 'Lugares que abre',
                'Departamento', 'Puesto objetivo', 'Responsable', 'ID externo', 'Plataforma', 'Fecha de caducidad', 'Caducidad', 'Horarios',
                'Etiqueta NFC / RFID', 'Estado', 'Creada por', 'Fecha de alta']);
            foreach ($llaves as $l) {
                Csv::fila($salida, [
                    $l->nomenclatura, $l->descripcion, $l->sede?->nombre, $l->etiquetaTipo(), $l->etiquetaAlcance(), implode(', ', $l->lugares()),
                    $l->departamento?->nombre, $l->puesto?->nombre, $l->colaborador?->nombreCompleto(), $l->id_externo, $l->plataforma_externa,
                    $hora->formatear($l->caducidadParaMostrar(), 'd/m/Y'), $l->caducidad()['texto'] ?? 'Sin caducidad',
                    $l->horarios->isEmpty() ? '24 horas' : $l->horarios->map(fn ($h) => $h->nombre.' '.$h->inicio().'-'.$h->fin())->join(', '),
                    $l->etiqueta_nfc, $l->activo ? 'Activa' : 'Baja', $l->creado_por_nombre, $hora->formatear($l->created_at),
                ]);
            }
            fclose($salida);
        }, 'catalogo-llaves-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Texto con el que se busca una llave (el mismo en la pantalla y en la exportación).
     */
    public static function textoBusqueda(Llave $l): string
    {
        return mb_strtolower(implode(' ', array_filter([
            $l->nomenclatura, $l->descripcion, $l->sede?->nombre, $l->etiquetaTipo(), $l->etiquetaAlcance(), implode(' ', $l->lugares()),
            $l->departamento?->nombre, $l->puesto?->nombre, $l->colaborador?->nombreCompleto(), $l->colaborador?->num_empleado,
            $l->id_externo, $l->plataforma_externa, $l->horarios->pluck('nombre')->join(' '),
        ])));
    }

    /**
     * Llaves visibles con un permiso, con todo lo que se muestra ya cargado.
     *
     * @return Builder<Llave>
     */
    private function consulta(User $actor, string $permiso): Builder
    {
        return $this->llaves->limitar(Llave::query(), $actor, $permiso)->with([
            'sede:id,nombre', 'departamento:id,nombre', 'puesto:id,nombre',
            'colaborador:id,num_empleado,nombre,apellido_paterno,apellido_materno',
            'horarios', 'espacios:id,nombre,nivel,padre_id', 'espacios.padre:id,nombre', 'grupos:id,nombre',
            // Préstamo de llaves: insignia "EN USO" y "Usada por" (ver docs/tecnico/prestamo-llaves.md)
            'prestamoAbierto:id,llave_id,colaborador_id,prestado_en', 'prestamoAbierto.colaborador:id,num_empleado,nombre,apellido_paterno,apellido_materno',
        ])->withExists(['prestamoAbierto as en_uso']);
    }

    /**
     * Sedes del formulario: las activas permitidas para crear o editar y las
     * de las llaves que ya se ven.
     *
     * @param  \Illuminate\Support\Collection<int, Sede>  $sedes
     * @param  Collection<int, Llave>  $lista
     * @return list<int>
     */
    private function sedesDelFormulario(User $actor, \Illuminate\Support\Collection $sedes, Collection $lista): array
    {
        $sedesFormulario = $this->unirSedes($this->llaves->sedes($actor, 'llaves.crear'), $this->llaves->sedes($actor, 'llaves.editar'));

        return $sedes->filter(fn ($s) => ($sedesFormulario === null || in_array($s->id, $sedesFormulario, true)) && $s->activo)->pluck('id')
            ->merge($lista->pluck('sede_id'))->unique()->values()->all();
    }

    /**
     * Opciones del formulario: departamentos y puestos; con $conLugares,
     * también los lugares (solo cuando el formulario regresa con errores: si
     * no, se piden al abrir el diálogo con lugares()).
     *
     * @param  list<int>  $sedes
     * @param  Collection<int, Llave>  $lista
     * @return array<string, mixed>
     */
    private function catalogos(array $sedes, Collection $lista, bool $conLugares): array
    {
        $usados = fn (string $campo) => $lista->pluck($campo)->filter()->unique()->values();

        return [
            'departamentos' => Departamento::with('sedes:sedes.id')
                ->where(fn ($q) => $q->where('activo', true)->orWhereIn('id', $usados('departamento_id')))
                ->orderBy('nombre')->get(['id', 'nombre', 'todas_las_sedes', 'activo']),
            'puestos' => Puesto::with('departamentos:departamentos.id')
                ->where(fn ($q) => $q->where('activo', true)->orWhereIn('id', $usados('puesto_id')))
                ->orderBy('nombre')->get(['id', 'nombre', 'activo']),
            'lugares' => $conLugares ? $this->lugaresDelFormulario($sedes, $lista) : null,
        ];
    }

    /**
     * Lugares de Zonas y edificios y secciones de las sedes del formulario
     * (los activos y los desactivados que ya tiene alguna llave visible).
     *
     * @param  list<int>  $sedes
     * @param  Collection<int, Llave>  $lista
     * @return array<string, mixed>
     */
    private function lugaresDelFormulario(array $sedes, Collection $lista): array
    {
        return [
            'espacios' => Espacio::with(['padre:id,nombre,padre_id,nivel', 'padre.padre:id,nombre'])->whereIn('sede_id', $sedes)
                ->whereIn('nivel', array_values(Llave::NIVEL_DEL_ALCANCE))
                ->where(fn ($q) => $q->where('activo', true)->orWhereIn('id', $lista->flatMap(fn ($l) => $l->espacios->pluck('id'))->unique()->values()))
                ->orderBy('ruta')->get(['id', 'sede_id', 'padre_id', 'nivel', 'nombre', 'activo'])
                ->sortBy('nombre', SORT_NATURAL)->values(),
            'grupos' => GrupoEspacio::withCount('espacios')->whereIn('sede_id', $sedes)
                ->where(fn ($q) => $q->where('activo', true)->orWhereIn('id', $lista->flatMap(fn ($l) => $l->grupos->pluck('id'))->unique()->values()))
                ->orderBy('nombre')->get(['id', 'sede_id', 'nombre', 'activo']),
        ];
    }

    /**
     * @param  list<int>|null  $a
     * @param  list<int>|null  $b
     * @return list<int>|null
     */
    private function unirSedes(?array $a, ?array $b): ?array
    {
        return $a === null || $b === null ? null : array_values(array_unique([...$a, ...$b]));
    }

    private function empresaDeTrabajo(Request $request): int
    {
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        return $empresaId;
    }

    /**
     * Una llave de otra empresa o fuera del alcance del permiso responde 404.
     */
    private function buscarEnAlcance(User $actor, int $id, string $permiso): Llave
    {
        $modelo = $this->llaves->limitar(Llave::query(), $actor, $permiso)->find($id);
        abort_if($modelo === null, 404);

        return $modelo;
    }
}
