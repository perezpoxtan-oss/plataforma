# Importar desde Excel (importación masiva)

Petición del dueño (prioridad alta antes de Producción): cargar desde archivo **Colaboradores**, **Padrón de personas**, **Empresas externas (proveedores)**, **Padrón vehicular** y **Zonas y edificios** (zona → piso → habitación), con plantilla, vista previa sin guardar y guardado por lotes que reutiliza las mismas reglas que el alta manual.

- Controlador: `app/Http/Controllers/ImportacionController.php`
- Servicio: `app/Services/Importacion/AdministradorImportaciones.php` (subir, vista previa, guardar, resultado)
- Un importador por módulo: `app/Services/Importacion/Importador{Colaboradores,Personas,Proveedores,Vehiculos,Zonas}.php` (base `Importador`, columnas `Columna`)
- Lector de archivos: `app/Support/Importacion/LectorArchivo.php` (CSV y XLSX, sin dependencias de Composer)
- Modelo: `app/Models/Importacion.php` (tabla `importaciones`, migración `2026_11_03_000100_crear_importaciones`)
- Permiso: acción `importar` (migración `2026_11_03_000200_accion_importar_en_padrones`, `CatalogoSeeder`, `RolesPlantillaSeeder::importarComoCrear`)
- Vistas: `resources/views/importaciones/{create,show,_pasos,_boton}.blade.php`; botón **Importar** en las cinco listas
- Pruebas: `tests/Feature/Organizacion/ImportacionTest.php`

## Rutas

| Ruta | Qué hace | Permiso |
|---|---|---|
| `GET /importar/{tipo}` | Paso 1: plantilla, qué va en cada columna, subir; archivos anteriores (10) | `modulo.importar` |
| `GET /importar/{tipo}/plantilla` | Plantilla CSV (UTF-8 con BOM): encabezados en español + fila de ejemplo (la sede de ejemplo es una real) | `modulo.importar` |
| `POST /importar/{tipo}` | Sube, guarda el original y arma la vista previa → redirige a la importación | `modulo.importar` |
| `GET /importaciones/{id}` | Vista previa (filtros `?ver=nuevas|existen|errores`, 200 filas por página) o resultado | `modulo.importar` |
| `POST /importaciones/{id}/guardar` | Guarda (`existentes=omitir|actualizar`) | `modulo.importar` (+ `crear`/`editar` por fila) |
| `GET /importaciones/{id}/original` | El archivo tal como se subió | `modulo.importar`; en Colaboradores además `colaboradores.datos_personales` |
| `GET /importaciones/{id}/resultado` | CSV: columnas del archivo + «resultado» + «detalle» | `modulo.importar` |

`{tipo}`: `colaboradores` (módulo `colaboradores`), `personas` (`visitantes`), `proveedores` (`proveedores`), `vehiculos` (`vehiculos`), `zonas` (`espacios`). Un tipo desconocido responde 404; una importación de otra empresa, 404 (el modelo lleva `PerteneceAEmpresa`); sin el permiso de su módulo, 403.

## Flujo

