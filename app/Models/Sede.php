<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sede extends Model
{
    use PerteneceAEmpresa, RegistraAutor, SoftDeletes;

    protected $table = 'sedes';

    /** Mismos valores por defecto que la base de datos (disponibles antes de recargar). */
    protected $attributes = ['activo' => true];

    protected $fillable = ['empresa_id', 'codigo', 'nombre', 'direccion', 'zona_horaria', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    /**
     * Zona horaria efectiva: la de la sede o, si no tiene, la de la empresa.
     */
    public function zonaHoraria(): string
    {
        if ($this->zona_horaria) {
            return $this->zona_horaria;
        }

        return $this->loadMissing('empresa')->empresa?->zona_horaria ?? config('app.timezone');
    }
}
