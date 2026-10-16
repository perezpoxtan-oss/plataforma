<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;

/**
 * Montaje posible de un área reservable (Auditorio, Escuela, Herradura…):
 * catálogo editable por empresa. El aforo de cada montaje se fija por área
 * (reserva_area_montajes.aforo).
 */
class ReservaMontaje extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    /** Catálogo con que nace cada empresa (lo puede cambiar). */
    public const DEFECTO = ['Auditorio', 'Escuela', 'Herradura (U)', 'Banquete', 'Cóctel', 'Imperial', 'Libre'];

    protected $table = 'reserva_montajes';

    protected $attributes = ['activo' => true, 'orden' => 0];

    protected $fillable = ['empresa_id', 'nombre', 'orden', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'orden' => 'integer'];
    }
}
