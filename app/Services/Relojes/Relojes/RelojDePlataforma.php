<?php

namespace App\Services\Relojes\Relojes;

use App\Models\User;
use App\Services\Relojes\Espera;
use App\Services\Relojes\Reloj;
use Illuminate\Support\Collection;

/**
 * Lo que espera al Super Administrador (C-26, E-05): solicitudes, pruebas
 * gratis y clientes. Se recuerda a todos los Super Administradores; al
 * suplente (de nuevo todos, con el aviso que no se puede silenciar), porque
 * en la plataforma no hay a quién más pasarlo (DD-21 exige dos).
 */
abstract class RelojDePlataforma extends Reloj
{
    public function plataforma(): bool
    {
        return true;
    }

    public function suplenteOmision(): string
    {
        return 'todos los Super Administradores (aviso que no se puede silenciar)';
    }

    public function responsables(Espera $e, ?int $empresaId): Collection
    {
        return User::where('es_superadmin', true)->where('activo', true)->get();
    }
}
