<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Rango o posición (Agente, Supervisor, Gerente...). Es independiente del
 * departamento: si no se liga a ninguno, aplica en cualquiera.
 */
class Puesto extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $attributes = ['activo' => true];

    protected $fillable = ['empresa_id', 'nombre', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function departamentos(): BelongsToMany
    {
        return $this->belongsToMany(Departamento::class, 'departamento_puesto');
    }
}
