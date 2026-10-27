<?php

namespace App\Http\Controllers\Seguridad;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Gafete;
use App\Models\Sede;
use App\Models\User;
use App\Models\VoucherReposicion;
use App\Services\Activos\CustodiaActivos;
use App\Services\Gafetes\AdministradorGafetes;
use App\Services\Inventarios\Vouchers;
use App\Services\Lector\ImpresionDesdePadron;
use App\Services\Vouchers\RecuperacionVouchers;
use App\Support\Identidad;
use App\Support\Receptor;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Inventario de gafetes (réplica de modules/gafetes de SEGCAT): lotes por
 * sede y tipo, edición, etiqueta NFC / RFID, baja con voucher, reactivación
 * e impresión "doble vista" con el QR dibujado aquí mismo.
 */
class GafeteController extends Controller
{
    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly AdministradorGafetes $gafetes,
        private readonly Vouchers $vouchers,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('gafetes.ver');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);

        if ($empresaId === null) {
            return view('seguridad.gafetes.index', ['sinEmpresa' => true]);
        }

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $request) {
            $puede = [
                'crear' => $actor->can('gafetes.crear'),
                'editar' => $actor->can('gafetes.editar'),
                'estado' => $actor->can('gafetes.eliminar'),
                'imprimir' => $actor->can('gafetes.imprimir'),
                'voucher' => $actor->can('vouchers.imprimir'),
            ];
            $conFormulario = $puede['crear'] || $puede['editar'];

            $lista = $this->gafetes->limitar(Gafete::query(), $actor, 'gafetes.ver')
                ->with(['tipo:id,nombre', 'sede' => fn ($q) => $q->withTrashed()->select('id', 'nombre', 'codigo')])
                ->leftJoin('users as uc', 'uc.id', '=', 'gafetes.creado_por')
                ->leftJoin('users as ua', 'ua.id', '=', 'gafetes.actualizado_por')
                ->select(['gafetes.*', 'uc.name as creado_por_nombre', 'ua.name as actualizado_por_nombre'])
                ->orderByDesc('gafetes.id')
                ->get();

            $tipos = $this->gafetes->tipos($actor, $conFormulario);
            $permitidasVer = $this->gafetes->sedesPermitidas($actor, 'gafetes.ver');
            $permitidasCrear = $this->gafetes->sedesPermitidas($actor, 'gafetes.crear');
            $sedes = Sede::orderBy('nombre')->get(['id', 'nombre', 'codigo', 'activo']);

            // Costo sugerido por tipo (último usado al cobrar uno de ese tipo)
            $costos = $puede['estado'] ? $tipos->mapWithKeys(fn ($t) => [$t->id => $this->vouchers->costoSugerido('gafete', $t->nombre)]) : collect();

            return view('seguridad.gafetes.index', [
                'sinEmpresa' => false,
                'gafetes' => $lista,
                'tipos' => $tipos,
                'empresaNombre' => Empresa::whereKey($this->tenant->empresaId())->value('nombre_comercial'),
                // Para filtrar: las sedes que puede ver; para generar: las activas donde puede crear
                'sedesFiltro' => $permitidasVer === null ? $sedes : $sedes->whereIn('id', $permitidasVer)->values(),
                'sedesLote' => $sedes->where('activo', true)->when($permitidasCrear !== null, fn ($c) => $c->whereIn('id', $permitidasCrear))->values(),
                'editables' => $this->gafetes->idsEnAlcance($actor, 'gafetes.editar'),
                'desactivables' => $this->gafetes->idsEnAlcance($actor, 'gafetes.eliminar'),
                'imprimibles' => $this->gafetes->idsEnAlcance($actor, 'gafetes.imprimir'),
                'costos' => $costos,
                // C-16: lo elegido en el selector de responsable antes de que el formulario regresara con errores
                'responsableBaja' => Receptor::anterior('responsable'),
                'responsableAnterior' => $this->responsableAnterior($request),
                'voucherGenerado' => $puede['voucher'] ? session('voucher_generado') : null,
                'puede' => $puede,
                // Mejoras padrones (ADR-0015): «En uso» (prestado ahora en caseta) y el voucher de los dados de baja
                'prestados' => Gafete::query()->prestados()->pluck('gafetes.id')->map(fn ($id) => (int) $id)->all(),
                // C-14 / C-15: con quién está y su último préstamo; el voucher propone a quien lo tuvo
                'custodia' => $custodia = app(CustodiaActivos::class)->gafetesEnLista($lista->pluck('id')->map(fn ($id) => (int) $id)->all()),
                'propuestas' => $puede['estado'] ? $lista->filter(fn (Gafete $g) => $g->noRegreso())
                    ->mapWithKeys(fn (Gafete $g) => [$g->id => app(CustodiaActivos::class)->responsablePropuesto($g)])->all() : [],
                'puedeRecibir' => $actor->can('gafetes.editar') || $actor->can('accesos.editar'),
                'vouchersBaja' => app(RecuperacionVouchers::class)->ultimosPorOrigen('gafete', $lista->where('activo', false)->pluck('id')),
                'puedeVerVouchers' => $actor->can('vouchers.ver'),
            ]);
        });
    }

    /**
     * "Generar lote".
     */
    public function lote(Request $request): RedirectResponse
    {
        Gate::authorize('gafetes.crear');
        $empresaId = $this->empresaDeTrabajo($request);

        $gafetes = $this->tenant->conEmpresa($empresaId, fn () => $this->gafetes->generarLote($request->user(), $empresaId, $request->all()));
        $total = $gafetes->count();
        $rango = $total === 1 ? $gafetes->first()->nomenclatura : $gafetes->first()->nomenclatura.' a '.$gafetes->last()->nomenclatura;

        $redireccion = redirect()->route('gafetes.index');
        // Mejoras padrones (criterio 5): el siguiente paso es imprimir justo ese lote
        if ($request->user()->can('gafetes.imprimir')) {
            $redireccion->with('siguiente_paso', ['texto' => $total === 1 ? 'Imprimir este gafete' : 'Imprimir este lote', 'icono' => 'bi-printer', 'nueva_pestana' => true,
                'url' => route('gafetes.imprimir', ['gafetes' => $gafetes->pluck('id')->all()])]);
        }

        return $redireccion->with('ok', ($total === 1 ? 'Gafete generado' : "Lote de {$total} gafetes generado")." correctamente ({$rango}).");
    }

    public function update(Request $request, int $gafete): RedirectResponse
    {
        Gate::authorize('gafetes.editar');
        $empresaId = $this->empresaDeTrabajo($request);

        $modelo = $this->tenant->conEmpresa($empresaId, function () use ($request, $gafete) {
            $modelo = $this->buscarEnAlcance($request->user(), $gafete, 'gafetes.editar');

            return $this->gafetes->actualizar($request->user(), $modelo, $request->all());
        });

        return redirect()->to(route('gafetes.index').'#gafete-'.$modelo->id)->with('ok', "Gafete {$modelo->nomenclatura} actualizado correctamente.");
    }

    /**
     * Baja con voucher (Extraviado, Dañado, Robado).
     */
    public function baja(Request $request, int $gafete): RedirectResponse
    {
        Gate::authorize('gafetes.eliminar');
        $empresaId = $this->empresaDeTrabajo($request);

        [$modelo, $voucher] = $this->tenant->conEmpresa($empresaId, function () use ($request, $gafete) {
            $modelo = $this->buscarEnAlcance($request->user(), $gafete, 'gafetes.eliminar');

            return [$modelo, $this->gafetes->darDeBaja($request->user(), $modelo, $request->all())];
        });

        return redirect()->to(route('gafetes.index').'#gafete-'.$modelo->id)
            ->with('aviso', "Gafete {$modelo->nomenclatura} dado de baja. Se generó el voucher {$voucher->folio}".($voucher->aplica_cobro ? ' con cobro de $'.number_format((float) $voucher->monto, 2).'.' : ' sin cobro.').' Puedes reactivarlo con un clic si aparece.')
            ->with('voucher_generado', ['id' => $voucher->id, 'folio' => $voucher->folio]);
    }

    /**
     * C-15: «Recibir» un gafete que quedó «No regresó» y lo devolvieron
     * después. Lo puede hacer quien edita gafetes o quien atiende la Bitácora de accesos.
     */
    public function recibir(Request $request, int $gafete): RedirectResponse
    {
        abort_unless($request->user()->can('gafetes.editar') || $request->user()->can('accesos.editar'), 403);
        $empresaId = $this->empresaDeTrabajo($request);

        $modelo = $this->tenant->conEmpresa($empresaId, function () use ($request, $gafete) {
            $modelo = $this->buscarEnAlcance($request->user(), $gafete, 'gafetes.ver');
            $this->gafetes->recibir($request->user(), $modelo);

            return $modelo;
        });

        return redirect()->to(route('gafetes.index').'#gafete-'.$modelo->id)->with('ok', "Gafete {$modelo->nomenclatura} recibido: vuelve a estar DISPONIBLE para prestarse en caseta.");
    }

    public function reactivar(Request $request, int $gafete): RedirectResponse
    {
        Gate::authorize('gafetes.eliminar');
        $empresaId = $this->empresaDeTrabajo($request);

        [$modelo, $resultado, $voucher] = $this->tenant->conEmpresa($empresaId, function () use ($request, $gafete) {
            $modelo = $this->buscarEnAlcance($request->user(), $gafete, 'gafetes.eliminar');
            // Mejoras padrones (ADR-0015): reactivar = «Recuperado» de su voucher (un solo camino, mismo resultado)
            [$resultado, $voucher] = app(RecuperacionVouchers::class)->desdeFicha($request->user(), 'gafete', $modelo);
            if ($resultado === 'sin_voucher') {
                $this->gafetes->reactivar($request->user(), $modelo);
            }

            return [$modelo, $resultado, $voucher];
        });

        return $this->despuesDeReactivar($resultado, $voucher, "Gafete {$modelo->nomenclatura}", 'reactivado',
            route('gafetes.index').'#gafete-'.$modelo->id);
    }

    /**
     * Mejoras padrones (ADR-0015): mensaje y destino después de «Reactivar»
     * según lo que pasó con su voucher (ver RecuperacionVouchers::desdeFicha).
     */
    private function despuesDeReactivar(string $resultado, ?VoucherReposicion $voucher, string $articulo, string $reactivado, string $volver): RedirectResponse
    {
        return match ($resultado) {
            'con_cobro' => redirect()->to(route('vouchers.index', ['q' => $voucher->folio, 'recuperar' => $voucher->id]).'#voucher-'.$voucher->id)
                ->with('aviso', "{$articulo} tiene el voucher {$voucher->folio} con cobro: confirma aquí si ya se le cobró al responsable; al confirmar se reactiva."),
            'recuperado' => redirect()->to($volver)->with('ok', "{$articulo} {$reactivado}. Su voucher {$voucher->folio} quedó «Cancelado por recuperación»."),
            default => redirect()->to($volver)->with('ok', "{$articulo} {$reactivado} correctamente."
                .($voucher ? " Su voucher {$voucher->folio} sigue vigente: márcalo «Recuperado» en Vouchers de reposición." : '')),
        };
    }

    /**
     * "Impresión doble vista (libro)" de los gafetes marcados (SEGCAT:
     * gafete_imprimir.php). El QR lleva solo la dirección /e/{código} y se
     * dibuja aquí en SVG: SEGCAT lo pedía a api.qrserver.com.
     */
    public function imprimir(Request $request): View|RedirectResponse
    {
        Gate::authorize('gafetes.imprimir');
        $empresaId = $this->empresaDeTrabajo($request);
        $ids = collect((array) $request->input('gafetes', []))->filter(fn ($id) => is_numeric($id))->map(fn ($id) => (int) $id)->unique()->take(500)->values();

        if ($ids->isEmpty()) {
            return redirect()->route('gafetes.index')->with('aviso', 'No marcaste ningún gafete para imprimir. Marca las casillas de los gafetes (o «Marcar todos») y presiona «Imprimir».');
        }

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $ids, $empresaId) {
            $gafetes = $this->gafetes->limitar(Gafete::query(), $request->user(), 'gafetes.imprimir')
                ->whereIn('gafetes.id', $ids)
                ->with(['tipo:id,nombre', 'sede' => fn ($q) => $q->withTrashed()->select('id', 'nombre', 'codigo')])
                ->get()
                ->sortBy(fn (Gafete $g) => [$g->sede?->nombre, $g->tipo?->nombre, $g->consecutivo, $g->nomenclatura])
                ->values();
            abort_if($gafetes->isEmpty(), 404);
            // Mejoras padrones (ADR-0015): si la empresa imprime con plantillas, un solo camino (Etiquetas QR)
            if ($plantillas = app(ImpresionDesdePadron::class)->redirigir($request->user(), $empresaId, 'gafete', $gafetes->pluck('id')->map(fn ($id) => (int) $id)->all())) {
                return $plantillas;
            }

            $escritor = new Writer(new ImageRenderer(new RendererStyle(150, 1), new SvgImageBackEnd));
            $qrs = $gafetes->mapWithKeys(fn (Gafete $g) => [
                // Sin la declaración XML: va incrustado en el HTML
                $g->id => preg_replace('/^<\?xml[^>]*>\s*/', '', $escritor->writeString(route('lector.ir', $g->codigo_qr))),
            ]);

            $empresa = Empresa::findOrFail($empresaId);

            return view('seguridad.gafetes.imprimir', [
                'gafetes' => $gafetes,
                'qrs' => $qrs,
                'empresa' => $empresa,
                'logo' => $this->logo($empresa),
            ]);
        });
    }

    /**
     * Logo para el frente del gafete: el de la empresa si tiene uno guardado
     * en public/; si no, el símbolo de la plataforma; si no, el recuadro
     * "Espacio logo" de SEGCAT.
     */
    private function logo(Empresa $empresa): ?string
    {
        foreach ([$empresa->logo_ruta, app(Identidad::class)->get('simbolo')] as $ruta) {
            if (is_string($ruta) && $ruta !== '' && ! str_contains($ruta, '..') && is_file(public_path($ruta))) {
                return asset($ruta);
            }
        }

        return null;
    }

    /**
     * Tras un error en el diálogo de baja: el responsable que ya se había
     * elegido, para mostrarlo otra vez.
     */
    private function responsableAnterior(Request $request): ?string
    {
        $id = $request->old('colaborador_id');
        if (! is_numeric($id)) {
            return null;
        }
        $colaborador = Colaborador::find((int) $id);

        return $colaborador === null ? null : $colaborador->nombreCompleto().' · Núm. '.$colaborador->num_empleado;
    }

    private function empresaDeTrabajo(Request $request): int
    {
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        return $empresaId;
    }

    /**
     * Un gafete de otra empresa o fuera del alcance del permiso responde 404.
     */
    private function buscarEnAlcance(User $actor, int $id, string $permiso): Gafete
    {
        $modelo = $this->gafetes->limitar(Gafete::query(), $actor, $permiso)->with('tipo:id,nombre')->find($id);
        abort_if($modelo === null, 404);

        return $modelo;
    }
}
