<?php

use App\Http\Middleware\CabecerasSeguridad;
use App\Http\Middleware\ConservarAvisos;
use App\Http\Middleware\ControlarInactividad;
use App\Http\Middleware\EstablecerEmpresa;
use App\Http\Middleware\ExigirCambioContrasena;
use App\Http\Middleware\ExigirDosPasos;
use App\Http\Middleware\FirmasComoArchivo;
use App\Http\Middleware\MedirTiempos;
use App\Http\Middleware\PortadaPublica;
use App\Http\Middleware\SoloConsulta;
use App\Http\Middleware\VerificarHost;
use App\Support\RegresoTrasEnvio;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Cierre por inactividad, empresa activa y cierre de usuarios desactivados.
        // Firmas enviadas como archivo: vuelven a su campo original antes de los
        // controladores (el firewall del hospedaje bloquea los textos base64 largos).
        $middleware->web(append: [
            // Las consultas en segundo plano de una pantalla no gastan los avisos del envío anterior
            ConservarAvisos::class,
            FirmasComoArchivo::class,
            ControlarInactividad::class,
            EstablecerEmpresa::class,
            ExigirCambioContrasena::class,
            // Verificación en dos pasos obligatoria (Super Administrador o política de la empresa): primero activarla
            ExigirDosPasos::class,
            // Suscripciones: empresa vencida (después de la gracia), suspendida o dada de baja = solo consulta
            SoloConsulta::class,
        ]);
        $middleware->redirectUsersTo(fn () => route('panel'));
        // Catálogo comercial: la portada pública de «/» decide antes que «auth» (sin sesión y publicada, se muestra)
        $middleware->prependToPriorityList(AuthenticatesRequests::class, PortadaPublica::class);

        // Seguridad: dominio verificado (enlaces sin Host falso)
        $middleware->prepend(VerificarHost::class);
        // Diagnóstico de velocidad: el primero de todos, para medir la visita completa
        $middleware->prepend(MedirTiempos::class);
        // Seguridad: cabeceras (CSP, clickjacking, nosniff, HSTS, sin caché con sesión) en toda respuesta
        $middleware->append(CabecerasSeguridad::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Seguridad: contraseñas (de usuario o del correo SMTP) nunca se guardan en la sesión al fallar una validación
        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password', 'contrasena', 'contrasena_actual', 'codigo']);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Formulario abierto demasiado tiempo (token CSRF vencido): volver a la
        // pantalla de acceso con un aviso, en lugar de la pagina de error 419.
        $exceptions->render(function (HttpException $e, Request $request) {
            // Una dirección de envío abierta como página (recargar, volver atrás,
            // pestaña restaurada): regresar a la pantalla de origen, no al error 405.
            if ($e->getStatusCode() === 405 && $request->isMethodSafe() && ! $request->expectsJson()) {
                $destino = RegresoTrasEnvio::destino($request);
                RegresoTrasEnvio::registrar($request, $destino);
                if ($destino !== null) {
                    return redirect()->to(RegresoTrasEnvio::conAviso($destino));
                }
            }
            if ($e->getStatusCode() === 419 && ! $request->expectsJson()) {
                // Ronda 5: el cierre por inactividad con la sesión ya terminada avisa "Sesión finalizada por seguridad"
                return redirect()->route('login')->with('acceso', $request->is('sesion/expirada') ? 'expirado' : 'pagina_vencida');
            }

            return null;
        });
    })->create();

// Carpeta publica fuera del proyecto (hosting compartido): la ruta vive en .public_path.
// Si el archivo no existe se usa la carpeta public/ normal (desarrollo, CI).
$publicPathFile = dirname(__DIR__).'/.public_path';
if (is_file($publicPathFile)) {
    $publicPath = trim((string) file_get_contents($publicPathFile));
    if ($publicPath !== '' && is_dir($publicPath)) {
        $app->usePublicPath($publicPath);
    }
}

return $app;
