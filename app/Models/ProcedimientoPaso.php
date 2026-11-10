<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;

/**
 * Un paso de una versión: texto, responsable (opcional) y si es un punto
 * crítico (se resalta en el modo lectura y en la hoja impresa).
 */
class ProcedimientoPaso extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'procedimiento_pasos';

    protected $attributes = ['critico' => false];

    protected $fillable = ['empresa_id', 'version_id', 'orden', 'texto', 'responsable', 'critico'];

    protected function casts(): array
    {
        return ['orden' => 'integer', 'critico' => 'boolean'];
    }
}
