<?php

namespace App\Services\Plataforma;

/**
 * Versión de PHP del servidor para Configuración → Plataforma (solo Super
 * Administrador; docs/tecnico/configuracion.md, «Versión de PHP del servidor»).
 *
 *  - Web: PHP_VERSION del proceso que atiende la página.
 *  - Cron: la línea «PHP del cron:» que desplegar.sh escribe en
 *    $HOME/despliegue_<ambiente>_estado.txt (si existe y se puede leer).
 *  - Estado: «Al día» si la versión es igual o mayor que la mínima de su rama
 *    (config('plataforma.php.minimas')); «Requiere actualización» si es menor,
 *    o si la rama ya no tiene soporte (anterior a la más baja de la lista).
 *    Una rama más nueva que todas las de la lista cuenta como al día.
 *
 * Nada de esto se manda en cabeceras HTTP ni se muestra a nadie más.
 */
class VersionPhp
{
    /** @param string|null $version versión a evaluar (pruebas); null = la del servidor */
    public function __construct(private readonly ?string $version = null) {}

    public function web(): string
    {
        return $this->version ?? PHP_VERSION;
    }

    /**
     * @return array{version: string, rama: string, minima: ?string, al_dia: bool}
     */
    public static function evaluar(string $version): array
    {
        $version = trim($version);
        $minimas = (array) config('plataforma.php.minimas', []);
        preg_match('/^(\d+)\.(\d+)(?:\.(\d+))?/', $version, $m);
        $limpia = $m ? $m[1].'.'.$m[2].'.'.($m[3] ?? '0') : '0.0.0';
        $rama = $m ? $m[1].'.'.$m[2] : '';
        $minima = $minimas[$rama] ?? null;

        if ($minima !== null) {
            $alDia = version_compare($limpia, (string) $minima, '>=');
        } else {
            // Rama fuera de la lista: más nueva que todas = al día; más vieja (sin soporte) = requiere actualización
            $ramas = array_keys($minimas);
            usort($ramas, 'version_compare');
            $masNueva = end($ramas);
            $alDia = $m && $masNueva !== false && version_compare($rama.'.0', $masNueva.'.0', '>');
        }

        return ['version' => $version, 'rama' => $rama, 'minima' => $minima, 'al_dia' => $alDia];
    }

    /**
     * Versión del PHP de las tareas programadas, si el despliegue la anotó.
     */
    public function cron(): ?string
    {
        $archivo = $this->archivoEstado();
        if ($archivo === null || ! is_file($archivo) || ! is_readable($archivo)) {
            return null;
        }
        $texto = (string) @file_get_contents($archivo, false, null, 0, 8192);
        if (! preg_match('/^PHP del cron:\s*(\d+\.\d+\.\d+[0-9A-Za-z.+-]*)/mu', $texto, $m)) {
            return null;
        }

        return $m[1];
    }

    /**
     * Archivo de estado del despliegue: el de config o, en el servidor, el que
     * corresponde a la carpeta de la aplicación ($HOME/apps/plataforma-<amb>/releases/…).
     */
    public function archivoEstado(): ?string
    {
        $config = config('plataforma.php.estado_despliegue');
        if (is_string($config) && $config !== '') {
            return $config;
        }
        $base = realpath(base_path()) ?: base_path();
        if (preg_match('#^(.+)/apps/plataforma-(qa|produccion)(?:/|$)#', $base, $m)) {
            return $m[1].'/despliegue_'.($m[2] === 'produccion' ? 'prod' : 'qa').'_estado.txt';
        }

        return null;
    }

    /**
     * Todo lo que muestra la tarjeta de Configuración.
     *
     * @return array<string, mixed>
     */
    public function datos(): array
    {
        $cron = $this->cron();
        $opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
        $tiempo = (int) ini_get('max_execution_time');

        return [
            'web' => self::evaluar($this->web()),
            'cron' => $cron === null ? null : self::evaluar($cron),
            'opcache' => is_array($opcache) && ! empty($opcache['opcache_enabled']),
            'memoria' => ini_get('memory_limit') === '-1' ? 'Sin límite' : (string) ini_get('memory_limit'),
            'tiempo' => $tiempo <= 0 ? 'Sin límite' : $tiempo.' s',
            'aviso' => (string) config('plataforma.php.aviso'),
        ];
    }
}
