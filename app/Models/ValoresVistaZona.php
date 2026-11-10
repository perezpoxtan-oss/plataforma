<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Valores a la Vista: valores a la vista en una zona de la habitación. SEGCAT: habitacion_valores_zona.
 */
class ValoresVistaZona extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'valores_vista_zonas';

    protected $fillable = ['empresa_id', 'novedad_id', 'zona', 'descripcion'];

    public function novedad(): BelongsTo
    {
        return $this->belongsTo(Novedad::class);
    }
}
