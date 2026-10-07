<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una etiqueta de una impresión: el tipo del lector (llave, vehiculo…), el
 * registro y su título como se veía al imprimir.
 */
class ImpresionEtiquetasItem extends Model
{
    use PerteneceAEmpresa;

    protected $table = 'impresiones_etiquetas_items';

    protected $fillable = ['empresa_id', 'impresion_id', 'tipo', 'registro_id', 'titulo', 'sede_id', 'orden'];

    protected function casts(): array
    {
        return ['registro_id' => 'integer', 'orden' => 'integer'];
    }

    public function impresion(): BelongsTo
    {
        return $this->belongsTo(ImpresionEtiquetas::class, 'impresion_id');
    }

    /** «llave-12»: la clave con la que se marca en la pantalla. */
    public function clave(): string
    {
        return $this->tipo.'-'.$this->registro_id;
    }
}
