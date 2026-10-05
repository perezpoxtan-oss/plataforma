<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Siniestro PC: servicio de emergencia externo y su hora de llegada. SEGCAT: siniestro_pc_servicios_externos.
 */
class SiniestroServicio extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'siniestro_servicios';

    protected $fillable = ['empresa_id', 'novedad_id', 'servicio', 'hora_llegada'];

    public function novedad(): BelongsTo
    {
        return $this->belongsTo(Novedad::class);
    }
}
