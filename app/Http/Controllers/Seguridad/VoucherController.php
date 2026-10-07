<?php

namespace App\Http\Controllers\Seguridad;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\Sede;
use App\Models\VoucherReposicion;
use App\Services\Firmas\Firmas;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Autorizador;
use App\Services\Vouchers\ConsultaVouchers;
use App\Services\Vouchers\RecuperacionVouchers;
use App\Support\Entrada;
use App\Support\HoraLocal;
use App\Support\ImagenSegura;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
                // Ronda 5: quién registró la firma en papel
                ->leftJoin('users as up', 'up.id', '=', 'vouchers_reposicion.firmado_papel_por')
                // Ronda 6 (GV-04): quién lo marcó recuperado / reembolsado
                ->leftJoin('users as ur', 'ur.id', '=', 'vouchers_reposicion.recuperado_por')
                ->leftJoin('users as ue', 'ue.id', '=', 'vouchers_reposicion.reembolsado_por')
                ->select(['vouchers_reposicion.*', 'uc.name as creado_por_nombre', 'up.name as firmado_papel_por_nombre',
                    'ur.name as recuperado_por_nombre', 'ue.name as reembolsado_por_nombre'])
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
                // Ronda 6 (GV-04): «Recuperado» (vigente) y «Reembolsado» (reembolso pendiente)
                'puedeRecuperar' => $actor->can('vouchers.editar')
                    ? collect(VoucherReposicion::ORIGENES)->filter(fn ($o) => $actor->can($o[1].'.eliminar'))->keys()->all()
                    : [],
                'puedeReembolsar' => $actor->can('vouchers.editar'),
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
                ->leftJoin('users as ur', 'ur.id', '=', 'vouchers_reposicion.recuperado_por')
                ->leftJoin('users as ue', 'ue.id', '=', 'vouchers_reposicion.reembolsado_por')
                ->select(['vouchers_reposicion.*', 'uc.name as creado_por_nombre', 'ur.name as recuperado_por_nombre', 'ue.name as reembolsado_por_nombre'])
                ->find($voucher);
            abort_if($modelo === null, 404);

            return view('seguridad.vouchers.imprimir', [
                'voucher' => $modelo,
                'empresaNombre' => Empresa::whereKey($modelo->empresa_id)->value('nombre_comercial'),
            ]);
        });
    }

    /**
     * Ronda 5 (LL-04): firma digital (seguridad | responsable) u hoja firmada
     * escaneada (hoja), desde el disco privado. Solo con permiso y alcance.
     */
    public function firma(Request $request, Firmas $firmas, int $voucher, string $parte): StreamedResponse
    {
        Gate::authorize('vouchers.ver');
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $firmas, $voucher, $parte) {
            $modelo = $this->consulta->consulta($request->user())->find($voucher);
            abort_if($modelo === null, 404);

            return $firmas->respuesta(match ($parte) {
                'seguridad' => $modelo->firma_seguridad,
                'responsable' => $modelo->firma_responsable,
                default => $modelo->hoja_firmada,
            });
        });
    }

    /**
     * Ronda 5 (LL-04): firma física. Se marca "firmado en papel" y, si se
     * quiere, se sube la hoja escaneada o fotografiada (imagen limpia, disco
     * privado). Quien imprime vouchers lo registra.
     */
    public function papel(Request $request, Firmas $firmas, AdministradorRoles $auditoria, int $voucher): RedirectResponse
    {
        Gate::authorize('vouchers.imprimir');
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);
        $request->validate([
            'hoja' => ['nullable', 'file', 'max:6144', 'mimes:jpg,jpeg,png,webp'],
        ], [
            'hoja.max' => 'La foto de la hoja pesa demasiado (máximo 6 MB).',
            'hoja.mimes' => 'Sube la hoja como foto o imagen (JPG, PNG o WEBP).',
            'hoja.file' => 'No se pudo leer el archivo de la hoja firmada.',
        ]);

        $modelo = $this->tenant->conEmpresa($empresaId, function () use ($request, $firmas, $auditoria, $voucher, $empresaId) {
            $modelo = $this->consulta->consulta($request->user(), 'vouchers.imprimir')->find($voucher);
            abort_if($modelo === null, 404);
            if ($modelo->firma_modo === 'digital') {
                throw ValidationException::withMessages(['hoja' => "El voucher {$modelo->folio} ya se firmó digitalmente."]);
            }

            $antes = $modelo->only(['firmado_papel_en', 'hoja_firmada']);
            $anterior = $modelo->hoja_firmada;
            $hoja = $request->file('hoja');
            $modelo->forceFill([
                'firma_modo' => 'fisica',
                'firmado_papel_en' => $modelo->firmado_papel_en ?? now(),
                'firmado_papel_por' => $modelo->firmado_papel_por ?? $request->user()->id,
                'hoja_firmada' => $hoja ? ImagenSegura::guardar($hoja, "firmas/{$empresaId}/vouchers-hojas/".now()->format('Y/m'), 'hoja', 'local') : $anterior,
            ])->save();
            if ($hoja && $anterior !== null) {
                $firmas->borrar($anterior);
            }
            $auditoria->auditar($request->user(), 'vouchers.firmado_papel', $modelo, $antes, [
                'firmado_papel_en' => $modelo->firmado_papel_en?->toIso8601String(), 'hoja_firmada' => $modelo->hoja_firmada !== null,
            ]);

            return $modelo;
        });

        return redirect()->to(route('vouchers.index').'#voucher-'.$modelo->id)
            ->with('ok', "Voucher {$modelo->folio}: firma en papel registrada".($request->hasFile('hoja') ? ' con la hoja escaneada.' : '.'));
    }

    /**
     * Ronda 6 (GV-04): el artículo apareció y se devolvió. Reactiva la llave,
     * el gafete o el equipo y deja el voucher «Cancelado por recuperación» o,
     * si ya se había cobrado, «Reembolso pendiente».
     */
    public function recuperado(Request $request, RecuperacionVouchers $recuperacion, int $voucher): RedirectResponse
    {
        Gate::authorize('vouchers.editar');
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        $modelo = $this->tenant->conEmpresa($empresaId, function () use ($request, $recuperacion, $voucher) {
            $modelo = $this->consulta->consulta($request->user(), 'vouchers.editar')->find($voucher);
            abort_if($modelo === null, 404);
            abort_unless($request->user()->can($recuperacion->permisoReactivar($modelo)), 403);

            return $recuperacion->recuperar($request->user(), $modelo, $request->only(['cobro_pagado', 'comentario']));
        });

        return redirect()->to(route('vouchers.index').'#voucher-'.$modelo->id)->with('ok', match ($modelo->estado) {
            'reembolso_pendiente' => "Voucher {$modelo->folio}: artículo recuperado y reactivado. Queda «Reembolso pendiente» hasta que se le devuelva el dinero al responsable.",
            default => "Voucher {$modelo->folio}: artículo recuperado y reactivado. El voucher queda «Cancelado por recuperación»".($modelo->aplica_cobro ? ' y el cobro se canceló.' : '.'),
        });
    }

    /** Ronda 6 (GV-04): ya se le devolvió el dinero al responsable. */
    public function reembolso(Request $request, RecuperacionVouchers $recuperacion, int $voucher): RedirectResponse
    {
        Gate::authorize('vouchers.editar');
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        $modelo = $this->tenant->conEmpresa($empresaId, function () use ($request, $recuperacion, $voucher) {
            $modelo = $this->consulta->consulta($request->user(), 'vouchers.editar')->find($voucher);
            abort_if($modelo === null, 404);

            return $recuperacion->reembolsar($request->user(), $modelo, $request->only(['comentario']));
        });

        return redirect()->to(route('vouchers.index').'#voucher-'.$modelo->id)->with('ok', "Voucher {$modelo->folio}: reembolso entregado.");
    }

    /**
     * Filtros de la lista (en la dirección, para poder compartir la búsqueda).
     *
     * @return array{q: string, sede: ?int, origen: ?string, cobro: ?string, estado: ?string, desde: ?string, hasta: ?string}
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
            'estado' => array_key_exists(Entrada::texto($request->query('estado')), VoucherReposicion::ESTADOS) ? Entrada::texto($request->query('estado')) : null,
            'desde' => $fecha($request->query('desde')),
            'hasta' => $fecha($request->query('hasta')),
        ];
    }

    /**
     * @param  Builder<VoucherReposicion>  $consulta
     * @param  array{q: string, sede: ?int, origen: ?string, cobro: ?string, estado: ?string, desde: ?string, hasta: ?string}  $f
     * @return Builder<VoucherReposicion>
     */
    private function filtrar(Builder $consulta, array $f): Builder
    {
        $zona = $this->hora->zona();

        return $consulta
            ->when($f['sede'] !== null, fn ($q) => $q->where('vouchers_reposicion.sede_id', $f['sede']))
            ->when($f['origen'] !== null, fn ($q) => $q->where('vouchers_reposicion.origen_tipo', $f['origen']))
            ->when($f['cobro'] !== null, fn ($q) => $q->where('vouchers_reposicion.aplica_cobro', $f['cobro'] === '1'))
            ->when($f['estado'] !== null, fn ($q) => $q->where('vouchers_reposicion.estado', $f['estado']))
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
