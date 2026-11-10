<?php

namespace App\Services\Colaboradores;

use RuntimeException;

/**
 * El alta provisional coincide con alguien que ya está en el directorio:
 * se le ofrece al guardia usar ese registro en lugar de duplicarlo.
 */
class ColaboradorParecido extends RuntimeException
{
    /**
     * @param  list<array<string, mixed>>  $parecidos
     */
    public function __construct(public readonly array $parecidos)
    {
        parent::__construct('Ya hay un colaborador con ese nombre.');
    }
}
