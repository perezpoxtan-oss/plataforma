<?php

namespace App\Services\Personas;

use App\Models\Persona;
use Illuminate\Validation\ValidationException;

/**
 * El folio de identificación ya lo tiene otra persona de la empresa.
 *
 * Es un error de validación normal (la pantalla regresa con el mensaje), y
 * además lleva a la persona que ya lo tiene: el registro rápido responde 409
 * con ella para que la caseta la use en vez de duplicarla.
 */
class FolioDuplicado extends ValidationException
{
    public ?Persona $existente = null;

    public static function de(Persona $existente, string $mensaje): self
    {
        $e = static::withMessages(['folio_identificacion' => $mensaje]);
        $e->existente = $existente;

        return $e;
    }
}
