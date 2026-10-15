<?php

namespace App\Models;

use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Producto del catálogo comercial: un grupo de módulos de la plataforma que
 * se vende junto (por ejemplo «Caseta y accesos»). Global de la plataforma:
 * no pertenece a ninguna empresa.
 */
class Producto extends Model
{
    use RegistraAutor;

    protected $table = 'productos';

    protected $attributes = ['activo' => true, 'icono' => 'bi-box', 'orden' => 0];

    protected $fillable = ['clave', 'nombre', 'descripcion', 'icono', 'activo', 'orden'];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'orden' => 'integer'];
    }

    /** Módulos del sistema que habilita. */
    public function modulos(): BelongsToMany
    {
        return $this->belongsToMany(Modulo::class, 'modulo_producto')->orderBy('modulos.orden');
    }

    public function planes(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'plan_producto');
    }

    /** @param  Builder<Producto>  $q */
    public function scopeActivos(Builder $q): Builder
    {
        return $q->where('productos.activo', true)->orderBy('productos.orden')->orderBy('productos.id');
    }
}
