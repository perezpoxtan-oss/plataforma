<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Modulo extends Model
{
    public const TIPO_SISTEMA = 'sistema';

    public const TIPO_CONFIGURABLE = 'configurable';

    protected $table = 'modulos';

    /** Mismos valores por defecto que la base de datos (disponibles antes de recargar). */
    protected $attributes = ['activo' => true];

    protected $fillable = [
        'area_id', 'padre_id', 'menu_id', 'seccion_menu', 'orden_menu', 'clave', 'nombre', 'descripcion', 'icono', 'color_icono',
        'ruta', 'orden', 'tipo', 'activo',
    ];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    public function padre(): BelongsTo
    {
        return $this->belongsTo(self::class, 'padre_id');
    }

    public function submodulos(): HasMany
    {
        return $this->hasMany(self::class, 'padre_id')->orderBy('orden');
    }

    public function acciones(): BelongsToMany
    {
        return $this->belongsToMany(Accion::class, 'modulo_acciones')->withTimestamps();
    }

    public function moduloAcciones(): HasMany
    {
        return $this->hasMany(ModuloAccion::class);
    }
}
