<?php

namespace App\Http\Controllers\Seguridad;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\LostFoundArticulo;
use App\Models\LostFoundEntrega;
use App\Models\LostFoundUmbral;
use App\Models\Sede;
use App\Services\Firmas\Firmas;
use App\Services\Novedades\AdministradorNovedades;
use App\Services\Novedades\ArchivoLostFound;
use App\Services\Permisos\Autorizador;
use App\Support\HoraLocal;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Lost & Found — archivo (réplica de modules/bitacora/lf_archivo.php y sus
 * pantallas: cerrar / entregar, etiqueta, auditoría de inventario y días de
 * resguardo). Los artículos nacen en la Bitácora de Novedades (ticket de
 * Lost & Found); aquí se consultan, se entregan y se imprimen.
 *
 * Las reglas viven en App\Services\Novedades\ArchivoLostFound.
 */
class LostFoundController extends Controller
{
    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly ArchivoLostFound $archivo,
        private readonly AdministradorNovedades $novedades,
        private readonly Autorizador $autorizador,
    ) {}

    public function index(Request $request, HoraLocal $hora): View
    {
        Gate::authorize('lost_found.ver');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        if ($empresaId === null) {
            return view('seguridad.lost-found.index', ['sinEmpresa' => true]);
        }
        $f = $request->validate([
            'filtro' => ['nullable', 'string', 'in:'.implode(',', array_keys(ArchivoLostFound::FILTROS))],
            'q' => ['nullable', 'string', 'max:100'],
            'sede' => ['nullable', 'integer'],
            'tipo' => ['nullable', 'string', 'max:20'],
        ]);
        $f['filtro'] ??= 'todos';

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $empresaId, $f, $hora) {
            $umbrales = LostFoundUmbral::vigentes();
            $base = fn () => $this->archivo->limitar(LostFoundArticulo::query(), $actor, 'lost_found.ver');

            $conteos = $base()->toBase()->selectRaw('estatus, COUNT(*) as total')->groupBy('estatus')->pluck('total', 'estatus')->map(fn ($n) => (int) $n);
            $urgentes = $this->archivo->soloUrgentes($base(), $umbrales)->count();

            $consulta = $this->archivo->filtrar($base(), $f, $umbrales)->with([
                'sede:id,nombre', 'areaEspecifica:id,nombre', 'novedad:id,numero,ubicacion,estatus', 'creador:id,name', 'cerrador:id,name',
                'entrega', 'reportesVinculados:id,articulo_vinculado_id,folio,nombre_huesped,correo',
            ]);
            if ($f['filtro'] === 'urgentes') {
                // Aquí interesa ver primero lo más urgente (vencido), no por fecha
                $articulos = $consulta->orderBy('created_at')->limit(500)->get()
                    ->sortByDesc(fn (LostFoundArticulo $a) => $a->semaforo($umbrales)['clase'] === 'rojo' ? 1 : 0)->values();
                $paginador = null;
            } else {
                $paginador = $consulta->orderByDesc('created_at')->orderByDesc('id')->paginate(ArchivoLostFound::POR_PAGINA)->withQueryString();
                $articulos = collect($paginador->items());
            }

            $ids = $articulos->pluck('id')->all();
            $sedesFiltro = $this->sedesDe($actor, 'lost_found.ver');

            // "Cerrar / Entregar": el diálogo se vuelve a abrir si regresó con errores
            $dialogo = old('_dialogo');
            $cierreId = is_string($dialogo) && str_starts_with($dialogo, 'cerrar-') ? (int) substr($dialogo, 7) : null;

            return view('seguridad.lost-found.index', [
                'sinEmpresa' => false,
                'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial'),
                'articulos' => $articulos,
                'paginador' => $paginador,
                'porMes' => $f['filtro'] === 'urgentes' ? null : $articulos->groupBy(fn (LostFoundArticulo $a) => $hora->formatear($a->created_at, 'Y-m')),
                'sinArticulos' => $f['filtro'] === 'todos' && $this->novedades->puedeModulo($actor, 'ver')
                    ? $this->archivo->ticketsSinArticulos($actor, $f['q'] ?? null, isset($f['sede']) ? (int) $f['sede'] : null)->limit(50)->get() : collect(),
                'umbrales' => $umbrales,
                'filtros' => $f,
                'conteos' => [
                    'todos' => $conteos->sum(), 'resguardo' => $conteos['EN_RESGUARDO'] ?? 0, 'urgentes' => $urgentes,
                    'devueltos' => $conteos['DEVUELTO'] ?? 0, 'otros' => ($conteos['DONADO'] ?? 0) + ($conteos['DESTRUIDO'] ?? 0) + ($conteos['ENTREGADO_BENEFICENCIA'] ?? 0),
                ],
                'sedesFiltro' => $sedesFiltro,
                'variasSedes' => $sedesFiltro->count() > 1,
                'cerrables' => $this->idsCon($actor, 'lost_found.firmar', $ids),
                'imprimibles' => $this->idsCon($actor, 'lost_found.imprimir', $ids),
                'cierre' => $cierreId !== null ? $this->archivo->limitar(LostFoundArticulo::query(), $actor, 'lost_found.firmar')->find($cierreId) : null,
                'puede' => $this->puede($actor),
            ]);
        });
    }

    /**
     * Ficha del artículo: a donde lleva el QR de la etiqueta de la bolsa.
     */
    public function show(Request $request, int $articulo): View
    {
        Gate::authorize('lost_found.ver');
        $actor = $request->user();
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $articulo) {
            $modelo = $this->archivo->buscar($actor, $articulo, 'lost_found.ver');
            $modelo->load([
                'sede:id,nombre,zona_horaria,empresa_id', 'areaEspecifica:id,nombre', 'creador:id,name', 'editor:id,name', 'cerrador:id,name',
                'novedad:id,numero,sede_id,categoria,estatus,reportado_por,ubicacion,descripcion,creado_por,created_at', 'novedad.creador:id,name',
                'entrega.creador:id,name', 'entrega.colaborador:id,num_empleado,nombre,apellido_paterno,apellido_materno', 'entrega.persona:id,nombre_completo,tipo',
                'reportesVinculados:id,articulo_vinculado_id,novedad_id,folio,objeto,nombre_huesped,telefono,correo,vinculado_en',
                'robosVinculados:id,novedad_id,articulo_vinculado_id', 'robosVinculados.novedad:id,numero,estatus,ubicacion',
            ]);
            $dialogo = old('_dialogo');

            return view('seguridad.lost-found.show', [
                'a' => $modelo,
                'semaforo' => $modelo->semaforo(LostFoundUmbral::vigentes()),
                'umbrales' => LostFoundUmbral::vigentes(),
                'puedeCerrar' => $modelo->enResguardo() && $this->archivo->permite($actor, 'lost_found.firmar', $modelo),
                'puedeImprimir' => $this->archivo->permite($actor, 'lost_found.imprimir', $modelo),
                'puedeVerTicket' => $modelo->novedad !== null && $this->novedades->permite($actor, 'ver', $modelo->novedad),
                'puedeAcuse' => $modelo->novedad !== null && $this->novedades->permite($actor, 'imprimir', $modelo->novedad),
                'abrirCierre' => $dialogo === 'cerrar-'.$modelo->id,
                'puede' => $this->puede($actor),
            ]);
        });
    }

    /**
     * Registrar Cierre (SEGCAT: lf_articulo_proceso.php?accion=cerrar).
     */
    public function cerrar(Request $request, int $articulo): RedirectResponse
    {
        Gate::authorize('lost_found.firmar');
        $actor = $request->user();
        $empresaId = $this->empresaDeTrabajo($request);

        $modelo = $this->tenant->conEmpresa($empresaId, function () use ($request, $actor, $articulo) {
            $modelo = $this->archivo->buscar($actor, $articulo, 'lost_found.firmar');
            $this->archivo->cerrar($actor, $modelo, $request->except(['_token', '_dialogo', 'volver']));

            return $modelo;
        });

        // A dónde regresar: lista fija (nunca una dirección que mande el navegador)
        $destino = $request->input('volver') === 'ficha'
            ? route('lost_found.articulos.show', $modelo->id)
            : route('lost_found.archivo').'#articulo-'.$modelo->id;

        return redirect()->to($destino)->with('ok', "Cierre registrado correctamente: {$modelo->folio} quedó como «{$modelo->etiquetaEstatus()}».");
    }

    /**
     * Etiqueta para la bolsa del artículo (SEGCAT: lf_etiqueta.php) con el QR
     * dibujado aquí mismo: solo lleva la dirección /e/{código}.
     */
    public function etiqueta(Request $request, int $articulo): View
    {
        Gate::authorize('lost_found.imprimir');
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $articulo, $empresaId) {
            $modelo = $this->archivo->buscar($request->user(), $articulo, 'lost_found.imprimir');
            $modelo->load(['sede:id,nombre,zona_horaria,empresa_id', 'areaEspecifica:id,nombre']);
            $svg = (new Writer(new ImageRenderer(new RendererStyle(170, 1), new SvgImageBackEnd)))->writeString(route('lector.ir', $modelo->codigo_qr));

            return view('seguridad.lost-found.etiqueta', [
                'a' => $modelo,
                'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial'),
                'qr' => (string) preg_replace('/^<\?xml[^>]*>\s*/', '', $svg),
            ]);
        });
    }

    /**
     * Auditoría de Inventario (SEGCAT: lf_auditoria_pdf.php): lo que debería
     * estar físicamente en bodega hoy, para cotejarlo. Opcional: por sede y por
     * fecha en que se encontró.
     */
    public function auditoria(Request $request, HoraLocal $hora): View
    {
        Gate::authorize('lost_found.imprimir');
        $actor = $request->user();
        $empresaId = $this->empresaDeTrabajo($request);
        $f = $request->validate([
            'sede' => ['nullable', 'integer'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ], [
            'date_format' => 'Revisa la fecha (día/mes/año).',
            'hasta.after_or_equal' => '«Hasta» no puede ser antes que «Desde».',
        ]);

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $empresaId, $f, $hora) {
            $desde = isset($f['desde']) ? Carbon::createFromFormat('Y-m-d', $f['desde'], $hora->zona())->startOfDay()->utc() : null;
            $hasta = isset($f['hasta']) ? Carbon::createFromFormat('Y-m-d', $f['hasta'], $hora->zona())->endOfDay()->utc() : null;
            $articulos = $this->archivo->limitar(LostFoundArticulo::query(), $actor, 'lost_found.imprimir')
                ->where('lost_found_articulos.estatus', LostFoundArticulo::EN_RESGUARDO)
                ->when(! empty($f['sede']), fn ($q) => $q->where('lost_found_articulos.sede_id', (int) $f['sede']))
                ->when($desde !== null, fn ($q) => $q->where('lost_found_articulos.created_at', '>=', $desde))
                ->when($hasta !== null, fn ($q) => $q->where('lost_found_articulos.created_at', '<=', $hasta))
                ->with(['sede:id,nombre', 'areaEspecifica:id,nombre'])
                ->get()
                ->sortBy(fn (LostFoundArticulo $a) => [mb_strtolower($a->sede?->nombre ?? ''), mb_strtolower($a->ubicacion_bodega ?? ''), $a->folio])
                ->values();

            return view('seguridad.lost-found.auditoria', [
                'articulos' => $articulos,
                'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial'),
                'sede' => ! empty($f['sede']) ? Sede::find((int) $f['sede']) : null,
                'filtros' => $f,
                'variasSedes' => $articulos->pluck('sede_id')->unique()->count() > 1,
                'sedesFiltro' => $this->sedesDe($actor, 'lost_found.imprimir'),
            ]);
        });
    }

    /**
     * Días de Resguardo (SEGCAT: lf_config_umbrales.php). Lo ve quien ve Lost &
     * Found; lo cambia quien tiene "configurar" en toda la empresa.
     */
    public function umbrales(Request $request): View
    {
        Gate::authorize('lost_found.ver');
        $actor = $request->user();
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, fn () => view('seguridad.lost-found.umbrales', [
            'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial'),
            'dias' => LostFoundUmbral::vigentes(),
            'guardados' => LostFoundUmbral::with('editor:id,name')->get()->keyBy('tipo_valor'),
            'puedeEditar' => $this->archivo->puedeConfigurar($actor),
        ]));
    }

    public function guardarUmbrales(Request $request): RedirectResponse
    {
        Gate::authorize('lost_found.configurar');
        $actor = $request->user();
        abort_unless($this->archivo->puedeConfigurar($actor), 403, 'Los días de resguardo son de toda la empresa: hace falta el permiso «configurar» de Lost & Found con alcance de empresa.');
        $empresaId = $this->empresaDeTrabajo($request);

        $this->tenant->conEmpresa($empresaId, fn () => $this->archivo->guardarUmbrales($actor, $request->only('dias')));

        return redirect()->route('lost_found.umbrales')->with('ok', 'Los umbrales se guardaron correctamente.');
    }

    /**
     * Firma del cierre (disco privado), solo con permiso de ver el artículo y dentro de su alcance.
     */
    public function firma(Request $request, int $entrega): StreamedResponse
    {
        Gate::authorize('lost_found.ver');
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $entrega) {
            $modelo = LostFoundEntrega::with('articulo')->find($entrega);
            abort_if($modelo === null || $modelo->articulo === null || ! $this->archivo->permite($request->user(), 'lost_found.ver', $modelo->articulo), 404);

            return app(Firmas::class)->respuesta($modelo->firma_ruta);
        });
    }

    // ------------------------------------------------------------------ Apoyo

    /**
     * @return array<string, bool>
     */
    private function puede($actor): array
    {
        return [
            'firmar' => $actor->can('lost_found.firmar'),
            'imprimir' => $actor->can('lost_found.imprimir'),
            'configurar' => $this->archivo->puedeConfigurar($actor),
            'tickets' => $this->novedades->puedeModulo($actor, 'ver'),
            'persona' => $actor->can('visitantes.crear'),
        ];
    }

    /**
     * Ids (de los que se muestran) que el actor puede tocar con un permiso.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function idsCon($actor, string $permiso, array $ids): array
    {
        if ($ids === [] || ! $actor->can($permiso)) {
            return [];
        }

        return $this->archivo->limitar(LostFoundArticulo::query(), $actor, $permiso)->whereIn('lost_found_articulos.id', $ids)->pluck('lost_found_articulos.id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Sedes de la empresa donde aplica el permiso (para los filtros).
     *
     * @return Collection<int, Sede>
     */
    private function sedesDe($actor, string $permiso)
    {
        $sedes = $this->autorizador->sedesPermitidas($actor, $permiso);

        return Sede::orderBy('nombre')->get(['id', 'nombre'])->filter(fn ($s) => $sedes === null || in_array($s->id, $sedes, true))->values();
    }

    private function empresaDeTrabajo(Request $request): int
    {
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        return $empresaId;
    }
}
