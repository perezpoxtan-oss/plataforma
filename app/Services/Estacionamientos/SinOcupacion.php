<?php

namespace App\Services\Estacionamientos;

/**
 * Ocupación mientras la Bitácora de accesos no esté migrada: ninguna zona
 * tiene vehículos registrados todavía.
 */
class SinOcupacion implements OcupacionEstacionamientos
{
    public function ocupados(array $zonaIds): array
    {
        return [];
    }
}
