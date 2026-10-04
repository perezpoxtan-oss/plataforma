<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Permisos\Autorizador;
use App\Support\HoraLocal;
use App\Support\Identidad;
use App\Support\Menu\ConstructorMenu;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Una instancia por peticion (o por proceso de cola/cron)
        $this->app->scoped(Tenant::class);
        $this->app->scoped(Autorizador::class);
        $this->app->scoped(Identidad::class);
        $this->app->scoped(ConstructorMenu::class);
        $this->app->scoped(HoraLocal::class);
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        // Fechas en la hora local de quien las ve: @fecha($registro->created_at) o @fecha($valor, 'H:i')
        Blade::directive('fecha', fn (string $expresion) => "<?php echo e(app(\\App\\Support\\HoraLocal::class)->formatear({$expresion})); ?>");

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

        // Nombre, colores y logos de la plataforma en todas las vistas
        View::composer('*', function ($vista) {
            $vista->with('identidad', app(Identidad::class));
        });

        // Menu y datos del usuario para la estructura de pantallas
        View::composer('layouts.app', function ($vista) {
            $usuario = auth()->user();
            $ruta = request()->route();
            $menus = app(ConstructorMenu::class)->para(
                $usuario,
                $ruta?->getName(),
                $ruta?->getName() === 'modulos.pendiente' ? $ruta->parameter('clave') : null,
            );

            // Atajos fijos de la barra inferior del celular (como SEGCAT)
            $items = collect($menus)->flatMap(fn ($menu) => collect($menu['secciones'])->flatten(1))->keyBy('clave');
            $atajos = collect(['novedades' => ['Novedades', 'bi-headset'], 'accesos' => ['Accesos', 'bi-journal-text']])
                ->filter(fn ($_, $clave) => $items->has($clave))
                ->map(fn ($atajo, $clave) => [...$items[$clave], 'nombre' => $atajo[0], 'icono' => $atajo[1]])
                ->values()
                ->all();

            $vista->with([
                'usuario' => $usuario,
                'rolNombre' => $usuario->nombreRolPrincipal(),
                'menus' => $menus,
                'atajos' => $atajos,
            ]);
        });
    }
}
