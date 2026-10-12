<?php

namespace App\Models;

use App\Services\Auditoria\MovimientosSensibles;
use Illuminate\Database\Eloquent\Model;

class Auditoria extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = 'creado_en';

    protected $table = 'auditoria';

    protected $fillable = [
        'empresa_id', 'sede_id', 'user_id', 'evento', 'auditable_type', 'auditable_id', 'antes', 'despues', 'ip',
    ];

    protected function casts(): array
    {
        return ['antes' => 'array', 'despues' => 'array', 'creado_en' => 'datetime'];
    }

    /**
     * Mejoras de estructura (ADR-0016): cada movimiento guarda la sede del
     * registro (para «Su sede» en la bitácora) y los movimientos sensibles
     * avisan a quien ve toda la bitácora, si la empresa lo encendió.
     */
    protected static function booted(): void
    {
        static::creating(function (Auditoria $fila): void {
            if (! array_key_exists('sede_id', $fila->getAttributes())) {
                $fila->sede_id = MovimientosSensibles::sedeDe($fila->auditable_type, $fila->auditable_id);
            }
        });

        static::created(fn (Auditoria $fila) => app(MovimientosSensibles::class)->avisar($fila));
    }
}
