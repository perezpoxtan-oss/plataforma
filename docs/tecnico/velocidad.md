# Diagnóstico de velocidad

`App\Http\Middleware\MedirTiempos` (el primer middleware global) mide cada visita y separa dónde se va el tiempo:

| Parte | Qué mide | Si es alta |
|---|---|---|
| `espera` | Desde que el servidor web recibió la petición (`REQUEST_TIME_FLOAT`) hasta que PHP empezó (`LARAVEL_START`). | Cola o límites del hospedaje (procesos de entrada, CPU), arranque de PHP. No depende del código. |
| `arranque` | Carga del código y la configuración hasta el primer middleware. | OPcache apagado o cachés de configuración/rutas sin generar. |
| `bd` | Suma del tiempo de las consultas a la base de datos y cuántas fueron. | Consultas lentas (índices), demasiadas consultas o servidor de base de datos saturado. |
| `codigo` | El resto: programa, pantallas, sesión. | Trabajo en PHP o en las vistas. |
| `total` | Todo lo anterior. | — |

## Cómo verlo en el navegador (solo Super Administrador)

La respuesta lleva la cabecera estándar `Server-Timing` únicamente si la sesión es de un Super Administrador. En Chrome: **F12 → Network → (clic en la pantalla) → Timing**, sección **Server Timing**. Incluye además las 3 consultas más lentas (solo el texto SQL con `?`, sin valores).

## Registro de visitas lentas

Las visitas cuyo `total` llega a `config('plataforma.velocidad.lento_ms')` (variable `PLATAFORMA_LENTO_MS`, 1500 ms por omisión; 0 = todas) se anotan en `storage/logs/lentas-AAAA-MM-DD.log` (14 días): método, nombre de ruta, estado, desglose, número de consultas, memoria y las 5 consultas más lentas sin valores. En el hospedaje está en `shared/storage/logs/` (Administrador de archivos de cPanel). Si escribir falla, la pantalla no se afecta.

## Pruebas

`tests/Feature/Nucleo/MedirTiemposTest.php`: cabecera solo para Super Administrador; nadie más (sin sesión ni administrador de empresa) la recibe; las visitas lentas se anotan sin valores; las rápidas no.
