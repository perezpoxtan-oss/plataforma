<?php

namespace App\Models;

use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tipo de espacio por nivel (Piso, Habitación, Baño, Cama...). Los del
 * sistema (empresa_id nulo) los ven todas las empresas; cada empresa puede
 * agregar los suyos sin afectar a las demás.
 */
class TipoEspacio extends Model
{
    use RegistraAutor;

    protected $table = 'tipos_espacio';

    protected $attributes = ['activo' => true];

    protected $fillable = ['empresa_id', 'nivel', 'nombre', 'icono', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    /**
     * Tipos que puede usar una empresa: los del sistema y los suyos.
     */
    public function scopeDisponiblesPara(Builder $query, int $empresaId, string $nivel): void
    {
        $query->where('nivel', $nivel)
            ->where('activo', true)
            ->where(fn ($q) => $q->whereNull('empresa_id')->orWhere('empresa_id', $empresaId))
            ->orderBy('nombre');
    }
}
