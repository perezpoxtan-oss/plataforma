<?php

namespace App\Services\Estacionamientos;

use Illuminate\Container\Attributes\Bind;

/**
 * De dónde sale la ocupación de cada zona: cuántos vehículos están "EN SITIO"
 * con esa zona en la Bitácora de accesos (SEGCAT la contaba en vivo, nunca
 * la guardaba aparte; así la salida libera el espacio sin pasos extra).
 *
 * La Bitácora de accesos todavía no existe en la plataforma: mientras tanto
 * responde SinOcupacion (todo en 0) y la pantalla muestra "0 / 40". Cuando se
 * migre Accesos, ese módulo registra su propia implementación en su
 * ServiceProvider y esta pantalla la usa sin cambiar nada:
 *
 *   $this->app->bind(OcupacionEstacionamientos::class, OcupacionPorAccesos::class);
 *
 * Ver docs/tecnico/estacionamientos.md.
 */
#[Bind(SinOcupacion::class)]
interface OcupacionEstacionamientos
{
    /**
     * Vehículos que ocupan ahora cada zona. Se llama una sola vez por pantalla
     * con todas las zonas (sin consultas por zona) y dentro de la empresa de
     * trabajo. Las zonas que no aparezcan en el resultado cuentan como 0.
     *
     * @param  list<int>  $zonaIds
     * @return array<int, int> zona_id => vehículos en sitio
     */
    public function ocupados(array $zonaIds): array;
}
