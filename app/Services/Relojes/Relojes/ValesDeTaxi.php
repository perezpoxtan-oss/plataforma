<?php

namespace App\Services\Relojes\Relojes;

use App\Models\MovimientoTransporte;
use App\Services\Relojes\Espera;
use App\Services\Relojes\Reloj;
use Illuminate\Support\Collection;

/**
 * Vale de taxi por Vo.Bo. (renglón 13; C-24, D-08). Ya tenía reloj y color;
 * ahora recuerda a quien da el Vo.Bo. en la sede y pasa a quien lo da en toda
 * la empresa. El guardia que lo registró se entera de si se aprobó o se
 * rechazó (BitacoraTransporte::autorizar / rechazar).
 */
class ValesDeTaxi extends Reloj
{
    public function clave(): string
    {
        return 'vales_taxi';
    }

    public function nombre(): string
    {
        return 'Vales de taxi por Vo.Bo.';
    }

    public function suplenteOmision(): string
    {
        return 'quien da el Vo.Bo. en toda la empresa (o el administrador)';
    }

    public function modulos(): array
    {
        return ['transporte'];
    }

    public function permiso(): ?string
    {
        return 'transporte.aprobar';
    }

    public function esperas(?int $empresaId): Collection
    {
        return MovimientoTransporte::where('estatus', 'no_llego')->where('anulado', false)->whereNull('autorizado_en')->whereNull('rechazado_en')
            ->orderBy('created_at')->limit(500)->get()
            ->map(fn (MovimientoTransporte $m) => new Espera('transporte_vale', (int) $m->id, $m->created_at ?? now(), 'Vale de taxi por Vo.Bo.: '.$m->folio(),
                route('transporte.show', $m->id), (int) $m->sede_id))->values();
    }

    public function responsables(Espera $e, ?int $empresaId): Collection
    {
        return $this->conPermiso($empresaId, 'transporte.aprobar', $e->sedeId);
    }

    /** D-01: el renglón de Mis pendientes que ya existía y lo cuenta. */
    public function renglonPropio(): ?string
    {
        return 'vales_taxi';
    }
}
