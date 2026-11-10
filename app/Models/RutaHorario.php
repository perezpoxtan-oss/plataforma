<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Horario de una ruta (SEGCAT: ruta_horarios): un grupo de días con su hora
 * de inicio, su hora de llegada y sus paraderos. Puede cruzar la medianoche
 * (23:30 a 00:40 llega al día siguiente).
 */
class RutaHorario extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'ruta_horarios';

    protected $fillable = ['empresa_id', 'ruta_id', 'nombre', 'dias', 'hora_inicio', 'hora_fin', 'orden'];

    public function ruta(): BelongsTo
    {
        return $this->belongsTo(Ruta::class);
    }

    public function paradas(): HasMany
    {
        return $this->hasMany(RutaParada::class)->orderBy('orden');
    }

    /** @return list<string> ["LU", "MA", ...] */
    public function listaDias(): array
    {
        return Ruta::separarDias($this->dias);
    }

    /** ¿Sale este día? 1 = lunes … 7 = domingo (ISO). */
    public function aplicaEn(int $diaIso): bool
    {
        return in_array(array_keys(Ruta::DIAS)[$diaIso - 1] ?? '', $this->listaDias(), true);
    }

    public function inicio(): string
    {
        return substr((string) $this->hora_inicio, 0, 5);
    }

    /** "07:30" o "" si no tiene hora de fin (opcional en las salidas). */
    public function fin(): string
    {
        return $this->hora_fin ? substr((string) $this->hora_fin, 0, 5) : '';
    }

    /** Llega al día siguiente (p. ej. 23:30 a 00:40). */
    public function cruzaMedianoche(): bool
    {
        return $this->fin() !== '' && $this->fin() < $this->inicio();
    }

    /** "L-V", "S-D", "L-D" o las letras sueltas ("L,X,V"). */
    public function patronDias(): string
    {
        return Ruta::patronDias($this->dias);
    }

    /** "Lunes a viernes", "Todos los días", "Lun, Mié, Vie". */
    public function textoDias(): string
    {
        return Ruta::textoDias($this->dias);
    }
}
