# Respaldos y traslado a otro servidor · Exportar una empresa

Petición del dueño: «generar un exportado de todas las configuraciones de Empresas y sedes en caso de que tengamos que migrar a un nuevo servidor en el futuro y no perder datos de clientes». El respaldo diario (`App\Services\Respaldos\Respaldos`, ver `configuracion.md`) cubre solo la base; los **archivos** de `storage` no se respaldaban y no había un paquete por empresa.

| Pieza | Dónde | Quién |
|---|---|---|
| Pantalla «Respaldos y traslado a otro servidor» | `GET /configuracion/traslado` (`traslado.index`), enlace desde Configuración → Respaldos | Solo Super Administrador |
| Exportar datos de una empresa | `GET /empresas/{empresa}/exportaciones`, botón en la ficha de Empresas | Super Administrador (todas) o `empresas.exportar` con alcance de empresa (la suya) |
| Restauración | `php artisan plataforma:restaurar-traslado <zip>` | Consola del servidor nuevo |
| Tarea en proceso | `php artisan plataforma:traslado --si-toca` (cron de `desplegar.sh`) | — |
| Código | `app/Services/Traslado/*`, `TrasladoController`, `Console/Commands/{Traslado,RestaurarTraslado}.php`, `public/js/traslado.js` | — |

## Formato del paquete (`version_formato` 1)

ZIP estándar (se abre con cualquier descompresor; ZIP64 automático arriba de 4 GB o 65 535 entradas; nombres UTF-8):

```
manifest.json          formato, version_formato, tipo, generado_en (UTC), generado_por,
                       plataforma {version, ultima_migracion, migraciones, motor},
                       archivos [{ruta, bytes, sha256}], totales {archivos, bytes}, omitidos?
LEEME.txt              qué trae y pasos de restauración (texto neutral, sin marcas del hospedaje)
traslado:
  base/base.sql.gz     respaldo de Respaldos (mismo formato que el diario; importable)
  archivos/private/…   storage/app/private (sin respaldos/ ni traslados/)
  archivos/public/…    storage/app/public (logotipos, identidad)
  manifest.base        {ruta, motor, tablas {tabla: filas}, filas}
empresa:
  datos/<tabla>.json   un objeto por renglón, uno por línea
  hojas/<hoja>.csv     sedes, colaboradores, usuarios, padron_* y configuracion (UTF-8 con BOM)
  archivos/{private,public}/…   solo los que mencionan los renglones de la empresa
  manifest.empresa, manifest.tablas {tabla: filas}, manifest.no_incluye
```

- **No viajan:** `storage/framework` (caché, sesiones, vistas), `storage/logs`, `storage/app/private/respaldos` y `…/traslados`, `.gitignore`, `*.parcial`, enlaces simbólicos. En la base, `sessions`, `cache`, `cache_locks` y `password_reset_tokens` van sin filas (regla de Respaldos).
- **Conteos:** `Respaldos::ultimosConteos()` devuelve las filas escritas por tabla en el último volcado (cambio mínimo en Respaldos); así el conteo del manifest es exactamente lo que trae el `.sql.gz`.
- El `.sql.gz` del paquete se crea con motivo `traslado` y se borra de `respaldos/` al copiarlo (no se duplica).
- **Coherencia:** la base es la foto del momento en que empezó el paquete; los archivos se copian después. Lo capturado entre ambos momentos irá en el siguiente.

## Generación por partes (hospedaje compartido)

