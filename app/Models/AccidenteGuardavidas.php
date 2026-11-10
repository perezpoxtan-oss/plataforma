<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Accidente: Guardavidas — Anexo Acuático. SEGCAT: accidente_guardavidas.
 */
class AccidenteGuardavidas extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'accidente_guardavidas';

    protected $fillable = ['empresa_id', 'novedad_id', 'fecha', 'hora', 'turno', 'lugar', 'nombre', 'puesto', 'supervisor', 'alcoholizado', 'descalzo', 'tipo_calzado', 'tipo_herida', 'parte_afectada', 'acto_inseguro', 'condicion_insegura', 'especifique_riesgo', 'descripcion', 'acudio_servicio_medico', 'material_curacion', 'se_informa_a'];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'alcoholizado' => 'boolean', 'descalzo' => 'boolean', 'acto_inseguro' => 'boolean', 'condicion_insegura' => 'boolean', 'acudio_servicio_medico' => 'boolean'];
    }

    public function novedad(): BelongsTo
    {
        return $this->belongsTo(Novedad::class);
    }
}
