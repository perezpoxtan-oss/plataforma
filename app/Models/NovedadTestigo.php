<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Testigo de un Accidente, un Siniestro de Protección Civil o un Robo (campo formato).
 */
class NovedadTestigo extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'novedad_testigos';

    protected $fillable = ['empresa_id', 'novedad_id', 'formato', 'nombre', 'departamento', 'declaracion'];

    public function novedad(): BelongsTo
    {
        return $this->belongsTo(Novedad::class);
    }
}