1. **Subir** (`AdministradorImportaciones::subir`): extensión `.csv`, `.txt` o `.xlsx`, hasta `MAX_KB` (5 MB). El original se guarda en el disco privado `local` (`storage/app/private/importaciones/{empresa}/{uuid}.{csv|xlsx}`, sin ruta pública: AZ-05). Se lee, se acomodan las columnas por encabezado y se valida: faltan columnas obligatorias, vacío, o más de `MAX_FILAS` (2,000) filas → error en el campo `archivo` y **se borra el archivo**; no queda importación.
2. **Vista previa** (`procesar(..., guardar: false)`): cada fila pasa por el **mismo servicio** que el alta manual dentro de `DB::beginTransaction()` … `DB::rollBack()`: nada se guarda en el módulo (ni su auditoría). Así se ven exactamente los errores que daría la pantalla, incluidas las reglas entre filas (la fila 5 ve lo que «creó» la 2). Se guarda en `importaciones.filas` el estado de cada renglón: `nueva`, `existe` (con `actualizable` o `no_actualiza` = por qué no se actualizaría) o `error` (con `errores[columna]` y `mensaje`). Los valores no se copian a la base: se vuelven a leer del archivo al mostrar.
3. **Guardar** (`aplicar`): marca la importación como `guardando` con un `UPDATE … WHERE estado = 'vista_previa'` (dos clics no guardan dos veces) y repite el proceso de verdad **por lotes de 200 filas en transacción** (`LOTE`); cada fila va en su propio punto de guardado (`DB::transaction` anidada), así una fila con error se deshace sola y no tumba el lote. Estados finales: `creada`, `actualizada`, `omitida`, `error`. Se guarda `resultado`, `resumen`, `existentes` y `aplicada_en`, y se registra **un evento de auditoría por importación** (`{modulo}.importado`, sujeto `Importacion`, con archivo, filas, creadas, actualizadas, omitidas, con error y la opción elegida). Cada alta o cambio deja además su propio evento, como en la pantalla.
4. **Resultado**: pantalla con conteos y botones **Ir a …**, **Descargar resultado**, **Descargar archivo original** (criterio 5: el aviso verde ofrece el siguiente paso).

## Reglas por fila

- **Mismas reglas que el alta manual** (no se duplican): cada importador solo traduce textos a lo que recibe el servicio y lo llama — `AdministradorColaboradores::crear/actualizar`, `AdministradorPersonas`, `AdministradorProveedores`, `AdministradorVehiculos`, `AdministradorEspacios::crear`. Se arma un `Request` con los datos de la fila (`Importador::peticion`).
- **Traducciones**: sede por nombre o código (sin acentos ni mayúsculas); departamento, puesto y turno por nombre; listas (tipo de persona, identificación, categoría, tipo de vehículo…) por texto o clave, con sinónimos («Camioneta», «Moto», «IFE»…); fecha `dd/mm/aaaa`, `aaaa-mm-dd` o la serie de Excel; estado de nacimiento sin acentos. Lo que no existe es error de la celda («No existe la sede «Sede Luna» en esta empresa…»); **nunca se crean sedes, departamentos, puestos, turnos ni empresas externas al vuelo**.
- **Todos los errores de la fila a la vez**: si al traducir ya hubo errores, la fila igual pasa por el servicio dentro de un punto de guardado que se deshace (`Importador::guardarOReportar`), y se juntan los de ambos.
- **Ya existe** (clave de cada módulo): número de empleado; folio de identificación (o nombre completo, error si hay dos personas con ese nombre); razón social (`buscarPorNombre`); placas normalizadas; en zonas, la ruta completa sede › zona › piso › habitación. **Repetidos en el archivo**: la segunda fila con la misma clave es error («Repetido en el archivo: es el mismo vehículo de la fila 2.»).
- **Actualizar** (opción al guardar; por omisión «Dejarlos como están»): se parte de los datos actuales y se ponen encima solo las celdas con valor (`Importador::encima`): **una celda vacía no borra**. En Colaboradores el turno y los datos personales solo se tocan si vienen. En Empresas externas las sedes donde opera no cambian (su diálogo). Zonas no tiene nada que actualizar: lo existente se omite.
- **Permisos**: entrar = `importar`; filas nuevas = `crear` (si no, error «No tienes permiso para dar de alta…»); actualizar = `editar` y que el registro esté en su alcance (`Importador::editable`: sedes en Colaboradores, «solo los propios» en Personas y Vehículos, exclusivo de sus sedes en Proveedores); si no, se omite con el motivo. El alcance de sede de `crear` lo aplican los servicios (sede de Colaboradores; sedes de Proveedores; en Zonas, `sedesPermitidas(espacios.crear)`).
- **Datos personales** (Colaboradores): sin `colaboradores.datos_personales` la plantilla no trae CURP/RFC/NSS…, esas columnas se ignoran al guardar (el servicio tampoco las acepta), no se muestran en la vista previa, el resultado sale enmascarado (`Colaborador::enmascarar`) y no se descarga el original.
- **Multiempresa**: todo corre en `Tenant::conEmpresa()`; `Importacion` lleva `empresa_id` y su filtro.
- **Suscripción**: en solo consulta el middleware `SoloConsulta` bloquea subir y guardar (son POST); plantillas, vista previa y resultados se pueden ver. Como al importar no se crean sedes ni usuarios, los límites del plan (`Limites`) no se pueden rebasar por aquí.

