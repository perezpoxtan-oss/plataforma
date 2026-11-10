<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Punto de inspección de un ticket histórico de Recorrido PC. SEGCAT: bitacora_recorridos_pc.
 */
class RecorridoPcPunto extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'novedad_recorrido_puntos';

    protected $fillable = ['empresa_id', 'novedad_id', 'identificador', 'categoria', 'edificio', 'nivel', 'area', 'criterios', 'observaciones'];

    protected function casts(): array
    {
        return ['criterios' => 'array'];
    }

    public function novedad(): BelongsTo
    {
        return $this->belongsTo(Novedad::class);
    }
}
