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

## Probar el script fuera del servidor

`PLATAFORMA_API` y `PLATAFORMA_URL` sustituyen a GitHub y al sitio, para ensayarlo con un servidor simulado. Así se probaron la primera instalación, la actualización, la versión defectuosa con regreso automático y la corrida sin cambios.

## Respaldos de la base

`desplegar.sh` respalda la base antes de cada migración (`plataforma:respaldar --motivo=antes-de-actualizar`) y hace el respaldo diario (`--si-toca`, después de las 3:00 hora de Cancún) en cada corrida del cron. No hace falta otro cron. Los archivos quedan en `$APP/shared/storage/app/private/respaldos` (permisos 600) y se conservan 14 días. Ver `docs/tecnico/configuracion.md`.

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
