<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Valores a la Vista: puerta, ventana o terraza. SEGCAT: habitacion_aperturas.
 */
class ValoresVistaApertura extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'valores_vista_aperturas';

    protected $fillable = ['empresa_id', 'novedad_id', 'tipo', 'estado', 'es_especial', 'descripcion'];

    protected function casts(): array
    {
        return ['es_especial' => 'boolean'];
    }

    public function novedad(): BelongsTo
    {
        return $this->belongsTo(Novedad::class);
    }
}
