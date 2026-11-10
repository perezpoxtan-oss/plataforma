<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Nota del "Minuto a Minuto" de una novedad. Solo se agrega: nunca se edita ni se borra.
 */
class NovedadNota extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'novedad_notas';

    protected $fillable = ['empresa_id', 'novedad_id', 'tipo', 'autor_nombre', 'texto'];

    public function novedad(): BelongsTo
    {
        return $this->belongsTo(Novedad::class);
    }
}
