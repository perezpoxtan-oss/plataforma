<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * «No molestar» / delegación: mientras está activa (desde ≤ ahora ≤ hasta y
 * sin cancelar), los avisos de autorización del responsable van a su
 * delegado, que puede responder por él.
 */
class Delegacion extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    public const ESTADOS = ['activa' => 'Activa', 'programada' => 'Programada', 'terminada' => 'Terminada', 'cancelada' => 'Cancelada'];

    protected $table = 'delegaciones';

    protected $fillable = ['empresa_id', 'user_id', 'delegado_id', 'desde', 'hasta', 'motivo'];

    protected function casts(): array
    {
        return ['desde' => 'datetime', 'hasta' => 'datetime', 'cancelada_en' => 'datetime'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function delegado(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegado_id');
    }

    public function canceladaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelada_por');
    }

    /** @param  Builder<Delegacion>  $q */
    public function scopeActivas(Builder $q): Builder
    {
        $ahora = now();

        return $q->whereNull('delegaciones.cancelada_en')->where('delegaciones.desde', '<=', $ahora)->where('delegaciones.hasta', '>=', $ahora);
    }

    public function estado(): string
    {
        return match (true) {
            $this->cancelada_en !== null => 'cancelada',
            $this->hasta->isPast() => 'terminada',
            $this->desde->isFuture() => 'programada',
            default => 'activa',
        };
    }
}
