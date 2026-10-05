<?php

namespace App\Http\Controllers\Seguridad;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Gafete;
use App\Models\Sede;
use App\Models\User;
use App\Services\Gafetes\AdministradorGafetes;
use App\Services\Inventarios\Vouchers;
use App\Support\Identidad;
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
                'responsableAnterior' => $this->responsableAnterior($request),
                'voucherGenerado' => $puede['voucher'] ? session('voucher_generado') : null,
                'puede' => $puede,
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

        return redirect()->route('gafetes.index')->with('ok', ($total === 1 ? 'Gafete generado' : "Lote de {$total} gafetes generado")." correctamente ({$rango}). Márcalos y presiona «Imprimir».");
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

    public function reactivar(Request $request, int $gafete): RedirectResponse
    {
        Gate::authorize('gafetes.eliminar');
        $empresaId = $this->empresaDeTrabajo($request);

        $modelo = $this->tenant->conEmpresa($empresaId, function () use ($request, $gafete) {
            $modelo = $this->buscarEnAlcance($request->user(), $gafete, 'gafetes.eliminar');
            $this->gafetes->reactivar($request->user(), $modelo);

            return $modelo;
        });

        return redirect()->to(route('gafetes.index').'#gafete-'.$modelo->id)->with('ok', "Gafete {$modelo->nomenclatura} reactivado correctamente.");
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
