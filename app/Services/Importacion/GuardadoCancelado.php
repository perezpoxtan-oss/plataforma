<?php

namespace App\Services\Importacion;

/**
 * Uso interno (todo o nada): una fila falló al guardar, se deshace la
 * transacción completa y se devuelven los resultados para mostrarlos.
 */
final class GuardadoCancelado extends \RuntimeException
{
    /**
     * @param  list<array<string, mixed>>  $resultados
     */
    public function __construct(public readonly array $resultados)
    {
        parent::__construct('Importación cancelada: hay filas con error.');
    }
}
