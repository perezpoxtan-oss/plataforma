<?php

// Convierte las fallas de junit.xml en anotaciones de GitHub, para poder
// leerlas desde la API sin descargar los registros completos.
$archivo = $argv[1] ?? 'junit.xml';
if (! is_file($archivo)) {
    echo "::error title=Pruebas::No se genero {$archivo}; revisa el paso de pruebas.\n";
    exit(0);
}

$xml = simplexml_load_file($archivo);
$total = 0;
foreach ($xml->xpath('//testcase[failure or error]') as $caso) {
    $detalle = (string) ($caso->failure ?? $caso->error);
    $mensaje = substr($detalle, 0, 1800);
    $mensaje = str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $mensaje);
    $titulo = $caso['class'].'::'.$caso['name'];
    echo "::error title={$titulo}::{$mensaje}\n";
    if (++$total >= 40) {
        break;
    }
}
echo "Fallas anotadas: {$total}\n";
