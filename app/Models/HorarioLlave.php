<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Horario de apertura válido de una llave (SEGCAT: llaves_horarios). El
 * nombre es libre y propio de la llave (no depende del catálogo de Turnos).
 * Si la hora de fin es menor que la de inicio, termina al día siguiente.
 */
class HorarioLlave extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'horarios_llave';

    protected $fillable = ['empresa_id', 'llave_id', 'nombre', 'hora_inicio', 'hora_fin'];

    public function llave(): BelongsTo
    {
        return $this->belongsTo(Llave::class);
    }

    public function inicio(): string
    {
        return substr((string) $this->hora_inicio, 0, 5);
    }

    public function fin(): string
    {
        return substr((string) $this->hora_fin, 0, 5);
    }

    public function cruzaMedianoche(): bool
    {
        return $this->fin() < $this->inicio();
    }
}
