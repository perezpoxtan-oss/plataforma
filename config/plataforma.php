<?php

return [

    /*
    | Seguridad de la sesion (mismos valores que SEGCAT).
    */
    'sesion' => [
        // Minutos sin actividad antes de cerrar la sesion
        'inactividad_minutos' => (int) env('PLATAFORMA_INACTIVIDAD_MINUTOS', 20),
        // Aviso previo al cierre, en segundos
        'aviso_segundos' => 120,
        // Intentos fallidos seguidos antes de bloquear la cuenta
        'max_intentos' => 5,
        'minutos_bloqueo' => 15,
        // Intentos por minuto desde un mismo equipo (IP), sin importar la cuenta
        'intentos_por_ip' => 20,
    ],

    /*
    | Seguridad: dominios desde los que se atiende la plataforma (VerificarHost).
    | Por defecto, solo el de APP_URL; PLATAFORMA_HOSTS agrega otros separados
    | por coma. Fuera de "local" y "testing" la verificación está activa.
    */
    'hosts' => [
        'verificar' => (bool) env('PLATAFORMA_VERIFICAR_HOST', ! in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)),
        'permitidos' => array_values(array_filter(array_map('trim', [
            (string) parse_url((string) env('APP_URL', ''), PHP_URL_HOST),
            ...explode(',', (string) env('PLATAFORMA_HOSTS', '')),
        ]))),
    ],

];
