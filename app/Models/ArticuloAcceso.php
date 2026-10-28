<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * C-19 (B-07, DD-18): equipo o herramienta que un proveedor o contratista
 * trae al entrar (lista corta y opcional). Al darle salida, el guardia la
 * compara: «Sale con lo mismo» o lo canaliza al supervisor.
 */
class ArticuloAcceso extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    /** Renglones que admite la lista (es corta a propósito). */
    public const MAXIMO = 10;

    protected $table = 'accesos_articulos';

    protected $attributes = ['cantidad' => 1];

    protected $fillable = ['empresa_id', 'acceso_id', 'articulo', 'cantidad', 'serie'];

    protected function casts(): array
    {
        return ['cantidad' => 'integer'];
    }

    public function acceso(): BelongsTo
    {
        return $this->belongsTo(Acceso::class);
    }

    /** «2 × TALADRO (serie DW-123)». */
    public function texto(): string
    {
        return ($this->cantidad > 1 ? $this->cantidad.' × ' : '').$this->articulo.($this->serie ? ' (serie '.$this->serie.')' : '');
    }
}
