<?php

namespace App\Models;

use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rol de una empresa, o plantilla de la plataforma cuando empresa_id es nulo.
 * No usa el filtro global de empresa porque las plantillas son compartidas;
 * use el scope disponiblesPara().
 */
class Rol extends Model
{
    use RegistraAutor;

    protected $table = 'roles';

    /** Mismos valores por defecto que la base de datos (disponibles antes de recargar). */
    protected $attributes = ['activo' => true];

    protected $fillable = ['empresa_id', 'nombre', 'descripcion', 'nivel_jerarquia', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'nivel_jerarquia' => 'integer'];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function permisos(): HasMany
    {
        return $this->hasMany(RolPermiso::class);
    }

    public function esPlantilla(): bool
    {
        return $this->empresa_id === null;
    }

    public function scopeDeEmpresa(Builder $query, int $empresaId): void
    {
        $query->where('empresa_id', $empresaId);
    }

    public function scopePlantillas(Builder $query): void
    {
        $query->whereNull('empresa_id');
    }
}
