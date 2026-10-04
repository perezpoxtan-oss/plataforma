<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Boton del menu principal (Estructura, Padrones, Operacion...).
 */
class Menu extends Model
{
    protected $table = 'menus';

    /** Mismos valores por defecto que la base de datos (disponibles antes de recargar). */
    protected $attributes = ['activo' => true];

    protected $fillable = ['clave', 'nombre', 'icono', 'orden', 'orden_movil', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'orden' => 'integer', 'orden_movil' => 'integer'];
    }

    public function modulos(): HasMany
    {
        return $this->hasMany(Modulo::class)->orderBy('orden');
    }
}
