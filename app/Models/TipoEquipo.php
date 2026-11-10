<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tipo de equipo de seguridad (SEGCAT: cat_tipos_equipo): "Radio de
 * Comunicación", "Lámpara Táctica", "Fornitura"… Catálogo propio de cada
 * empresa; se crea al vuelo desde el alta de un equipo.
 */
class TipoEquipo extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'tipos_equipo';

    protected $attributes = ['activo' => true];

    protected $fillable = ['empresa_id', 'nombre', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function equipos(): HasMany
    {
        return $this->hasMany(Equipo::class);
    }
}
