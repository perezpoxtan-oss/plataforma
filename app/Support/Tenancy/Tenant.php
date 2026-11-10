<?php

namespace App\Support\Tenancy;

/**
 * Empresa activa en la peticion actual.
 *
 * - Usuario de empresa: siempre su empresa (no puede cambiarla).
 * - Super Administrador: puede elegir una empresa para trabajar; si no elige,
 *   no se aplica filtro (vista de plataforma).
 * - Consola / cron: sin filtro, salvo que el comando fije una empresa.
 */
class Tenant
{
    private ?int $empresaId = null;

    public function establecer(?int $empresaId): void
    {
        $this->empresaId = $empresaId;
    }

    public function empresaId(): ?int
    {
        return $this->empresaId;
    }

    public function activo(): bool
    {
        return $this->empresaId !== null;
    }

    /**
     * Ejecuta un bloque con una empresa fija y restaura la anterior.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function conEmpresa(?int $empresaId, callable $callback): mixed
    {
        $anterior = $this->empresaId;
        $this->empresaId = $empresaId;

        try {
            return $callback();
        } finally {
            $this->empresaId = $anterior;
        }
    }
}
