<?php

namespace App\Support\Tenancy;

use App\Models\Empresa;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Empresa que se administra en las pantallas de configuracion.
 *
 * - Usuario de empresa: siempre la suya.
 * - Super Administrador: la que elija en el selector; sin eleccion trabaja
 *   sobre las plantillas de la plataforma (lo que reciben las empresas nuevas).
 */
class EmpresaDeTrabajo
{
    public const SESION = 'empresa_activa_id';

    public function id(User $usuario): ?int
    {
        if (! $usuario->es_superadmin) {
            return (int) $usuario->empresa_id;
        }

        $id = session(self::SESION);

        return $id === null ? null : (int) $id;
    }

    public function esPlantillas(User $usuario): bool
    {
        return $usuario->es_superadmin && $this->id($usuario) === null;
    }

    /**
     * Opciones del selector (solo Super Administrador).
     *
     * @return Collection<int, Empresa>
     */
    public function opciones(): Collection
    {
        return Empresa::query()->orderBy('nombre_comercial')->get(['id', 'nombre_comercial', 'activo']);
    }
}
