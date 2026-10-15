<?php

namespace App\Models;

use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Plan de suscripción: precio por sede al mes, sedes mínimas, usuarios por
 * sede incluidos (nulo = ilimitados) y los productos que incluye. «Cotizar»
 * lo muestra sin precio público. Global de la plataforma.
 */
class Plan extends Model
{
    use RegistraAutor;

    protected $table = 'planes';

    protected $attributes = [
        'precio_por_sede_mensual' => 0, 'sedes_minimas' => 1, 'destacado' => false, 'cotizar' => false,
        'activo' => true, 'publicado' => false, 'orden' => 0,
    ];

    protected $fillable = [
        'nombre', 'slug', 'frase', 'descripcion', 'precio_por_sede_mensual', 'sedes_minimas', 'usuarios_por_sede',
        'destacado', 'cotizar', 'activo', 'publicado', 'orden',
    ];

    protected function casts(): array
    {
        return [
            'precio_por_sede_mensual' => 'decimal:2', 'sedes_minimas' => 'integer', 'usuarios_por_sede' => 'integer',
            'destacado' => 'boolean', 'cotizar' => 'boolean', 'activo' => 'boolean', 'publicado' => 'boolean', 'orden' => 'integer',
        ];
    }

    public function productos(): BelongsToMany
    {
        return $this->belongsToMany(Producto::class, 'plan_producto')->orderBy('productos.orden')->orderBy('productos.id');
    }

    public function paquetes(): BelongsToMany
    {
        return $this->belongsToMany(Paquete::class, 'paquete_plan');
    }

    /** Lo que ve el público: activo y publicado. @param  Builder<Plan>  $q */
    public function scopePublicos(Builder $q): Builder
    {
        return $q->where('planes.activo', true)->where('planes.publicado', true)->orderBy('planes.orden')->orderBy('planes.id');
    }
}
