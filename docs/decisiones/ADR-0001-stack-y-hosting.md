# ADR-0001: Laravel 13 en Neubox cPanel

- Estado: aceptada
- Fecha: 2026-10-04

## Contexto
El sistema actual (SEGCAT) es PHP sin framework. Se migra en modo espejo, con API para apps móviles. El hosting es Neubox cPanel compartido (CloudLinux, MariaDB 11.4, PHP 8.4), sin SSH ni Terminal.

## Decisión
- Laravel 13 sobre PHP 8.4.
- Código fuera de la carpeta pública: `/home/vdcpcomm/apps/plataforma-prod`; el subdominio `app.vdcp.com.mx` solo contiene los archivos públicos y su `index.php` apunta al proyecto. La ruta pública se lee de `.public_path` en `bootstrap/app.php`.
- PHP de línea de comandos: `/opt/alt/php84/usr/bin/php` (`/usr/bin/php` es php-cgi).
- El hosting deshabilita `proc_open`, `escapeshellarg`, `symlink` y otras; los comandos de cron usan `-d disable_functions=`.
- Todas las tablas en InnoDB (`DB_ENGINE`, por defecto `InnoDB`): el MariaDB del hosting crea MyISAM si no se indica.
- Cron mínimo recomendado: cada 5 minutos. Las tareas programadas se ejecutan dentro del proceso (sin depender de `proc_open`).
- Despliegue por script de cron que descarga la versión etiquetada aprobada desde GitHub.

## Consecuencias
Sin procesos permanentes ni WebSockets: colas procesadas por cron y avisos urgentes enviados en la misma petición. Migrar a VPS cuando el hardware o el volumen lo pidan.
