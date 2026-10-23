<?php

namespace App\Services\Manual;

/**
 * Una página del manual de usuario (docs/usuario/<slug>.md) con los datos de
 * su encabezado (front matter).
 */
final class PaginaManual
{
    /**
     * @param  list<string>  $modulos  claves del catálogo («modulo» = puede verlo; «modulo.accion» = esa acción exacta); vacío = página general (todos la ven)
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $titulo,
        public readonly array $modulos,
        public readonly string $seccion,
        public readonly int $orden,
        public readonly string $resumen,
    ) {}

    /**
     * Solo las claves de módulo (sin la acción), en el mismo orden.
     *
     * @return list<string>
     */
    public function clavesDeModulo(): array
    {
        return array_values(array_unique(array_map(fn (string $m) => explode('.', $m, 2)[0], $this->modulos)));
    }

    public function esGeneral(): bool
    {
        return $this->modulos === [];
    }
}
