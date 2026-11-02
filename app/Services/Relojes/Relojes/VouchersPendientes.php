<?php

namespace App\Services\Relojes\Relojes;

use App\Models\VoucherReposicion;
use App\Services\Relojes\Espera;
use App\Services\Relojes\Reloj;
use Illuminate\Support\Collection;

/**
 * Vouchers que esperan algo (C-24, GC-09): la firma en papel que no se ha
 * registrado y el reembolso pendiente (se recuperó lo que se había cobrado).
 */
class VouchersPendientes extends Reloj
{
    public function clave(): string
    {
        return 'vouchers';
    }

    public function nombre(): string
    {
        return 'Vouchers por firmar o reembolsar';
    }

    public function suplenteOmision(): string
    {
        return 'quien administra los vouchers en toda la empresa (o el administrador)';
    }

    public function modulos(): array
    {
        return ['vouchers'];
    }

    public function permiso(): ?string
    {
        return 'vouchers.editar';
    }

    public function renglonDef(): ?array
    {
        return ['vouchers', 'Vouchers por firmar o reembolsar', 'bi-receipt', route('vouchers.index')];
    }

    public function textosInicio(): ?array
    {
        return ['1 voucher espera su firma en papel o su reembolso', '%d vouchers esperan su firma en papel o su reembolso',
            'Registra la hoja firmada o el reembolso para cerrarlos.', 'Ver vouchers'];
    }

    public function esperas(?int $empresaId): Collection
    {
        return VoucherReposicion::where(fn ($q) => $q->where(fn ($f) => $f->where('firma_modo', 'fisica')->whereNull('firmado_papel_en')->where('estado', 'vigente'))
            ->orWhere('estado', 'reembolso_pendiente'))->orderBy('id')->limit(500)->get()
            ->map(fn (VoucherReposicion $v) => new Espera('voucher_pendiente', (int) $v->id,
                $v->estado === 'reembolso_pendiente' ? ($v->recuperado_en ?? $v->updated_at ?? now()) : ($v->created_at ?? now()),
                'Voucher '.$v->folio.($v->estado === 'reembolso_pendiente' ? ': reembolso pendiente' : ': falta registrar la firma en papel'),
                route('vouchers.index').'#voucher-'.$v->id, $v->sede_id !== null ? (int) $v->sede_id : null))->values();
    }

    public function responsables(Espera $e, ?int $empresaId): Collection
    {
        return $this->conPermiso($empresaId, 'vouchers.editar', $e->sedeId);
    }
}
