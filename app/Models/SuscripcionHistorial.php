<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Renglón del historial de una suscripción: qué pasó, de qué estado a cuál,
 * quién (nulo = la tarea diaria o un aviso de pago) y cuándo.
 */
class SuscripcionHistorial extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'suscripcion_historial';

    protected $fillable = ['suscripcion_id', 'empresa_id', 'evento', 'estado_antes', 'estado_despues', 'descripcion', 'datos', 'user_id'];

    protected function casts(): array
    {
        return ['datos' => 'array', 'created_at' => 'datetime'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
