<?php

namespace App\Services\Relojes;

use Carbon\CarbonInterface;

/**
 * Un asunto que espera respuesta (C-22): de qué se trata, desde cuándo
 * espera y a dónde se va para atenderlo. La referencia es la misma que usa
 * el módulo en sus avisos de la campana, así que cuando el módulo lo
 * resuelve, también se apagan el recordatorio y el aviso al suplente.
 */
final class Espera
{
    /**
     * @param  array<string, mixed>  $datos  lo que el reloj necesita después (ids, nombres)
     */
    public function __construct(
        public readonly string $referenciaTipo,
        public readonly int $referenciaId,
        public readonly CarbonInterface $desde,
        public readonly string $titulo,
        public readonly string $url,
        public readonly ?int $sedeId = null,
        public readonly array $datos = [],
    ) {}

    public function llave(): string
    {
        return $this->referenciaTipo.':'.$this->referenciaId;
    }

    public function minutos(?CarbonInterface $ahora = null): int
    {
        return (int) max(0, intdiv(($ahora ?? now())->getTimestamp() - $this->desde->getTimestamp(), 60));
    }
}
