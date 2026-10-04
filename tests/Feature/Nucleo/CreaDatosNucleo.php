<?php

namespace Tests\Feature\Nucleo;

use App\Models\Empresa;
use App\Models\Rol;
use App\Models\Rubro;
use App\Models\Sede;
use App\Models\User;
use App\Models\UsuarioRol;
use App\Services\Plataforma\ProvisionarEmpresa;
use App\Support\Tenancy\Tenant;
use Database\Seeders\DatabaseSeeder;

trait CreaDatosNucleo
{
    protected function sembrarCatalogo(): void
    {
        $this->seed(DatabaseSeeder::class);
    }

    /**
     * @param  list<string>|null  $modulos
     */
    protected function crearEmpresa(string $nombre = 'Hotel Uno', ?array $modulos = null): Empresa
    {
        $rubro = Rubro::where('clave', 'hotel')->firstOrFail();

        return app(ProvisionarEmpresa::class)->crear($rubro, ['nombre_comercial' => $nombre], $modulos);
    }

    protected function crearSede(Empresa $empresa, string $codigo = 'S1'): Sede
    {
        return app(Tenant::class)->conEmpresa($empresa->id, fn () => Sede::create([
            'codigo' => $codigo,
            'nombre' => "Sede {$codigo}",
        ]));
    }

    protected function crearUsuario(Empresa $empresa, ?string $rol = null, ?Sede $sede = null): User
    {
        $usuario = User::factory()->create(['empresa_id' => $empresa->id]);

        if ($rol !== null) {
            $this->darRol($usuario, $rol, $sede);
        }

        return $usuario;
    }

    protected function darRol(User $usuario, string $rol, ?Sede $sede = null): void
    {
        $rolModelo = Rol::where('empresa_id', $usuario->empresa_id)->where('nombre', $rol)->firstOrFail();

        UsuarioRol::create(['user_id' => $usuario->id, 'rol_id' => $rolModelo->id, 'sede_id' => $sede?->id]);
    }

    protected function crearSuperadmin(): User
    {
        $usuario = User::factory()->create(['empresa_id' => null]);
        $usuario->forceFill(['es_superadmin' => true])->save();

        return $usuario;
    }
}
