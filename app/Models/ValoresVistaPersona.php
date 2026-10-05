<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Valores a la Vista: otra persona en la habitación. SEGCAT: habitacion_personas.
 */
class ValoresVistaPersona extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'valores_vista_personas';

    protected $fillable = ['empresa_id', 'novedad_id', 'nombre', 'departamento', 'puesto', 'actividad', 'se_retira'];

    protected function casts(): array
    {
        return ['se_retira' => 'boolean'];
    }

    public function novedad(): BelongsTo
    {
        return $this->belongsTo(Novedad::class);
    }
}