- Cada paquete es un **trabajo** con estado en `storage/app/private/traslados/trabajos/<id>.json` (id de 16 hex): lista de tareas (`base`, `listar`, `archivo`, `plan`, `tabla`, `archivos-empresa`, `csv`, `cierre`; `verificar` para las revisiones), índice, desplazamiento del ZIP y el índice central pendiente.
- `App\Services\Traslado\ZipPorPartes` escribe el ZIP **agregando al final** (ZipArchive reescribe el archivo completo en cada cierre). Archivos en *store* (fotos, PDF y `.sql.gz` ya vienen comprimidos); JSON/CSV con *deflate* por bloques. CRC32 y SHA-256 en una sola lectura; el CRC (y el tamaño comprimido) se corrigen en el encabezado local al terminar la entrada. El índice central se escribe una vez, en `cierre`.
- `Paquetes::avanzar($id, $segundos, $web)`: candado `flock` por trabajo (pantalla y cron no se pisan); al menos una tarea por llamada; guarda el estado al final. Si una parte se corta (el hospedaje mata la petición), la marca `<id>.json.paso` queda: la siguiente llamada anota el intento **de inmediato**, recorta el `.parcial` al último desplazamiento confirmado y repite. Desde la pantalla, después de `INTENTOS_WEB` (2) intentos, la deja a la tarea programada (CLI sin límite de tiempo) y muestra el aviso.
- Pantalla: cada petición avanza `SEGUNDOS_WEB` (15 s; si `max_execution_time` es menor que lo necesario, intenta ampliarlo con `set_time_limit`, nunca en consola). `public/js/traslado.js` pide la siguiente parte por `POST /configuracion/traslado/trabajos/{id}` (JSON) y recarga al terminar; sin JavaScript, botón «Continuar».
- `plataforma:traslado --si-toca` (cron de 5 min, en proceso, sin `exec`): termina los trabajos pendientes (120 s por corrida) y arranca el **semanal** si está encendido. `--generar` arma uno completo desde consola.
- **Disco:** antes de empezar se estima (archivos + último respaldo) y se exige ese tamaño + 50 MB libres (`disk_free_space`); si no, error claro y nada se escribe.
- **Retención:** 3 paquetes de traslado y 3 exportaciones por empresa (`Paquetes::CONSERVAR`); trabajos terminados o fallidos se limpian a los 7 días; un trabajo que falla borra su `.parcial` y temporales.

### Política de archivos en el respaldo automático

Los archivos **no** se agregan al respaldo diario: duplicarían todo `storage` cada día (14 días de retención) y en hospedaje compartido la cuota de disco no se puede consultar (`disk_free_space` ve el disco completo). En su lugar: **paquete de traslado semanal opcional** (interruptor en la pantalla, clave `traslado` de `configuracion_plataforma`, **apagado por omisión**), con el chequeo de disco y la retención de 3. La base sigue diaria. Recomendación al dueño: encenderlo y descargar uno al mes fuera del servidor.

## Verificar sin restaurar

