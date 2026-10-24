<?php

namespace App\Support;

/**
 * Términos ya calculados por empresa durante una petición (o un proceso de
 * cola/cron): una sola consulta del giro por empresa. Se olvida al guardar
 * una empresa o un rubro.
 */
class RegistroTerminos
{
    /** @var array<int, Terminos> */
    private array $porEmpresa = [];

    /**
     * @param  callable(): Terminos  $calcular
     */
    public function recordar(int $empresaId, callable $calcular): Terminos
    {
        return $this->porEmpresa[$empresaId] ??= $calcular();
    }

    public function olvidar(): void
    {
        $this->porEmpresa = [];
    }
}
