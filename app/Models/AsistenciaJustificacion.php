<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Asistencia (ADR-0024): una falta, retardo o salida anticipada justificada
 * de un colaborador en un día, con su motivo. Es lo único que guarda el
 * módulo: la asistencia se calcula de la Bitácora de accesos y del turno.
 */
class AsistenciaJustificacion extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'asistencia_justificaciones';

    public const TIPOS = ['falta' => 'Falta', 'retardo' => 'Retardo', 'salida_anticipada' => 'Salida anticipada'];

    public const MOTIVOS = [
        'permiso' => 'Permiso', 'vacaciones' => 'Vacaciones', 'descanso' => 'Día de descanso', 'incapacidad' => 'Incapacidad',
        'comision' => 'Comisión de trabajo', 'otro' => 'Otro',
    ];

    protected $fillable = ['empresa_id', 'sede_id', 'colaborador_id', 'fecha', 'tipo', 'motivo', 'nota'];

    protected function casts(): array
    {
        return ['fecha' => 'date:Y-m-d', 'datos_eliminados_en' => 'datetime'];
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }

    public function textoMotivo(): string
    {
        return self::MOTIVOS[$this->motivo] ?? 'Otro';
    }
}
