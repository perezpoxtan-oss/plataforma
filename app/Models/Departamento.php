<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Área de trabajo de la empresa (Seguridad, Recepción, Ama de Llaves...).
 * Aplica en todas las sedes (incluidas las futuras) o solo en las elegidas.
 */
class Departamento extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $attributes = ['activo' => true, 'todas_las_sedes' => true];

    protected $fillable = ['empresa_id', 'nombre', 'todas_las_sedes', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'todas_las_sedes' => 'boolean'];
    }

    public function sedes(): BelongsToMany
    {
        return $this->belongsToMany(Sede::class, 'departamento_sede');
    }

    /** Colaboradores del departamento (para contar los activos en la tarjeta). */
    public function colaboradores(): HasMany
    {
        return $this->hasMany(Colaborador::class);
    }

    /** Responsables (titular y suplentes) que autorizan sus visitas y entrevistan a sus candidatos. */
    public function responsables(): HasMany
    {
        return $this->hasMany(DepartamentoResponsable::class);
    }

    public function puestos(): BelongsToMany
    {
        return $this->belongsToMany(Puesto::class, 'departamento_puesto');
    }

    /**
     * Departamentos que aplican en alguna de estas sedes.
     *
     * @param  array<int>  $sedeIds
     */
    public function scopeAplicanEn(Builder $consulta, array $sedeIds): Builder
    {
        return $consulta->where(fn ($q) => $q->where('todas_las_sedes', true)
            ->orWhereHas('sedes', fn ($s) => $s->whereIn('sedes.id', $sedeIds)));
    }
}
