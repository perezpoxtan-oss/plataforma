<?php

namespace App\Services\Vouchers;

use App\Models\Equipo;
use App\Models\Gafete;
use App\Models\Llave;
use App\Models\User;
use App\Models\VoucherReposicion;
use App\Services\Equipos\AdministradorEquipos;
use App\Services\Gafetes\AdministradorGafetes;
use App\Services\Llaves\AdministradorLlaves;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Autorizador;
use App\Support\Entrada;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ronda 6 (GV-04): el artículo de un voucher apareció y se devolvió.
 *
 * Estados (VoucherReposicion::ESTADOS) y transiciones permitidas:
 *
 *   vigente ──Recuperado──▶ cancelado_recuperacion   (sin cobro, o el cobro no se había pagado: se cancela)
 *   vigente ──Recuperado──▶ reembolso_pendiente      (ya se le había cobrado al responsable)
 *   reembolso_pendiente ──Reembolsado──▶ reembolsado
 *
 * Cualquier otra (recuperar dos veces, reembolsar un vigente o uno ya
 * cancelado…) se rechaza con un mensaje claro. «Recuperado» reactiva la
 * llave, el gafete o el equipo con las mismas reglas de su módulo (y su
 * auditoría) y deja la suya: vouchers.recuperado / vouchers.reembolsado.
 *
 * Corre con la empresa de trabajo fijada en el Tenant.
 */
class RecuperacionVouchers
{
    public function __construct(
        private readonly AdministradorRoles $auditoria,
        private readonly Autorizador $autorizador,
    ) {}

    /** Permiso de reactivar en el módulo de origen (el mismo del botón «Reactivar» de su ficha). */
    public function permisoReactivar(VoucherReposicion $voucher): string
    {
        return $voucher->moduloOrigen().'.eliminar';
    }

    /** ¿Puede marcarlo «Recuperado»? (vigente y con permiso de reactivar en el origen) */
    public function puedeRecuperar(User $actor, VoucherReposicion $voucher): bool
    {
        return $voucher->esVigente() && $actor->can('vouchers.editar') && $actor->can($this->permisoReactivar($voucher));
    }

    /**
     * @param  array<string, mixed>  $entrada  cobro_pagado (bool, solo con cobro), comentario
     */
    public function recuperar(User $actor, VoucherReposicion $voucher, array $entrada): VoucherReposicion
    {
        if (! $voucher->esVigente()) {
            throw ValidationException::withMessages(['comentario' => "El voucher {$voucher->folio} ya está «{$voucher->etiquetaEstado()}»: no se puede marcar como recuperado otra vez."]);
        }
        $comentario = $this->comentario($entrada['comentario'] ?? null);
        $conCobro = $voucher->aplica_cobro && (float) $voucher->monto > 0;
        $pagado = $conCobro && filter_var($entrada['cobro_pagado'] ?? false, FILTER_VALIDATE_BOOL);
        if ($pagado && $comentario === null) {
            throw ValidationException::withMessages(['comentario' => 'Escribe cómo se va a devolver el dinero (por ejemplo: «se reembolsa en nómina»).']);
        }

        $origen = $voucher->origen();
        $mas = VoucherReposicion::where('origen_tipo', $voucher->origen_tipo)->where('origen_id', $voucher->origen_id)
            ->whereKeyNot($voucher->id)->where('id', '>', $voucher->id)->value('folio');
        if ($mas !== null && $origen !== null && ! $this->estaActivo($origen)) {
            throw ValidationException::withMessages(['comentario' => "Este artículo se volvió a dar de baja después con el voucher {$mas}: marca ese como recuperado."]);
        }
        if ($origen !== null && ! $this->autorizador->puede($actor, $this->permisoReactivar($voucher), $origen)) {
            abort(403);
        }

        $antes = $this->foto($voucher);
        DB::transaction(function () use ($actor, $voucher, $origen, $pagado, $comentario, $mas) {
            // Si ya estaba activo (alguien lo reactivó desde su ficha) o hay un voucher más nuevo, no se toca
            if ($origen !== null && $mas === null && ! $this->estaActivo($origen)) {
                $this->reactivar($actor, $origen);
            }
            $voucher->forceFill([
                'estado' => $pagado ? 'reembolso_pendiente' : 'cancelado_recuperacion',
                'recuperado_en' => now(),
                'recuperado_por' => $actor->id,
                'recuperacion_comentario' => $comentario,
                'actualizado_por' => $actor->id,
            ])->save();
        });

        $this->auditoria->auditar($actor, 'vouchers.recuperado', $voucher, $antes, $this->foto($voucher) + [
            'cobro' => match (true) {
                ! $voucher->aplica_cobro => 'sin cobro',
                $pagado => 'ya pagado: reembolso pendiente',
                default => 'cancelado',
            },
            'articulo_reactivado' => $origen !== null && $this->estaActivo($origen->refresh()),
        ]);

        return $voucher;
    }

    /**
     * @param  array<string, mixed>  $entrada  comentario
     */
    public function reembolsar(User $actor, VoucherReposicion $voucher, array $entrada): VoucherReposicion
    {
        if ($voucher->estado !== 'reembolso_pendiente') {
            throw ValidationException::withMessages(['comentario' => "El voucher {$voucher->folio} está «{$voucher->etiquetaEstado()}»: solo se marca como reembolsado uno con reembolso pendiente."]);
        }
        $antes = $this->foto($voucher);
        $voucher->forceFill([
            'estado' => 'reembolsado',
            'reembolsado_en' => now(),
            'reembolsado_por' => $actor->id,
            'reembolso_comentario' => $this->comentario($entrada['comentario'] ?? null),
            'actualizado_por' => $actor->id,
        ])->save();
        $this->auditoria->auditar($actor, 'vouchers.reembolsado', $voucher, $antes, $this->foto($voucher));

        return $voucher;
    }

    private function estaActivo(Model $origen): bool
    {
        return $origen instanceof Equipo ? $origen->estado !== 'baja' : (bool) $origen->getAttribute('activo');
    }

    private function reactivar(User $actor, Model $origen): void
    {
        match (true) {
            $origen instanceof Llave => app(AdministradorLlaves::class)->reactivar($actor, $origen),
            $origen instanceof Gafete => app(AdministradorGafetes::class)->reactivar($actor, $origen),
            $origen instanceof Equipo => app(AdministradorEquipos::class)->reactivar($actor, $origen),
            default => null,
        };
    }

    private function comentario(mixed $valor): ?string
    {
        $texto = trim(Entrada::texto(is_string($valor) ? $valor : ''));
        if (mb_strlen($texto) > 500) {
            throw ValidationException::withMessages(['comentario' => 'El comentario es muy largo (máximo 500 caracteres).']);
        }

        return $texto === '' ? null : $texto;
    }

    /**
     * @return array<string, mixed>
     */
    private function foto(VoucherReposicion $v): array
    {
        return [
            'estado' => $v->estado,
            'recuperado_en' => $v->recuperado_en?->toIso8601String(),
            'recuperacion_comentario' => $v->recuperacion_comentario,
            'reembolsado_en' => $v->reembolsado_en?->toIso8601String(),
            'reembolso_comentario' => $v->reembolso_comentario,
        ];
    }
}
