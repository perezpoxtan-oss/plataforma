<?php

namespace App\Services\Manual;

/**
 * Una página del manual de usuario (docs/usuario/<slug>.md) con los datos de
 * su encabezado (front matter).
 */
final class PaginaManual
{
    /**
     * @param  list<string>  $modulos  claves del catálogo; vacío = página general (todos la ven)
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $titulo,
        public readonly array $modulos,
        public readonly string $seccion,
        public readonly int $orden,
        public readonly string $resumen,
    ) {}

    public function esGeneral(): bool
    {
        return $this->modulos === [];
    }
}
