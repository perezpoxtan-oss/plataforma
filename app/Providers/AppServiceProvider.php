<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Permisos\Autorizador;
use App\Support\Identidad;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Una instancia por peticion (o por proceso de cola/cron)
        $this->app->scoped(Tenant::class);
        $this->app->scoped(Autorizador::class);
        $this->app->scoped(Identidad::class);
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        // Toda habilidad con forma "modulo.accion" la resuelve el motor de permisos.
        // Asi funcionan @can, $user->can(), authorize() y el middleware "can:".
        Gate::before(function (User $usuario, string $habilidad, array $argumentos) {
            if (! str_contains($habilidad, '.')) {
                return null;
            }

            $registro = $argumentos[0] ?? null;

            return app(Autorizador::class)->puede(
                $usuario,
                $habilidad,
                $registro instanceof Model ? $registro : null,
            );
        });
    }
}
