<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Detalle del Reporte General (SEGCAT: ig_observados, ig_actividad, ig_motivo, acciones_inmediatas).
 * C-32 (D-17): «subtipo» opcional de la lista de la empresa (AjustesNovedades::subtiposReporteGeneral).
 */
class NovedadReporteGeneral extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'novedad_reportes_generales';

    protected $fillable = ['empresa_id', 'novedad_id', 'subtipo', 'observados', 'actividad', 'motivo', 'acciones_inmediatas'];

    public function novedad(): BelongsTo
    {
        return $this->belongsTo(Novedad::class);
    }
}
