<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Area extends Model
{
    protected $table = 'areas';

    protected $fillable = ['clave', 'nombre', 'icono', 'orden', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function modulos(): HasMany
    {
        return $this->hasMany(Modulo::class)->orderBy('orden');
    }
}
