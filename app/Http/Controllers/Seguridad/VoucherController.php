<?php

namespace App\Http\Controllers\Seguridad;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\Sede;
use App\Models\VoucherReposicion;
use App\Services\Permisos\Autorizador;
use App\Services\Vouchers\ConsultaVouchers;
use App\Support\Entrada;
use App\Support\HoraLocal;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Vouchers de reposición (réplica de modules/vouchers de SEGCAT). Solo
 * consulta e impresión: los vouchers nacen de las bajas de Llaves, Gafetes
 * y Equipos (App\Services\Inventarios\Vouchers) y no se editan ni se borran.
 */
class VoucherController extends Controller
{
    private const POR_PAGINA = 30;

    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly ConsultaVouchers $consulta,
        private readonly HoraLocal $hora,
        private readonly Autorizador $autorizador,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('vouchers.ver');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);

        if ($empresaId === null) {
            return view('seguridad.vouchers.index', ['sinEmpresa' => true]);
        }

        $filtros = $this->filtros($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $filtros) {
            $base = $this->consulta->consulta($actor);
            $hayVouchers = (clone $base)->exists();

            $vouchers = $this->filtrar($base, $filtros)
                ->with([
                    'sede' => fn ($q) => $q->withTrashed()->select('id', 'nombre'),
                    'colaborador:id,num_empleado,nombre,apellido_paterno,apellido_materno',
                ])
                ->leftJoin('users as uc', 'uc.id', '=', 'vouchers_reposicion.creado_por')
                ->select(['vouchers_reposicion.*', 'uc.name as creado_por_nombre'])
                ->orderByDesc('vouchers_reposicion.id')
                ->paginate(self::POR_PAGINA)
                ->withQueryString();

            $permitidas = $actor->es_superadmin ? null : $this->autorizador->sedesPermitidas($actor, 'vouchers.ver');

            return view('seguridad.vouchers.index', [
                'sinEmpresa' => false,
                'vouchers' => $vouchers,
                'hayVouchers' => $hayVouchers,
                'filtros' => $filtros,
                'origenes' => $this->consulta->origenesVisibles($actor),
                'sedes' => Sede::orderBy('nombre')->when($permitidas !== null, fn ($q) => $q->whereIn('id', $permitidas))->get(['id', 'nombre']),
                'empresaNombre' => Empresa::whereKey($this->tenant->empresaId())->value('nombre_comercial'),
                'puedeImprimir' => $actor->can('vouchers.imprimir'),
            ]);
        });
    }

    /**
     * "Voucher de reposición — 3 copias en una sola hoja" (Seguridad,
     * Colaborador y Recepción), para recortar y firmar a mano.
     */
    public function imprimir(Request $request, int $voucher): View
    {
        Gate::authorize('vouchers.ver');
        Gate::authorize('vouchers.imprimir');
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $voucher) {
            $modelo = $this->consulta->consulta($request->user(), 'vouchers.imprimir')
                ->with([
                    'sede' => fn ($q) => $q->withTrashed()->select('id', 'nombre'),
                    'colaborador:id,num_empleado,nombre,apellido_paterno,apellido_materno',
                ])
                ->leftJoin('users as uc', 'uc.id', '=', 'vouchers_reposicion.creado_por')
                ->select(['vouchers_reposicion.*', 'uc.name as creado_por_nombre'])
                ->find($voucher);
            abort_if($modelo === null, 404);

            return view('seguridad.vouchers.imprimir', [
                'voucher' => $modelo,
                'empresaNombre' => Empresa::whereKey($modelo->empresa_id)->value('nombre_comercial'),
            ]);
        });
    }

    /**
     * Filtros de la lista (en la dirección, para poder compartir la búsqueda).
     *
     * @return array{q: string, sede: ?int, origen: ?string, cobro: ?string, desde: ?string, hasta: ?string}
     */
    private function filtros(Request $request): array
    {
        $fecha = function (mixed $valor): ?string {
            if (! is_string($valor) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
                return null;
            }
            $f = CarbonImmutable::createFromFormat('!Y-m-d', $valor);

            return $f !== false && $f->format('Y-m-d') === $valor ? $valor : null;
        };

        return [
            'q' => mb_substr(trim(Entrada::texto($request->query('q', ''))), 0, 100),
            'sede' => is_numeric($request->query('sede')) ? (int) $request->query('sede') : null,
            'origen' => array_key_exists(Entrada::texto($request->query('origen')), VoucherReposicion::ORIGENES) ? Entrada::texto($request->query('origen')) : null,
            'cobro' => in_array($request->query('cobro'), ['1', '0'], true) ? $request->query('cobro') : null,
            'desde' => $fecha($request->query('desde')),
            'hasta' => $fecha($request->query('hasta')),
        ];
    }

    /**
     * @param  Builder<VoucherReposicion>  $consulta
     * @param  array{q: string, sede: ?int, origen: ?string, cobro: ?string, desde: ?string, hasta: ?string}  $f
     * @return Builder<VoucherReposicion>
     */
    private function filtrar(Builder $consulta, array $f): Builder
    {
        $zona = $this->hora->zona();

        return $consulta
            ->when($f['sede'] !== null, fn ($q) => $q->where('vouchers_reposicion.sede_id', $f['sede']))
            ->when($f['origen'] !== null, fn ($q) => $q->where('vouchers_reposicion.origen_tipo', $f['origen']))
            ->when($f['cobro'] !== null, fn ($q) => $q->where('vouchers_reposicion.aplica_cobro', $f['cobro'] === '1'))
            // Los días se cuentan en la hora local de la empresa
            ->when($f['desde'] !== null, fn ($q) => $q->where('vouchers_reposicion.created_at', '>=', CarbonImmutable::parse($f['desde'], $zona)->startOfDay()->utc()))
            ->when($f['hasta'] !== null, fn ($q) => $q->where('vouchers_reposicion.created_at', '<=', CarbonImmutable::parse($f['hasta'], $zona)->endOfDay()->utc()))
            ->when($f['q'] !== '', function ($q) use ($f) {
                $comodin = '%'.addcslashes($f['q'], '%_\\').'%';
                $q->where(fn ($b) => $b->where('vouchers_reposicion.folio', 'like', $comodin)
                    ->orWhere('vouchers_reposicion.origen_descripcion', 'like', $comodin)
                    ->orWhere('vouchers_reposicion.descripcion', 'like', $comodin)
                    ->orWhereHas('colaborador', fn ($c) => $c->where(fn ($n) => $n->where('nombre', 'like', $comodin)
                        ->orWhere('apellido_paterno', 'like', $comodin)->orWhere('apellido_materno', 'like', $comodin)
                        ->orWhere('num_empleado', 'like', $comodin))));
            });
    }
}
