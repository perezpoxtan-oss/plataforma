<?php

namespace App\Models;

use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Cliente de la plataforma (tenant). No usa el filtro de empresa porque
 * es la entidad que lo define; el acceso se controla con permisos.
 */
class Empresa extends Model
{
    use RegistraAutor, SoftDeletes;

    protected $table = 'empresas';

    protected $fillable = [
        'rubro_id', 'nombre_comercial', 'razon_social', 'rfc', 'logo_ruta',
        'zona_horaria', 'idioma', 'moneda', 'activo',
    ];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function rubro(): BelongsTo
    {
        return $this->belongsTo(Rubro::class);
    }

    public function sedes(): HasMany
    {
        return $this->hasMany(Sede::class);
    }

    public function modulos(): BelongsToMany
    {
        return $this->belongsToMany(Modulo::class, 'empresa_modulos')
            ->withPivot(['nombre_visible', 'orden', 'activo'])
            ->withTimestamps();
    }
}
