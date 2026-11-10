<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Firma guardada de un usuario (solo si él aceptó guardarla), para no
 * dibujarla cada vez que aprueba o firma en caseta. Vive en el disco privado
 * y solo la ve su dueño; al usarla en un pase se copia, así el pase no
 * cambia si después la reemplaza.
 */
class FirmaUsuario extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'firmas_usuarios';

    protected $fillable = ['empresa_id', 'user_id', 'firma_ruta'];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
