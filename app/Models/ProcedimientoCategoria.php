<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Categoría de procedimientos (catálogo por empresa): Emergencias, Operación
 * de caseta, Accesos, Protección civil, Administrativo… Su color pinta la
 * franja de las fichas. "Emergencias" va primero (orden).
 */
class ProcedimientoCategoria extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'procedimiento_categorias';

    /** Las que recibe cada empresa la primera vez: nombre => [color, orden]. */
    public const PREDETERMINADAS = [
        'Emergencias' => ['rojo', 1],
        'Operación de caseta' => ['azul', 2],
        'Accesos' => ['morado', 3],
        'Protección civil' => ['naranja', 4],
        'Administrativo' => ['gris', 5],
    ];

    /** Colores disponibles: clave => nombre. */
    public const COLORES = [
        'rojo' => 'Rojo', 'naranja' => 'Naranja', 'amarillo' => 'Amarillo', 'verde' => 'Verde',
        'azul' => 'Azul', 'morado' => 'Morado', 'gris' => 'Gris',
    ];

    protected $attributes = ['activo' => true, 'color' => 'azul', 'orden' => 0];

    protected $fillable = ['empresa_id', 'nombre', 'color', 'orden', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'orden' => 'integer'];
    }

    public function procedimientos(): HasMany
    {
        return $this->hasMany(Procedimiento::class, 'categoria_id');
    }

    public function esEmergencia(): bool
    {
        return mb_strtolower($this->nombre) === 'emergencias';
    }
}
