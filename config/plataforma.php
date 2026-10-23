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

    /*
    | Diagnóstico de velocidad (App\Http\Middleware\MedirTiempos): las visitas
    | que tardan al menos estos milisegundos en el servidor se anotan en
    | storage/logs/lentas-AAAA-MM-DD.log. 0 = anotar todas.
    */
    'velocidad' => [
        'lento_ms' => (int) env('PLATAFORMA_LENTO_MS', 1500),
    ],

    /*
    | Versión de PHP del servidor (Configuración → Plataforma, solo Super
    | Administrador; App\Services\Plataforma\VersionPhp). Versión mínima por
    | rama para considerarla «Al día»; una rama anterior a la más baja de la
    | lista ya no tiene soporte y siempre pide actualizar. Cuando salga una
    | corrección nueva, cambia aquí las versiones y el aviso.
    */
    'php' => [
        'minimas' => [
            '8.5' => '8.5.12',
            '8.4' => '8.4.27',
            '8.3' => '8.3.36',
            '8.2' => '8.2.35',
        ],
        'aviso' => 'Corrige la falla de seguridad PHP2Shell (CVE-2026-109618 y CVE-2026-109619). Pide a tu proveedor de hospedaje la versión corregida.',
        // Archivo de estado del despliegue con la línea «PHP del cron:»; vacío = se deduce de la carpeta de la aplicación
        'estado_despliegue' => env('PLATAFORMA_ESTADO_DESPLIEGUE'),
    ],

];
