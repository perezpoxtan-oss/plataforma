<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rubro extends Model
{
    protected $table = 'rubros';

    /** Mismos valores por defecto que la base de datos (disponibles antes de recargar). */
    protected $attributes = ['activo' => true];

    protected $fillable = ['clave', 'nombre', 'terminologia', 'activo'];

    protected function casts(): array
    {
        return [
            'terminologia' => 'array',
            'activo' => 'boolean',
        ];
    }

    public function empresas(): HasMany
    {
        return $this->hasMany(Empresa::class);
    }

    /**
     * Palabra visible para un termino del sistema (ej. 'sede' => 'Hotel').
     */
    public function termino(string $clave, string $porDefecto): string
    {
        return $this->terminologia[$clave] ?? $porDefecto;
    }
}
