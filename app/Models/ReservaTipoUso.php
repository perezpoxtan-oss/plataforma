<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;

/**
 * Tipo de uso de una reserva (Reunión, Trabajo, Evento especial): catálogo
 * editable por empresa; sirve para el informe «Reservas por tipo».
 */
class ReservaTipoUso extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    public const DEFECTO = ['Reunión', 'Trabajo', 'Evento especial'];

    protected $table = 'reserva_tipos_uso';

    protected $attributes = ['activo' => true, 'orden' => 0];

    protected $fillable = ['empresa_id', 'nombre', 'orden', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'orden' => 'integer'];
    }
}
