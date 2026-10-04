<?php

use App\Http\Middleware\EstablecerEmpresa;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Empresa activa y cierre de sesion de usuarios desactivados
        $middleware->web(append: [
            EstablecerEmpresa::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
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
