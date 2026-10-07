<?php

namespace App\Services\Recepcion;

use App\Models\User;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use Illuminate\Support\Collection;

/**
 * A quién avisar: usuarios activos de la empresa con un permiso que alcance
 * la sede del asunto (mismo criterio que los avisos por correo).
 */
class Destinatarios
{
    public function __construct(private readonly Autorizador $autorizador) {}

    /**
     * @return Collection<int, User>
     */
    public function conPermiso(int $empresaId, string $permiso, ?int $sedeId): Collection
    {
        return User::where('empresa_id', $empresaId)->where('activo', true)->get()
            ->filter(fn (User $u) => $this->alcanza($u, $permiso, $sedeId))
            ->values();
    }

    /**
     * Usuarios activos de la empresa que tienen el permiso en alguna sede (o en toda la empresa).
     *
     * @return Collection<int, User>
     */
    public function conPermisoEnAlgunaSede(int $empresaId, string $permiso): Collection
    {
        return User::where('empresa_id', $empresaId)->where('activo', true)->get()
            ->filter(fn (User $u) => ($this->autorizador->permisosEfectivos($u)[$permiso] ?? null) !== null)
            ->values();
    }

    public function alcanza(User $u, string $permiso, ?int $sedeId): bool
    {
        $efectivo = $this->autorizador->permisosEfectivos($u)[$permiso] ?? null;

        return $efectivo !== null && ($efectivo->alcance === Alcance::Empresa || $efectivo->sedes === null
            || ($sedeId !== null && in_array($sedeId, $efectivo->sedes, true)));
    }
}
