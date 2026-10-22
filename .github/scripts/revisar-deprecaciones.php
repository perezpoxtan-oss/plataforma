<?php

// Revisa los avisos de deprecación que Laravel anotó durante las pruebas
// (LOG_DEPRECATIONS_CHANNEL=single -> storage/logs/laravel.log).
//  - En el código propio (app, config, database, routes, resources, bootstrap,
//    lang, tests): error, con archivo y línea, y la CI falla.
//  - En librerías (vendor/): aviso informativo con el paquete; no falla, porque
//    no se corrige aquí sino actualizando la librería.
// Se usa para comprobar cada versión nueva de PHP (p. ej. 8.5) antes de
// cambiarla en el hospedaje. Ver docs/tecnico/despliegue.md.
$archivo = $argv[1] ?? 'storage/logs/laravel.log';
$raiz = rtrim((string) realpath(getcwd()), '/').'/';

if (! is_file($archivo)) {
    echo "Sin avisos de deprecación (no se creó {$archivo}).\n";
    exit(0);
}

$propios = [];
$librerias = [];
$texto = (string) file_get_contents($archivo);
preg_match_all('/^\[[^\]]+\] \w+\.WARNING: (.+?) in (\S+?\.php) on line (\d+)/m', $texto, $avisos, PREG_SET_ORDER);

foreach ($avisos as [, $mensaje, $ruta, $linea]) {
    $relativa = str_starts_with($ruta, $raiz) ? substr($ruta, strlen($raiz)) : $ruta;
    if (preg_match('#^vendor/([^/]+/[^/]+)/#', $relativa, $paquete)) {
        $librerias[$paquete[1]][$mensaje] = ($librerias[$paquete[1]][$mensaje] ?? 0) + 1;
    } elseif (! str_starts_with($relativa, '/')) {
        $propios["{$relativa}:{$linea}"] = [$relativa, $linea, $mensaje];
    }
}

$escapar = fn (string $s) => str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $s);

foreach ($librerias as $paquete => $mensajes) {
    foreach ($mensajes as $mensaje => $veces) {
        echo '::warning title=Deprecación en librería '.$escapar($paquete).'::'.$escapar(substr($mensaje, 0, 500))." ({$veces} veces)\n";
    }
}

foreach ($propios as [$relativa, $linea, $mensaje]) {
    echo "::error file={$relativa},line={$linea},title=Deprecación en código propio::".$escapar(substr($mensaje, 0, 500))."\n";
}

printf("PHP %s: %d avisos de deprecación en código propio, %d librerías con avisos.\n", PHP_VERSION, count($propios), count($librerias));
exit($propios === [] ? 0 : 1);
