# Despliegue a QA y Producción

## Cómo viaja una entrega

```
feature/*  ──PR aprobado──▶  develop  ──CI verde──▶  paquete "qa"  ──cron 5 min──▶  qa.vdcp.com.mx
develop ──PR──▶ main ──etiqueta v0.1.0──▶  paquete v0.1.0  ──desplegar_version.txt──▶  app.vdcp.com.mx
```

1. Al integrar un PR en `develop`, la CI corre las pruebas y, si pasan, el trabajo **Paquete para QA** arma `paquete.tar.gz` (código + librerías de producción, sin pruebas ni documentación) y lo publica en la versión `qa` de GitHub (*Releases*).
2. En el servidor, `desplegar.sh qa` corre cada 5 minutos desde cron. Si hay un paquete nuevo lo descarga, comprueba su huella sha256 y lo instala.
3. Producción solo cambia cuando se publica una etiqueta `vMAYOR.MENOR.PARCHE` y se escribe esa versión en `$HOME/desplegar_version.txt`. Escribir una versión anterior es regresar a ella.

## Estructura en el servidor

```
~/apps/plataforma-qa/
  releases/qa-<id>/        versiones instaladas (se guardan las 3 más recientes)
  actual -> releases/...   versión activa (acceso directo; el cambio es instantáneo)
  shared/.env              configuración (no cambia entre versiones)
  shared/storage/          archivos subidos, sesiones, bitácoras
  despliegue.log           detalle de cada instalación
~/qa.vdcp.com.mx/          carpeta pública del subdominio: css, js, vendor, index.php
~/despliegue_qa_estado.txt resumen legible de la última revisión
```

El `index.php` del subdominio lee el destino de `actual` en cada visita con `readlink()`: PHP guarda en caché las rutas resueltas y, sin esto, seguiría mostrando la versión anterior hasta dos minutos.

## Qué hace `desplegar.sh` en cada instalación

1. Descarga y verifica el paquete; lo descomprime en `releases/<nombre>`.
2. Enlaza `.env` y `storage` compartidos y limpia la caché de arranque.
3. Pone el sitio en mantenimiento, corre `migrate` y los `seeders` (son repetibles) y genera las cachés.
4. Copia los archivos públicos al subdominio y cambia `actual` a la versión nueva.
5. Consulta `/up`. Si no responde 200, **regresa solo a la versión anterior**, marca la nueva como fallida y no la reintenta hasta que llegue otra.

En cada revisión sin versión nueva, el script **asegura los permisos de lectura** de la carpeta pública (Apache lee `.htaccess`, css y js con otro usuario: 644/755) y **consulta `/up`**. Si no responde 200, lo anota como ADVERTENCIA en el archivo de estado.

Al instalar una versión, el script **se actualiza a sí mismo** con la copia de `despliegue/desplegar.sh` que trae el paquete: solo hay que subirlo a mano la primera vez.

Una falla en `migrate` deja la versión anterior funcionando. Las migraciones que sí alcanzaron a correr no se revierten: por eso cada migración debe ser compatible con la versión anterior del código.

## Archivos que se suben una sola vez

Se suben a la carpeta principal (`/home/vdcpcomm`) con el Administrador de archivos. El script los guarda en `~/.config/plataforma/` (solo lectura para el dueño) y **borra el original**.

| Archivo | Contenido |
|---|---|
| `github_token.txt` | Token *fine-grained* de GitHub, solo lectura de *Contents* del repositorio `plataforma` |
| `env_qa.txt` | Configuración de QA (plantilla en `despliegue/env_qa.txt`); la `APP_KEY` se genera sola |
| `env_prod.txt` | Configuración de Producción (plantilla en `despliegue/env_prod.txt`): debe decir `APP_ENV=production`, `APP_DEBUG=false` y `APP_URL` con `https://`, o el script no instala |
| `qa_inicial.txt` | Correo, nombre, usuario y contraseña para crear el Super Administrador y la empresa demo |

Para cambiar la configuración después, basta con subir otro `env_qa.txt`: se conserva la `APP_KEY` y queda un respaldo del `.env` anterior.

## Cron

```
*/5 * * * * /bin/bash /home/vdcpcomm/desplegar.sh qa
```

## QA frente a Producción

