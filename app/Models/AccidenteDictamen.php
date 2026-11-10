<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Accidente: Servicio Médico — Dictamen Clínico. SEGCAT: accidente_medico (tipos de herida y zonas del cuerpo ya no son texto separado por comas).
 */
class AccidenteDictamen extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'accidente_dictamenes';

    protected $fillable = ['empresa_id', 'novedad_id', 'tipos_herida', 'zonas_cuerpo', 'primeros_auxilios', 'cuales_auxilios', 'atencion_medica', 'cuales_atencion', 'diagnostico', 'hospitalizacion', 'nombre_hospital', 'trasladado_en', 'nombre_medico', 'observaciones'];

    protected function casts(): array
    {
        return ['tipos_herida' => 'array', 'zonas_cuerpo' => 'array', 'primeros_auxilios' => 'boolean', 'atencion_medica' => 'boolean', 'hospitalizacion' => 'boolean'];
    }

    public function novedad(): BelongsTo
    {
        return $this->belongsTo(Novedad::class);
    }
}
