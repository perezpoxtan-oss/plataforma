<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un paradero dentro de un horario de ruta, con su orden y su hora tentativa
 * (SEGCAT: ruta_paraderos).
 */
class RutaParada extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'ruta_paradas';

    protected $fillable = ['empresa_id', 'ruta_horario_id', 'paradero_id', 'hora', 'orden'];

    public function horario(): BelongsTo
    {
        return $this->belongsTo(RutaHorario::class, 'ruta_horario_id');
    }

    public function paradero(): BelongsTo
    {
        return $this->belongsTo(Paradero::class);
    }

    /** "13:25" o "" si no tiene hora. */
    public function horaCorta(): string
    {
        return $this->hora ? substr((string) $this->hora, 0, 5) : '';
    }
}
