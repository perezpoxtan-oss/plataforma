<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Siniestro PC: daño material por zona. SEGCAT: siniestro_pc_danos_zona.
 */
class SiniestroDano extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'siniestro_danos';

    protected $fillable = ['empresa_id', 'novedad_id', 'zona', 'descripcion'];

    public function novedad(): BelongsTo
    {
        return $this->belongsTo(Novedad::class);
    }
}
