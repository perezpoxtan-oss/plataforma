<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;

/**
 * Último recordatorio de acuses pendientes enviado a un usuario (para no
 * mandarle uno cada día).
 */
class ProcedimientoRecordatorio extends Model
{
    use PerteneceAEmpresa;

    protected $table = 'procedimiento_recordatorios';

    protected $fillable = ['empresa_id', 'user_id', 'ultimo_envio'];

    protected function casts(): array
    {
        return ['ultimo_envio' => 'date'];
    }
}
