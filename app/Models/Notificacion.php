<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Notificación del centro de notificaciones (la campana del encabezado).
 * Es de UN usuario: nadie más la ve. Puede traer botones de acción
 * (acciones: [{clave, etiqueta}]) que se responden desde la lista.
 */
class Notificacion extends Model
{
    use PerteneceAEmpresa;

    protected $table = 'notificaciones';

    protected $attributes = ['icono' => 'bi-bell', 'nivel' => 'info'];

    protected $fillable = [
        'empresa_id', 'user_id', 'tipo', 'titulo', 'texto', 'url', 'icono', 'nivel', 'referencia_tipo', 'referencia_id', 'acciones',
    ];

    protected function casts(): array
    {
        return ['acciones' => 'array', 'leida_en' => 'datetime'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @param  Builder<Notificacion>  $q */
    public function scopeDe(Builder $q, User $usuario): Builder
    {
        return $q->where('notificaciones.user_id', $usuario->id);
    }

    /** @param  Builder<Notificacion>  $q */
    public function scopeNoLeidas(Builder $q): Builder
    {
        return $q->whereNull('notificaciones.leida_en');
    }
}