`VerificadorPaquete`: abre con ZipArchive, valida `manifest.json` (formato, versión de formato no mayor a la instalada), nombres seguros (`ZipPorPartes::nombreSeguro`: sin `..`, `/` inicial, `\` ni NUL), que existan las piezas obligatorias (`base/base.sql.gz` o `datos/empresas.json`) y recalcula el SHA-256 y tamaño de cada entrada declarada. Entradas no declaradas = aviso (no se restauran). Por partes desde la pantalla (lista o subida; lo subido se guarda en `traslados/verificar/` y se borra al terminar) y de una vez en consola.

## Restauración (`plataforma:restaurar-traslado`)

```
php artisan plataforma:restaurar-traslado paquete.zip [--solo-verificar] [--importar-base] [--forzar] [--sobrescribir] [--conexion=]
```

1. Verificación completa (si falla, no toca nada). Rechaza paquetes de empresa. Avisa si la última migración del paquete no existe en el código instalado (paquete de versión más nueva).
2. Archivos → `storage/app/{private,public}`: iguales se omiten; distintos se reportan como conflicto y **no** se pisan sin `--sobrescribir`. Solo entradas del manifest con nombre seguro.
3. Extrae `base.sql.gz` a `traslados/restaurar/` y explica cómo importarlo (herramienta del hospedaje o `--importar-base`).
4. `--importar-base`: solo si el motor coincide (mysql/mariadb o sqlite) y la base de destino **no tiene ninguna tabla**; pide confirmación (`--forzar` para scripts). `ImportadorSql` lee por bloques y separa sentencias respetando textos (en MySQL/MariaDB escapes con `\`, en SQLite comillas duplicadas; comentarios `--` al inicio de sentencia). Después compara filas por tabla con el manifest y borra el `.sql.gz` extraído.
5. Bitácora (`configuracion.traslado_restaurado`) en la base de destino, si existe la tabla.

### Columnas generadas

El esquema tiene dos columnas generadas (`storedAs`), que sostienen reglas de unicidad: `prestamos_llaves.llave_en_uso` (un préstamo vigente por llave) y `equipos_responsiva.equipo_en_campo` (un equipo en un solo resguardo abierto).

- **Volcado** (`Respaldos::volcarFilas`, mismo código para respaldos diarios/manuales y para el paquete): solo selecciona e inserta las columnas con datos (`Respaldos::columnasConDatos`, que descarta las que traen `generation` en `Schema::getColumns`). El `CREATE TABLE` sí declara la columna generada, así que la base de destino la recalcula. MySQL/MariaDB y SQLite rechazan un valor explícito en una columna generada; antes el volcado las incluía y la importación se detenía.
- **SQLite**: el volcado escribe además los índices (`sqlite_master` tipo `index`) después de los datos; sin ellos la base restaurada perdía los únicos.
- **Respaldos anteriores** que sí traen la columna: `ImportadorSql` detecta en el `CREATE TABLE` una columna generada (`GENERATED ALWAYS` / `… STORED|VIRTUAL|PERSISTENT`), consulta la tabla recién creada y quita esa columna y su valor de cada renglón de sus `INSERT` (respetando textos con comas y paréntesis).
- Si se agrega otra columna generada, no hay que tocar el volcado; la prueba `test_las_columnas_generadas_no_se_vuelcan_y_la_base_restaurada_las_recalcula` lista las del esquema y avisa para revisarla. La CI repite el ciclo respaldo → importación en MariaDB (`.github/scripts/respaldo-mariadb.php`).

Prueba de punta a punta: `tests/Feature/Direccion/TrasladoTest::test_genera_el_paquete_y_lo_restaura_en_base_y_storage_vacios` (genera con datos y archivos de ejemplo → restaura en otra base SQLite vacía y otro storage → mismos conteos y archivos, incluido un texto con `;`, saltos, comillas, `\` y `--`).

## Exportar una empresa (portabilidad)

- Tablas (se leen del esquema; un módulo nuevo entra solo): `empresas` (su renglón), toda tabla con `empresa_id` (= la empresa) y las tablas **sin** `empresa_id` que cuelgan por llave foránea de una tabla de la empresa (`usuario_roles`, `rol_permisos`, `sede_turno`, `colaborador_sede`…; hasta dos niveles), filtradas por subconsulta. Nunca una consulta sin filtro de empresa.
- **Excluido:** `migrations`, `sessions`, `cache*`, `password_reset_tokens`, `jobs`, `job_batches`, `failed_jobs`, `enlaces_kiosco` (su código equivale a contraseña) y toda columna `password`, `remember_token`, `*_token`, `token_hash`, `secret*`, `recovery_codes`.
- **Archivos:** los que mencionan los renglones (ruta directa en `storage/app/private`, `storage/<ruta>` en public, o dentro de un JSON). Nunca de `respaldos/` ni `traslados/`.
- **CSV:** columnas sin las sensibles; celdas que empiezan con `= + - @` se anteponen con `'` (inyección de fórmulas en Excel).
- **Permiso:** acción nueva `empresas.exportar` (migración `2026_11_02_000400_permiso_exportar_empresa`: se otorga con alcance de empresa a los roles que hoy editan la empresa con alcance de empresa; `down()` quita la acción y sus permisos). En el catálogo, `empresas` gana `exportar`; la plantilla **Director** no la recibe (regla explícita en `RolesPlantillaSeeder`), el Administrador sí.

### Importar una empresa en otra instalación (fuera de alcance; diseño propuesto)

1. Comando `plataforma:importar-empresa <zip>` que exige el mismo `ultima_migracion` (o aplica las migraciones pendientes antes).
2. Orden topológico de tablas por llaves foráneas (el mismo plan de `ExportacionEmpresa::tablas()`); para cada renglón, `INSERT` sin `id` y un mapa `tabla:id_viejo → id_nuevo` en una tabla temporal.
3. Reescritura de llaves foráneas con el mapa (columnas conocidas por `Schema::getForeignKeys`) y de las referencias **sin** llave foránea declarada (`auditable_type/auditable_id`, `creado_por`/`actualizado_por` → usuarios, JSON con ids): lista explícita por tabla, revisada módulo a módulo.
4. Usuarios: mismo `username`/correo puede chocar con la instalación de destino → prefijo o rechazo; sin contraseña: se marcan para «Enviar enlace de bienvenida».
5. Catálogos globales (módulos, acciones, rubros, planes) se emparejan por `clave`, nunca por id.
6. Archivos: se copian con ruta nueva si la ruta contiene el id de la empresa (`firmas/{empresa}/…`) y se reescriben las columnas que los mencionan.
7. Todo en una transacción por bloques con «simulacro» (`--simular`) que reporta conflictos antes de escribir.

## Seguridad

- Todo en `storage/app/private/traslados` (0700, archivos 0600); nunca en la carpeta pública; `/storage/traslados/*` responde 404.
- Descarga: enlace **temporal firmado** (30 min, `URL::temporarySignedRoute`) **y** sesión del usuario autorizado. La firma se revisa en el controlador **después** de la empresa (lo de otra empresa sigue respondiendo 404, AZ-03). `Cache-Control: no-store, private`.
- Nombres de archivo validados contra el patrón del servicio (`traslado_<amb>_<fecha>.zip`, `empresa_<id>_<fecha>.zip`).
- Avisos de datos personales en ambas pantallas y en el LEEME.

## Bitácora de auditoría

| Evento | Cuándo |
|---|---|
| `configuracion.traslado_generado` | Paquete terminado (usuario o tarea automática sin usuario) |
| `configuracion.traslado_descargado` / `traslado_borrado` | Descarga / borrado |
| `configuracion.traslado_verificado` | Revisión terminada (pantalla o `--solo-verificar`/fallo en consola) |
| `configuracion.traslado_restaurado` | Restauración en el servidor nuevo |
| `configuracion.traslado_ajustes` | Encender/apagar el semanal |
| `empresas.exportada` / `exportacion_descargada` / `exportacion_borrada` | Exportación de una empresa |

## Criterios de experiencia de uso

- **5 (siguiente paso):** «Paquete listo» y «Exportación lista» traen el botón **Descargar ahora**.
- **7 (avanzado):** la restauración vive en consola y en el manual; la pantalla muestra solo generar, descargar, verificar y el semanal.
- **3 (lo que espera):** el avance muestra porcentaje, paso y tamaño; si la pantalla no puede, la tarea automática lo termina (no queda nada esperando a una persona).
- **Excepción al punto 4 (verbos), aprobada en la petición del dueño:** «Generar paquete de traslado» y «Verificar paquete» son los nombres que pidió; «Descargar», «Borrar» y «Continuar» (respaldo sin JavaScript) siguen el uso ya existente en Respaldos.

## Límites conocidos

- La generación de la base (`Respaldos::crear`) es una sola parte: en bases muy grandes puede pasarse del tiempo de la página; entonces la termina la tarea programada.
- La subida para verificar está limitada por `upload_max_filesize`/`post_max_size`; para paquetes grandes, `--solo-verificar` en consola.
- `--importar-base` solo entre el mismo motor y sobre base vacía; entre motores distintos, importar con la herramienta del hospedaje.
- El ZIP64 se probó por formato (lectores estándar); no hay prueba automática con > 4 GB.

## Pruebas

`tests/Feature/Direccion/TrasladoTest.php` (punta a punta, por partes y reanudación, la parte que se corta pasa a la tarea, JSON de avance, permisos y enlace firmado, retención, verificar subido/alterado/ajeno, nombres peligrosos, semanal, exportación de empresa sin contraseñas ni datos ajenos, permiso del Administrador/Director, migración, CSV) columnas generadas (volcado → restauración y respaldo con el formato anterior) y `ImportadorSqlTest.php` (separación de sentencias con cortes de lectura de 1 byte en adelante). En MariaDB: `.github/scripts/respaldo-mariadb.php` en la CI.
