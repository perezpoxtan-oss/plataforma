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

];
