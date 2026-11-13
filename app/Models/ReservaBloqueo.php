<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bloqueo de un sitio de Reservas de áreas (docs/tecnico/reservas.md,
 * «Bloqueos»): un área o espacio interior no se puede reservar de `desde` a
 * `hasta` (hora universal) por un motivo. Bloquear una zona bloquea sus
 * espacios interiores y al revés (Disponibilidad, por la ruta de espacios).
 * Se quita con «Dar de baja» (activo = false, con quién, cuándo y por qué).
 */
class ReservaBloqueo extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'reserva_bloqueos';

    protected $attributes = ['activo' => true];

    protected $fillable = ['empresa_id', 'sede_id', 'reserva_area_id', 'espacio_id', 'desde', 'hasta', 'motivo', 'activo'];

    protected function casts(): array
    {
        return [
            'desde' => 'datetime', 'hasta' => 'datetime', 'dado_de_baja_en' => 'datetime', 'activo' => 'boolean',
        ];
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(ReservaArea::class, 'reserva_area_id');
    }

    public function espacio(): BelongsTo
    {
        return $this->belongsTo(Espacio::class);
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class)->withTrashed();
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function dadoDeBajaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dado_de_baja_por');
    }

    /** Reservas marcadas «Afectada por bloqueo» con este bloqueo. */
    public function reservas(): HasMany
    {
        return $this->hasMany(Reserva::class, 'bloqueo_id');
    }

    /** @param  Builder<ReservaBloqueo>  $q */
    public function scopeVigentes(Builder $q): void
    {
        $q->where('reserva_bloqueos.activo', true);
    }

    /** ¿Sigue bloqueando (activo y no ha terminado)? */
    public function vigente(): bool
    {
        return $this->activo && $this->hasta !== null && $this->hasta->greaterThan(now());
    }

    /** «Salón Maya» (el nombre del espacio bloqueado). */
    public function sitio(): string
    {
        return (string) ($this->espacio?->nombre ?? $this->area?->nombre() ?? 'Sitio');
    }
}
