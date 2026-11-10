<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Agrupación de habitaciones de una sede (las "Secciones" de SEGCAT):
 * Torre Norte, Villas, Ala Mar... No es un nivel del árbol.
 */
class GrupoEspacio extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'grupos_espacio';

    protected $attributes = ['activo' => true];

    protected $fillable = ['empresa_id', 'sede_id', 'nombre', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    public function espacios(): HasMany
    {
        return $this->hasMany(Espacio::class);
    }
}
