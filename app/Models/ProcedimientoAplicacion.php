<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A quién aplica una versión: una fila por sede elegida (si no aplica a
 * todas), por departamento y por puesto. Sin departamentos ni puestos, aplica
 * a todo el personal de esas sedes.
 */
class ProcedimientoAplicacion extends Model
{
    use PerteneceAEmpresa;

    public $timestamps = false;

    protected $table = 'procedimiento_aplicaciones';

    protected $fillable = ['empresa_id', 'version_id', 'tipo', 'sede_id', 'departamento_id', 'puesto_id'];

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    public function departamento(): BelongsTo
    {
        return $this->belongsTo(Departamento::class);
    }

    public function puesto(): BelongsTo
    {
        return $this->belongsTo(Puesto::class);
    }
}
