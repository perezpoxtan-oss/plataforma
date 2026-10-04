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
