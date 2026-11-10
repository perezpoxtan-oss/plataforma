<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tipo de gafete de una empresa (SEGCAT: cat_tipos_gafete). Cada empresa
 * nombra los suyos; los tres de siempre se crean solos la primera vez.
 */
class TipoGafete extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'tipos_gafete';

    /** Los de SEGCAT, con su color en la impresión. */
    public const BASICOS = ['Visitante', 'Proveedor', 'Contratista'];

    protected $attributes = ['activo' => true];

    protected $fillable = ['empresa_id', 'nombre', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function gafetes(): HasMany
    {
        return $this->hasMany(Gafete::class);
    }

    /**
     * Color de la franja del gafete impreso, como en SEGCAT: Visitante naranja,
     * Proveedor azul y cualquier otro tipo gris.
     */
    public function color(): string
    {
        return match (mb_strtolower($this->nombre)) {
            'visitante' => 'visitante',
            'proveedor' => 'proveedor',
            default => 'contratista',
        };
    }
}