## Lector de archivos (límites)

- **CSV**: UTF-8 (con o sin BOM) o Windows-1252 (lo que guarda Excel en español como «CSV delimitado por comas»; se detecta con `mb_check_encoding` y se convierte); separador `,`, `;` o tabulador, el que más aparece en el renglón 1 fuera de comillas; saltos `\r\n`, `\n` o `\r`.
- **XLSX** (lector propio, `ZipArchive` + `SimpleXML`, sin red `LIBXML_NONET`): solo la **primera hoja** (por `workbook.xml` y sus relaciones); textos compartidos y en línea; números sin notación científica ni «.0» (un teléfono de 10 dígitos queda igual); VERDADERO/FALSO; fechas cuando el estilo de la celda es de fecha (formatos de fábrica 14–22, 27–36, 45–47, 50–58 o uno propio con d/y) → `AAAA-MM-DD`; fórmulas = último valor guardado por Excel; errores (`#N/A`) = vacío. Se ignoran celdas combinadas, otras hojas, imágenes, comentarios y columnas más allá de la 200. Tope de 40 MB descomprimidos y 2,000 entradas en el zip (contra archivos «bomba»). XLS antiguo (97-2003) **no**: hay que guardarlo como .xlsx o CSV.
- Renglón 1 = encabezados (en cualquier orden; se comparan sin acentos, mayúsculas, espacios ni «*», y con sinónimos por columna); columnas desconocidas se ignoran y se avisan en la vista previa. Renglones vacíos no cuentan. Cada celda se recorta a 500 caracteres.
- Sin `exec` ni `proc_open`: todo corre en la misma petición web (`set_time_limit(600)` donde el hospedaje lo permite). 2,000 filas tardan unos segundos en la vista previa y otros tantos al guardar.

## Criterios de experiencia de uso

1. Nada queda esperando a nadie: la importación es inmediata (no aplica campana ni Mis pendientes).
2. Un dato se captura una vez: el archivo evita recapturar; lo que ya existe se reconoce y se omite o actualiza.
3. Sin esperas (no aplica).
4. Verbos: el paso final es **Guardar**. **Importar** (botón de las listas), **Revisar archivo**, **Descargar plantilla / resultado / archivo original**, **Ya terminé** y **Mostrar en Inicio / Ocultar de Inicio** son excepciones aprobadas con la petición del dueño (descargas y navegación, no acciones sobre registros).
5. El aviso verde ofrece **Ir a …**, **Descargar resultado** e **Importar otro archivo** si hubo errores.
6. No aplica.
7. «Qué va en cada columna» va plegado; la pantalla muestra lo común (descargar, subir, revisar).
8. Solo las columnas con * son obligatorias (las mismas del alta corta); lo demás se completa después en la ficha.

## Agregar otro módulo

1. Crear `ImportadorX extends Importador` (columnas, clave, existente, crear/actualizar llamando al servicio del módulo, editable).
2. Registrarlo en `AdministradorImportaciones::TIPOS` y en `plural()`.
3. Agregar `importar` a su módulo en `CatalogoSeeder` y una migración como `2026_11_03_000200` para los roles existentes.
4. Botón `@include('importaciones._boton', ['tipo' => …, 'modulo' => …])` en su lista, sección en su página del manual y pruebas.
