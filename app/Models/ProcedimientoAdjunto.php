<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;

/**
 * Adjunto de una versión (PDF o imagen) en el disco privado. Solo se sirve
 * desde el controlador del módulo, con permiso y alcance.
 */
class ProcedimientoAdjunto extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'procedimiento_adjuntos';

    protected $fillable = ['empresa_id', 'version_id', 'nombre', 'ruta', 'tipo', 'mime', 'tamano'];

    protected function casts(): array
    {
        return ['tamano' => 'integer'];
    }

    public function esImagen(): bool
    {
        return $this->tipo === 'imagen';
    }

    public function tamanoLegible(): string
    {
        return $this->tamano >= 1048576 ? number_format($this->tamano / 1048576, 1).' MB' : max(1, (int) round($this->tamano / 1024)).' KB';
    }
}
