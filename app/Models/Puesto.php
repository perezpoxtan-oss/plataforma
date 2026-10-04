<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Rango o posición (Agente, Supervisor, Gerente...). Es independiente del
 * departamento: si no se liga a ninguno, aplica en cualquiera.
 */
class Puesto extends Model
{
    public const OPERATIVO = 'operativo';

    public const ADMINISTRATIVO = 'administrativo';

    public const TIPOS = [self::OPERATIVO => 'Operativo', self::ADMINISTRATIVO => 'Administrativo'];

    use PerteneceAEmpresa, RegistraAutor;

    protected $attributes = ['activo' => true, 'tipo' => self::OPERATIVO];

    protected $fillable = ['empresa_id', 'nombre', 'tipo', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function departamentos(): BelongsToMany
    {
        return $this->belongsToMany(Departamento::class, 'departamento_puesto');
    }
}
