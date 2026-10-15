<?php

/*
|--------------------------------------------------------------------------
| App de Android (carpeta movil/). Ver docs/tecnico/app-movil.md.
|--------------------------------------------------------------------------
| App Links: con la huella SHA-256 de la llave con la que se firma la app,
| la plataforma publica /.well-known/assetlinks.json y Android abre los
| enlaces /e/… directamente en la app (sin preguntar). Vacía = no se publica
| (404) y esos enlaces siguen abriendo el navegador. Varias huellas separadas
| por coma (p. ej. la de Google Play y la de la llave de subida).
*/

return [
    'paquete' => env('APP_MOVIL_PAQUETE', 'mx.smartcentinel.app'),
    'huellas_sha256' => (string) env('APP_MOVIL_HUELLAS_SHA256', ''),
];
