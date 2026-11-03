<?php

namespace App\Services\EquiposAcceso;

use Carbon\CarbonImmutable;

/**
 * Un marcaje como lo entendió el conector, igual para todas las marcas.
 *
 *  - `clave`: lo que lo hace único en ese equipo (se guarda su sha256);
 *  - `pin`: número de empleado en el equipo; `tarjeta`: número de tarjeta;
 *  - `cuando`: la hora del marcaje en UTC;
 *  - `sentido`: lo que dijo el equipo (entrada | salida | null si no dijo);
 *  - `ignorar`: si no es un marcaje (abrió con botón, alarma…), por qué.
 */
final class MarcajeLeido
{
    public function __construct(
        public readonly string $clave,
        public readonly ?string $pin,
        public readonly ?string $tarjeta,
        public readonly ?CarbonImmutable $cuando,
        public readonly ?string $sentido = null,
        public readonly ?string $ignorar = null,
    ) {}
}
