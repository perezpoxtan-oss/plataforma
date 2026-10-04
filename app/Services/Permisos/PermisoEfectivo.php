<?php

namespace App\Services\Permisos;

/**
 * Resultado de combinar todos los roles de un usuario para un permiso.
 */
final class PermisoEfectivo
{
    /**
     * @param  list<int>|null  $sedes  Sedes donde aplica; null = todas las sedes de la empresa.
     */
    public function __construct(
        public readonly Alcance $alcance,
        public readonly ?array $sedes,
    ) {}
}
