<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de un padrón que la caseta puede dar de alta desde Operación
 * (vehículos, empresas externas, personas). Ver ADR-0006 y
 * App\Services\Padrones\AltasPorVerificar.
 *
 * Columnas: verificacion (pendiente | verificado | rechazado), verificado_por,
 * verificado_en, motivo_rechazo, fusionado_en_id, origen_alta, sede_alta_id.
 * Se cambian solo con forceFill (no son "fillable").
 */
trait VerificableEnPadron
{
    public const PENDIENTE = 'pendiente';

    public const VERIFICADO = 'verificado';

    public const RECHAZADO = 'rechazado';

    public function initializeVerificableEnPadron(): void
    {
        $this->mergeCasts(['verificado_en' => 'datetime']);
        $this->attributes['verificacion'] ??= self::VERIFICADO;
    }

    public function estaPendiente(): bool
    {
        return $this->verificacion === self::PENDIENTE;
    }

    public function estaRechazado(): bool
    {
        return $this->verificacion === self::RECHAZADO;
    }

    /**
     * Registro que lo sustituye (se unió con él al verificar).
     */
    public function fusionadoEn(): BelongsTo
    {
        return $this->belongsTo(static::class, 'fusionado_en_id');
    }

    /**
     * @param  Builder<static>  $consulta
     * @return Builder<static>
     */
    public function scopePendientesDeVerificar(Builder $consulta): Builder
    {
        return $consulta->where($this->getTable().'.verificacion', self::PENDIENTE);
    }
}
