<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lost & Found: folio y enlace de una plataforma externa. SEGCAT: lost_found_detalle.
 */
class LostFoundDetalle extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'lost_found_detalles';

    protected $fillable = ['empresa_id', 'novedad_id', 'folio_externo', 'enlace_externo'];

    public function novedad(): BelongsTo
    {
        return $this->belongsTo(Novedad::class);
    }
}
