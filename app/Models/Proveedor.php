<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use App\Models\Concerns\VerificableEnPadron;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Empresa externa: proveedor, contratista, agencia, taxi, transportadora...
 * (SEGCAT: cat_empresas_externas). Opera en todas las sedes o solo en algunas.
 */
class Proveedor extends Model
{
    use PerteneceAEmpresa, RegistraAutor, VerificableEnPadron;

    /** clave => etiqueta (SEGCAT: tipo_empresa) */
    public const CATEGORIAS = [
        'proveedor' => 'Proveedor de insumos / alimentos',
        'transporte_personal' => 'Transporte de personal',
        'transporte_huespedes' => 'Transporte de huéspedes',
        'contratista' => 'Contratista',
        'agencia_autos' => 'Agencia de autos',
        'taxi' => 'Taxi',
        'agencia_viajes' => 'Agencia de viajes',
        'agencia_tours' => 'Agencia de tours',
        'transportadora' => 'Transportadora',
    ];

    protected $table = 'proveedores';

    protected $attributes = ['activo' => true, 'todas_las_sedes' => true, 'categoria' => 'proveedor'];

    protected $fillable = ['empresa_id', 'nombre', 'categoria', 'rfc', 'telefono', 'direccion', 'todas_las_sedes', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'todas_las_sedes' => 'boolean'];
    }

    public function sedes(): BelongsToMany
    {
        return $this->belongsToMany(Sede::class, 'proveedor_sede');
    }

    public function personas(): HasMany
    {
        return $this->hasMany(Persona::class);
    }

    public function vehiculos(): HasMany
    {
        return $this->hasMany(Vehiculo::class);
    }

    /**
     * @param  array<int>  $sedeIds
     */
    public function scopeOperanEn(Builder $consulta, array $sedeIds): Builder
    {
        return $consulta->where(fn ($q) => $q->where('proveedores.todas_las_sedes', true)
            ->orWhereHas('sedes', fn ($s) => $s->whereIn('sedes.id', $sedeIds)));
    }
}
