<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Permisos\Autorizador;
use App\Support\HoraLocal;
use App\Support\Identidad;
use App\Support\Menu\ConstructorMenu;
use App\Support\Tenancy\Tenant;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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

        // Seguridad: en Produccion no corren migrate:fresh/refresh/reset/rollback ni db:wipe
        DB::prohibitDestructiveCommands($this->app->isProduction());

        $this->limitadoresPublicos();

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

            // Atajos fijos de la barra inferior del celular (como SEGCAT), en el orden del menú Operación
            $items = collect($menus)->flatMap(fn ($menu) => collect($menu['secciones'])->flatten(1))->keyBy('clave');
            $atajos = collect(['accesos' => ['Accesos', 'bi-journal-text'], 'novedades' => ['Novedades', 'bi-headset']])
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

    /**
     * Límites de las pantallas públicas (sin sesión). Para un invitado,
     * «throttle:10,1» cuenta por IP y comparte el contador entre todas las
     * rutas; en recepción todas las tabletas salen por la misma IP, así que
     * cada acción lleva su propio contador y guardar se cuenta además por enlace.
     */
    private function limitadoresPublicos(): void
    {
        $ip = fn (Request $r): string => (string) $r->ip();
        $token = fn (Request $r): string => substr((string) $r->route('token'), 0, 64);

        // Kiosco de candidatos: abrir la pantalla (con o sin enlace), canjear el código de 6 caracteres y guardar
        RateLimiter::for('kiosco-ver', fn (Request $r) => Limit::perMinute(120)->by('ver|'.$ip($r).'|'.$token($r)));
        RateLimiter::for('kiosco-canjear', fn (Request $r) => Limit::perMinute(10)->by('canjear|'.$ip($r)));
        RateLimiter::for('kiosco-guardar', fn (Request $r) => [
            Limit::perMinute(10)->by('guardar|'.$ip($r).'|'.$token($r)),
            Limit::perMinute(60)->by('guardar-ip|'.$ip($r)),
        ]);

        // Bolsa de trabajo pública: consultar y postular no comparten contador
        RateLimiter::for('empleos-ver', fn (Request $r) => Limit::perMinute(120)->by('ver|'.$ip($r)));
        RateLimiter::for('empleos-postular', fn (Request $r) => Limit::perMinute(6)->by('postular|'.$ip($r)));
    }
}
