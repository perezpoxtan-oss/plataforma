<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pasajero de un vale de taxi (MovimientoTransporte::pasajeros()). Con
 * empresa_id propia (docs/tecnico/integridad-bd.md): se toma del movimiento
 * al guardar y las consultas directas usan el filtro de empresa.
 */
class MovimientoTransportePasajero extends Pivot
{
    use PerteneceAEmpresa;

    protected $table = 'movimiento_transporte_pasajeros';

    public $incrementing = true;

    public $timestamps = false;

    protected $fillable = ['empresa_id', 'movimiento_transporte_id', 'colaborador_id'];

    protected static function booted(): void
    {
        // Sin empresa activa (consola, tareas programadas) se toma la del movimiento
        static::creating(function (self $p): void {
            if (empty($p->empresa_id)) {
                $p->empresa_id = $p->pivotParent instanceof MovimientoTransporte
                    ? $p->pivotParent->empresa_id
                    : MovimientoTransporte::withoutGlobalScopes()->whereKey($p->movimiento_transporte_id)->value('empresa_id');
            }
        });
    }

    public function movimiento(): BelongsTo
    {
        return $this->belongsTo(MovimientoTransporte::class, 'movimiento_transporte_id');
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }
}
