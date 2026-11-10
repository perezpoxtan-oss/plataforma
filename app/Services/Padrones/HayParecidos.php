<?php

namespace App\Services\Padrones;

use RuntimeException;

/**
 * El alta rápida pasó la validación, pero hay registros parecidos en el
 * padrón: se deshace (la transacción se revierte) y se pregunta a la caseta
 * "¿Es alguno de estos?" antes de crear uno nuevo (ADR-0006).
 */
class HayParecidos extends RuntimeException
{
    /**
     * @param  list<array<string, mixed>>  $parecidos
     */
    public function __construct(public readonly array $parecidos)
    {
        parent::__construct('Hay registros parecidos en el padrón.');
    }
}
