<?php

namespace App\Providers;

use App\Models\Empresa;
use App\Models\Rubro;
use App\Models\User;
use App\Services\Permisos\Autorizador;
use App\Support\HoraLocal;
use App\Support\Identidad;
use App\Support\Menu\ConstructorMenu;
use App\Support\RegistroTerminos;
use App\Support\Tenancy\Tenant;
use App\Support\Terminos;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
        // Términos por giro (Terminos::deEmpresaId): una consulta por empresa y petición
        $this->app->scoped(RegistroTerminos::class);
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        // Seguridad: en Produccion no corren migrate:fresh/refresh/reset/rollback ni db:wipe
        DB::prohibitDestructiveCommands($this->app->isProduction());

        $this->limitadoresPublicos();

        // Giros: los correos y avisos dicen las palabras del giro de la empresa activa (en hotel no cambia nada)
        Event::listen(MessageSending::class, function (MessageSending $evento): void {
            $terminos = Terminos::actual();
            if ($terminos->esHotel()) {
                return;
            }
            $mensaje = $evento->message;
            if (is_string($mensaje->getSubject())) {
                $mensaje->subject($terminos->texto($mensaje->getSubject()));
            }
            $html = $mensaje->getHtmlBody();
            if ($html !== null) {
                $mensaje->html($terminos->html(is_string($html) ? $html : (string) stream_get_contents($html)));
            }
            $texto = $mensaje->getTextBody();
            if ($texto !== null) {
                $mensaje->text($terminos->texto(is_string($texto) ? $texto : (string) stream_get_contents($texto)));
            }
        });

        // Giros: al cambiar el rubro de una empresa (o su terminología) se recalculan sus términos
        foreach ([Empresa::class, Rubro::class] as $modelo) {
            $modelo::saved(fn () => app(RegistroTerminos::class)->olvidar());
        }

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
                // Mejoras Incidentes: el botón de la bitácora lleva el nombre que la empresa le dio en el menú (Configuración)
                ->map(fn ($atajo, $clave) => [...$items[$clave], 'nombre' => $clave === 'novedades' && $items[$clave]['nombre'] !== 'Novedades' ? $items[$clave]['nombre'] : $atajo[0], 'icono' => $atajo[1]])
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

        // Página pública de planes: consultar y enviar la solicitud de compra no comparten contador
        RateLimiter::for('comercial-ver', fn (Request $r) => Limit::perMinute(120)->by('comercial-ver|'.$ip($r)));
        RateLimiter::for('comercial-contratar', fn (Request $r) => [
            Limit::perMinute(5)->by('comercial-contratar|'.$ip($r)),
            Limit::perDay(30)->by('comercial-contratar-dia|'.$ip($r)),
        ]);

        // Privacidad (docs/tecnico/privacidad.md): consultar el aviso, enviar una solicitud ARCO y confirmar el correo
        RateLimiter::for('privacidad-ver', fn (Request $r) => Limit::perMinute(60)->by('privacidad-ver|'.$ip($r)));
        RateLimiter::for('privacidad-enviar', fn (Request $r) => [
            Limit::perMinute(3)->by('privacidad-enviar|'.$ip($r)),
            Limit::perDay(20)->by('privacidad-enviar-dia|'.$ip($r)),
        ]);
        // Verificación en dos pasos en Mi cuenta: activar, códigos nuevos y quitarla (piden contraseña o código), por cuenta
        RateLimiter::for('dos-pasos-cuenta', fn (Request $r) => Limit::perMinute(10)->by('dos-pasos-cuenta|'.($r->user()?->getAuthIdentifier() ?? $ip($r))));
        RateLimiter::for('privacidad-confirmar', fn (Request $r) => Limit::perMinute(10)->by('privacidad-confirmar|'.$ip($r)));
    }
}