| | QA | Producción |
|---|---|---|
| Franja amarilla "Ambiente de pruebas" | Sí | No |
| `APP_ENV` | `qa` (modo estricto de Eloquent activo: los errores de programación se notan) | `production` |
| Buscadores | `noindex` | Indexable |
| Datos demo (`plataforma:demo`) | Sí | Bloqueado |
| Correo | Solo a bitácora | Real |

`SESSION_LIFETIME=120` es intencional: el cierre por inactividad lo controla la plataforma (`PLATAFORMA_INACTIVIDAD_MINUTOS=20`), que además avisa "Sesión finalizada por seguridad".

## Usuarios demo (QA)

`php artisan plataforma:demo` crea **Hotel Demo** (sedes Centro y Playa) con un usuario por rol. Todos usan la contraseña de `qa_inicial.txt`.

| Usuario | Rol | Sede |
|---|---|---|
| `admin.demo` | Administrador | Todas |
| `director.demo` | Director | Todas |
| `jefe.demo` | Jefe de seguridad | Todas |
| `supervisor.demo` | Supervisor | Centro |
| `agente.demo` | Agente | Centro |
| `agente2.demo` | Agente | Playa |

Además crea dos empresas de otros giros para probar cómo se adapta la plataforma (ver `docs/tecnico/giros.md`). Sus cuentas usan la misma contraseña:

| Usuario | Empresa (giro) | Rol | Sede |
|---|---|---|---|
| `corp.admin.demo` | Corporativo Demo (corporativo) | Administrador | Todas |
| `corp.jefe.demo` | Corporativo Demo (corporativo) | Jefe de seguridad | Todas |
| `corp.agente.demo` | Corporativo Demo (corporativo) | Agente | Torre Reforma |
| `condo.admin.demo` | Condominio Demo (condominio) | Administrador | Todas |
| `condo.jefe.demo` | Condominio Demo (condominio) | Jefe de seguridad | Todas |
| `condo.agente.demo` | Condominio Demo (condominio) | Agente | Residencial Norte |

## Probar el script fuera del servidor

`PLATAFORMA_API` y `PLATAFORMA_URL` sustituyen a GitHub y al sitio, para ensayarlo con un servidor simulado. Así se probaron la primera instalación, la actualización, la versión defectuosa con regreso automático y la corrida sin cambios.

## Respaldos de la base

`desplegar.sh` respalda la base antes de cada migración (`plataforma:respaldar --motivo=antes-de-actualizar`) y hace el respaldo diario (`--si-toca`, después de las 3:00 hora de Cancún) en cada corrida del cron. No hace falta otro cron. Los archivos quedan en `$APP/shared/storage/app/private/respaldos` (permisos 600) y se conservan 14 días. Ver `docs/tecnico/configuracion.md`. En la misma corrida, `plataforma:traslado --si-toca` termina los paquetes de traslado y exportaciones que quedaron a medias y arranca el semanal si está encendido (ver `docs/tecnico/traslado.md`). Después de las 4:00, `plataforma:revisar-coherencia --si-toca --reparar` (una vez al día, en proceso) corrige desde su fuente los datos que la base guarda dos veces y lo anota en la auditoría (ver `docs/tecnico/integridad-bd.md`).

## Lista de salida a Producción

Antes de autorizar la primera versión de Producción (y al cambiar de hospedaje), revisa en Producción:

1. **Correo de la plataforma configurado y probado** (Configuración → Plataforma → Correo saliente → «Enviar correo de prueba»). Sin él, «Olvidé mi contraseña» solo ofrece «Pedir a mi administrador» y no salen avisos por correo (C-36).
2. **Dos Super Administradores activos**, cada uno con su verificación en dos pasos y sus códigos de respaldo guardados fuera de la plataforma. Inicio avisa en rojo mientras haya uno solo (C-36, DD-21). El hospedaje no tiene terminal: la recuperación del Super Administrador depende de esto (ver [dos-pasos.md](dos-pasos.md)).
3. **Tareas en proceso** comprobadas (privacidad, plazos, escalamientos): la Bitácora de auditoría muestra `privacidad.conservacion_aplicada` o la tarjeta de Privacidad muestra la «Última revisión» del día.
4. **Respaldo descargado** desde Configuración → Respaldos y guardado también fuera del servidor. Sin terminal, el respaldo se copia con el **administrador de archivos de cPanel** (carpeta `shared/storage/app/private/respaldos`) y se restaura importando el `.sql.gz` con phpMyAdmin.
5. **Aviso de privacidad** de cada empresa completado por su abogado y publicado en Configuración → Privacidad (los borradores de los textos legales están en [privacidad.md](privacidad.md)).
6. Prueba en un **teléfono real** (app y navegador).

