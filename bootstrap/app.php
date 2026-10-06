<?php

use App\Http\Middleware\ControlarInactividad;
use App\Http\Middleware\EncabezadosSeguridad;
use App\Http\Middleware\EstablecerEmpresa;
use App\Http\Middleware\VerificarHost;
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
        // Cierre por inactividad, empresa activa y cierre de usuarios desactivados
        $middleware->web(append: [
            ControlarInactividad::class,
            EstablecerEmpresa::class,
        ]);
        $middleware->redirectUsersTo(fn () => route('panel'));

        // Seguridad: dominio verificado (enlaces sin Host falso) y encabezados de endurecimiento
        $middleware->prepend(VerificarHost::class);
        $middleware->append(EncabezadosSeguridad::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Formulario abierto demasiado tiempo (token CSRF vencido): volver a la
        // pantalla de acceso con un aviso, en lugar de la pagina de error 419.
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() === 419 && ! $request->expectsJson()) {
                return redirect()->route('login')->with('acceso', 'pagina_vencida');
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
