<?php

namespace App\Models;

use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Paquete o complemento: suscripción (por sede al mes) o venta directa
 * (pago único). Se suma a los planes indicados; sin planes indicados, a
 * cualquiera. Global de la plataforma.
 */
class Paquete extends Model
{
    use RegistraAutor;

    public const SUSCRIPCION = 'suscripcion';

    public const UNICO = 'unico';

    public const MODALIDADES = [
        self::SUSCRIPCION => 'Suscripción (al mes)',
        self::UNICO => 'Venta directa (pago único)',
    ];

    protected $table = 'paquetes';

    protected $attributes = ['modalidad' => self::SUSCRIPCION, 'precio' => 0, 'por_sede' => true, 'activo' => true, 'publicado' => false, 'orden' => 0];

    protected $fillable = ['nombre', 'slug', 'descripcion', 'modalidad', 'precio', 'por_sede', 'activo', 'publicado', 'orden'];

    protected function casts(): array
    {
        return ['precio' => 'decimal:2', 'por_sede' => 'boolean', 'activo' => 'boolean', 'publicado' => 'boolean', 'orden' => 'integer'];
    }

    public function productos(): BelongsToMany
    {
        return $this->belongsToMany(Producto::class, 'paquete_producto')->orderBy('productos.orden')->orderBy('productos.id');
    }

    /** Planes con los que se puede sumar (vacío = con todos). */
    public function planes(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'paquete_plan');
    }

    public function esUnico(): bool
    {
        return $this->modalidad === self::UNICO;
    }

    /** @param  Builder<Paquete>  $q */
    public function scopePublicos(Builder $q): Builder
    {
        return $q->where('paquetes.activo', true)->where('paquetes.publicado', true)->orderBy('paquetes.orden')->orderBy('paquetes.id');
    }
}
