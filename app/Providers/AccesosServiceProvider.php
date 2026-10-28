<?php

namespace App\Providers;

use App\Services\Accesos\OcupacionPorAccesos;
use App\Services\Estacionamientos\OcupacionEstacionamientos;
use App\Services\Novedades\FichaHechos\AccesosEnFicha;
use App\Services\Novedades\FichaHechos\FichaDeHechos;
use App\Services\Novedades\FichaHechos\PrestamosLlavesEnFicha;
use Illuminate\Support\ServiceProvider;

/**
 * Bitácora de accesos: aporta la ocupación en vivo de las zonas de
 * estacionamiento (reemplaza a SinOcupacion). Ver docs/tecnico/accesos.md.
 *
 * C-31 (D-09): registra en la Ficha de Hechos las fuentes de Préstamo de
 * llaves y de Accesos (docs/tecnico/datos-que-viajan.md).
 */
class AccesosServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OcupacionEstacionamientos::class, OcupacionPorAccesos::class);
        $this->app->tag([PrestamosLlavesEnFicha::class, AccesosEnFicha::class], FichaDeHechos::ETIQUETA);
    }
}
