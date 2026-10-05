<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Siniestro PC: equipo de protección civil usado o dañado. SEGCAT: siniestro_pc_equipos.
 */
class SiniestroEquipo extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'siniestro_equipos';

    protected $fillable = ['empresa_id', 'novedad_id', 'identificador', 'estado_uso'];

    public function novedad(): BelongsTo
    {
        return $this->belongsTo(Novedad::class);
    }
}
