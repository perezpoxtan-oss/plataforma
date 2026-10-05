<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Accidente: formato del huésped / cliente (Guest Injury Report). SEGCAT: accidente_huesped.
 */
class AccidenteHuesped extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'accidente_huespedes';

    protected $fillable = ['empresa_id', 'novedad_id', 'fecha_accidente', 'hora_accidente', 'nombre', 'num_habitacion', 'agencia', 'fecha_check_in', 'fecha_check_out', 'pais', 'sexo', 'edad', 'lugar', 'explicacion', 'requiere_asistencia_medica', 'motivo_asistencia', 'hubo_testigos', 'detalles_testigos'];

    protected function casts(): array
    {
        return ['fecha_accidente' => 'date', 'fecha_check_in' => 'date', 'fecha_check_out' => 'date', 'requiere_asistencia_medica' => 'boolean', 'hubo_testigos' => 'boolean', 'edad' => 'integer'];
    }

    public function novedad(): BelongsTo
    {
        return $this->belongsTo(Novedad::class);
    }
}
