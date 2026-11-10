<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModuloAccion extends Model
{
    protected $table = 'modulo_acciones';

    protected $fillable = ['modulo_id', 'accion_id'];

    public function modulo(): BelongsTo
    {
        return $this->belongsTo(Modulo::class);
    }

    public function accion(): BelongsTo
    {
        return $this->belongsTo(Accion::class);
    }

    /**
     * Clave del permiso: "modulo.accion".
     */
    public function clave(): string
    {
        return $this->modulo->clave.'.'.$this->accion->clave;
    }
}