## QA: datos demo al día

En QA, cada versión nueva corre `plataforma:demo` una vez, con la contraseña de `qa_inicial` (si ya no está, las cuentas nuevas reciben la contraseña de admin.demo; cada parte del demo se completa por separado y el resultado, con los usuarios nuevos, queda en `despliegue_qa_estado.txt`); la marca de la versión ya completada queda en `$APP/.demo_completado`. El comando:

- crea solo las cuentas demo que faltan (por ejemplo `rh.demo`) y no cambia el correo ni la contraseña de las que ya existen;
- llena los datos de ejemplo de los módulos nuevos (cada uno solo si su tabla está vacía).

Como `desplegar.sh` se actualiza a sí mismo al final de una instalación, este paso empieza a correr a partir de la siguiente revisión del cron, unos 5 minutos después.

## Candados de seguridad del despliegue

Revisados en la auditoría del 2026-10-06 (`docs/seguridad/auditoria-2026-10-06-infraestructura.md`); cada uno tiene su prueba en `tests/Feature/SeguridadAuditoria/DesplegarScriptTest.php`, que corre el script real contra un GitHub simulado.

- **Huella obligatoria.** Si GitHub no da la huella `sha256` del paquete, no se instala. El número del paquete debe ser numérico (forma la carpeta `releases/`).
- **Producción no cambia de contenido.** La primera vez que se descarga una versión (`v1.2.3`) su huella queda en `$APP/.huellas`; si después el paquete de esa misma versión aparece con otra huella (alguien lo reemplazó en GitHub), no se instala. Además, `desplegar_version.txt` acepta la huella autorizada en la misma línea:

  ```
  v1.2.3 sha256:0f3c…(64 caracteres)
  ```

  La huella está en GitHub → *Releases* → `paquete.tar.gz` (o en el registro del flujo *Paquete*). El flujo ya no reemplaza el paquete de una versión publicada.
- **Configuración de Producción.** Antes de instalar se revisa el `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` con `https://`.
- **Token.** Se pasa a `curl` por la entrada estándar (`-K -`), no como argumento, para que no aparezca en la lista de procesos.
- **Candado de corrida.** Guarda el número de proceso; solo se retira si ese proceso ya terminó (o tras 3 horas).
- **Regreso automático.** Al regresar a la versión anterior también se restauran sus archivos públicos (`.htaccess`, css, js).
- **`.env`.** Se escribe con permisos `600` desde el inicio y se conservan solo los 3 respaldos más recientes (`.env.respaldo.*`).

## Versión de PHP (8.4 y 8.5)

La plataforma funciona en **PHP 8.4.1 o mayor**; está probada en **8.4 y 8.5**. La CI corre la suite completa (incluido migrar → revertir → migrar en MariaDB) en las dos versiones, y falla si el código propio produce avisos de deprecación (`.github/scripts/revisar-deprecaciones.php`; los de librerías salen como aviso con el nombre del paquete). El paquete y `composer.lock` se arman con la versión más baja en uso (8.4) para que nunca entre una librería que no corra en Producción; se pasan a 8.5 cuando QA y Producción ya estén en 8.5.

> **Por qué cambiar.** El hospedaje recomienda pasar a 8.5 por la falla «PHP2Shell» (CVE-2026-109618 y CVE-2026-109619, según su aviso). Están corregidas en **8.5.12, 8.4.27, 8.3.36 y 8.2.35**: lo importante es estar en una de esas versiones o una posterior de su rama. Confirma el número exacto en <https://www.php.net/ChangeLog-8.php>. Seguir en 8.4 con 8.4.27+ también queda corregido; 8.5 da más tiempo de soporte.

### Qué PHP usa cada parte

