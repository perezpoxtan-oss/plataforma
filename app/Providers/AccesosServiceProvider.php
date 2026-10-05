<?php

namespace App\Providers;

use App\Services\Accesos\OcupacionPorAccesos;
use App\Services\Estacionamientos\OcupacionEstacionamientos;
use Illuminate\Support\ServiceProvider;

/**
 * Bitácora de accesos: aporta la ocupación en vivo de las zonas de
 * estacionamiento (reemplaza a SinOcupacion). Ver docs/tecnico/accesos.md.
 */
class AccesosServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OcupacionEstacionamientos::class, OcupacionPorAccesos::class);
    }
}
