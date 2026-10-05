<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Accidente: formato del colaborador interno. SEGCAT: accidente_colaborador (los testigos 1 y 2 pasan a novedad_testigos).
 */
class AccidenteColaborador extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'accidente_colaboradores';

    protected $fillable = ['empresa_id', 'novedad_id', 'colaborador_id', 'fecha_accidente', 'hora_accidente', 'departamento', 'puesto', 'turno', 'area_trabajo', 'jefe_inmediato', 'puesto_jefe', 'primera_vez', 'causa_terceras_personas', 'causa_acto_inseguro', 'causa_condicion_insegura', 'explicacion_causas', 'aviso_dado_por', 'depto_aviso', 'actividades_cotidianas', 'mismas_actividades'];

    protected function casts(): array
    {
        return ['fecha_accidente' => 'date', 'primera_vez' => 'boolean', 'causa_terceras_personas' => 'boolean', 'causa_acto_inseguro' => 'boolean', 'causa_condicion_insegura' => 'boolean', 'mismas_actividades' => 'boolean'];
    }

    public function novedad(): BelongsTo
    {
        return $this->belongsTo(Novedad::class);
    }

    /** Colaborador afectado (padrón de colaboradores). */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }
}