| Parte | Dónde se elige | Nota |
|---|---|---|
| Sitio web (lo que ven los usuarios) | cPanel → **MultiPHP Manager** (por dominio) o **Select PHP Version** (CloudLinux; suele aplicar a toda la cuenta) | MultiPHP guarda la versión en un bloque `# php -- BEGIN cPanel-generated handler` del `.htaccess` del subdominio. `desplegar.sh` **conserva ese bloque** en cada instalación (antes lo borraba al copiar el `.htaccess` de la versión). |
| Cron (`desplegar.sh`: migraciones, cachés, tareas programadas) | El script la busca solo | 1) `~/.php_cli_path` si existe y es 8.4.1+; 2) la misma versión que el bloque de MultiPHP del sitio (`ea-php85` → `/opt/alt/php85/usr/bin/php` o `/opt/cpanel/ea-php85/root/usr/bin/php`); 3) 8.4, luego 8.5, luego `/usr/local/bin/php`. La versión usada sale en `despliegue_<amb>_estado.txt` («PHP del cron») y en `despliegue.log` al instalar. |

Si existe `~/.php_cli_path`, **fija** la versión del cron: al cambiar de versión, edita la ruta o borra el archivo para que el script la detecte.

### Pasos para el dueño: primero QA, luego Producción

1. **Antes de empezar:** confirma que el PR que habilitó 8.5 está en `develop` (QA) y que la CI salió verde en «Pruebas (PHP 8.5 + MariaDB 11.4)». Para Producción, que ya viaja en una versión `vX.Y.Z` instalada.
2. **QA a 8.5.** En cPanel → **MultiPHP Manager**, marca solo `qa.vdcp.com.mx` y elige **PHP 8.5** (en Neubox puede aparecer como `ea-php85` o `alt-php85`) → *Apply*. Si solo existe **Select PHP Version** (CloudLinux), ten en cuenta que cambia toda la cuenta (QA y Producción a la vez): hazlo en un horario tranquilo y sigue igual los pasos de verificación en ambos sitios.
3. **Extensiones y ajustes de 8.5.** Las extensiones y los valores de `php.ini` se guardan **por versión**: en **Select PHP Version → Extensions** (o *MultiPHP INI Editor*) revisa que en 8.5 estén activas `bcmath, ctype, curl, dom, exif, fileinfo, gd, intl, mbstring, pdo_mysql, sodium, xml, zip` (y `opcache`), y copia los valores que tenías en 8.4 (`memory_limit`, `upload_max_filesize`, `post_max_size`, `max_execution_time`).
4. **Verificar QA** (10 minutos):
   - `https://qa.vdcp.com.mx/up` responde «OK» y puedes iniciar sesión con un usuario demo; recorre 3 o 4 pantallas (Accesos, Pases de salida, Reservas, una impresión con QR) y sube una foto.
   - Espera la siguiente corrida del cron (5 min) y abre `despliegue_qa_estado.txt` en el Administrador de archivos: debe decir `OK` y «PHP del cron: 8.5.x». Si dice 8.4, edita o borra `~/.php_cli_path`.
   - En `~/apps/plataforma-qa/shared/storage/logs/` no deben aparecer errores nuevos.
   - Deja QA así uno o dos días de uso normal.
5. **Producción a 8.5.** Igual que el paso 2, ahora con `app.vdcp.com.mx`, y repite los pasos 3 y 4 (`despliegue_prod_estado.txt`). Hazlo fuera del horario de mayor uso.

### Regresar a 8.4 si algo falla

1. cPanel → **MultiPHP Manager** (o **Select PHP Version**) → elige de nuevo **PHP 8.4** para el sitio afectado → *Apply*. El cambio es inmediato; no hay que reinstalar nada: el mismo código y la misma base funcionan en 8.4 y 8.5.
2. Si escribiste `~/.php_cli_path` con la ruta de 8.5, regrésala a `/opt/alt/php84/usr/bin/php` (o bórrala).
3. Comprueba `/up`, inicia sesión y revisa en el siguiente `despliegue_<amb>_estado.txt` que diga «PHP del cron: 8.4.x».
4. Avisa qué pantalla falló (y la hora) para revisar `storage/logs/` y corregirlo antes de volver a intentar.

Con 8.4, asegúrate de estar en **8.4.27 o mayor** (corrección de PHP2Shell); si el hospedaje aún no la ofrece, pídela a soporte de Neubox.

## Velocidad

Para saber dónde se va el tiempo de una pantalla lenta (espera del hospedaje, arranque, base de datos o código), ver [velocidad.md](velocidad.md): el Super Administrador lo ve en F12 → Network → Timing y las visitas lentas quedan en `shared/storage/logs/lentas-AAAA-MM-DD.log`.
