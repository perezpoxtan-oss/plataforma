# Importar estructura

Pedido del dueño: «desde Super Administrador poder importar las sedes, con sus zonas, habitaciones y áreas dentro de las habitaciones, con un Excel o algo similar, para no capturar todo a mano».

Es un tipo más de **Importar desde Excel** ([importacion.md](importacion.md)): mismo flujo plantilla → subir → vista previa renglón por renglón → **Guardar** → resultado, mismas rutas y mismo modelo `Importacion`. Lo propio de este tipo: crea **sedes**, guarda **todo o nada**, admite **5,000 renglones**, la plantilla es **.xlsx con hoja de instrucciones** y tiene **exportar** en el mismo formato.

## Piezas

| Pieza | Qué hace |
|---|---|
| `App\Services\Importacion\ImportadorEstructura` | Columnas por giro, validación del renglón, reutilizar o crear sede → zona → piso → habitación → área y tipos, exportar, ejemplos ficticios |
| `App\Support\Importacion\EscritorXlsx` | Libro .xlsx propio con `ZipArchive` (sin dependencias): varias hojas, textos en línea (un «0101» no se vuelve número), encabezado fijo y en negritas |
| `App\Services\Importacion\GuardadoCancelado` | Todo o nada: corta la transacción y devuelve los resultados por renglón |
| `Importador` (base) | Ganchos nuevos con valor por omisión (los padrones no cambian): `maxFilas()`, `todoONada()`, `plantillaXlsx()`, `exportable()`, `puedeCrear()`, `detalleCreado()`, `resumenExtra()` |
| `AdministradorImportaciones` | Tipo `estructura`; tope por importador; `procesar()` con una sola transacción si `todoONada()`; `aplicar()` rechaza si la vista previa tiene errores y, si una fila falla al guardar, regresa a vista previa con los errores y no deja nada |
| `ImportacionController::plantilla()/exportar()/libro()` | Plantilla y exportar .xlsx (hojas «Estructura» e «Instrucciones»); sin `ZipArchive`, CSV UTF-8 con BOM |
| Vistas | `importaciones/create` (selector «Empresa de trabajo», **Descargar lo que ya hay**), `importaciones/show` («Guarda la estructura» todo o nada, «Se creó: …»), botón **Importar estructura** en `administracion/sedes/index` |
| Migración `2026_11_23_000100_accion_importar_en_sedes` | Permiso `sedes.importar` |

## Rutas

| Ruta | Qué hace | Permiso | Límite |
|---|---|---|---|
| `GET /importar/estructura` | Pantalla; Super Administrador sin empresa de trabajo → selector | `sedes.importar` | — |
| `GET /importar/estructura/plantilla` | Plantilla .xlsx con ejemplos ficticios del giro | `sedes.importar` | — |
| `GET /importar/estructura/exportar` | La estructura activa en el formato de la plantilla | `sedes.importar` + `espacios.ver` | 10/min (`importaciones.exportar`) |
| `POST /importar/estructura` | Subir y armar la vista previa | `sedes.importar` | 10/min (`importaciones.store`) |
| `POST /importaciones/{id}/guardar` | Guardar (todo o nada) | `sedes.importar` | 10/min (`importaciones.aplicar`) |

`/importar/{otro}/exportar` responde 404. Los límites `importaciones.store` y `importaciones.aplicar` aplican también a los padrones (antes no tenían límite).

## Modelo: no hay tablas nuevas

La estructura física ya es un árbol (`espacios`, ver [empresas-y-sedes.md](empresas-y-sedes.md) y la migración `2026_10_04_000700_crear_espacios`):

```
Sede → edificio (Zona o edificio) → area (Piso) → area_especifica (Habitación | Oficina | Vivienda | Casa) → subarea (Área de la habitación) → elemento
```

- El **«área dentro de la habitación» ya existe**: es el nivel `subarea` («Área» en pantalla, glosario C-39). No se inventó modelo.
- Las «zonas nivel 1, 2, 3» del pedido no existen como niveles libres: el árbol tiene dos niveles fijos arriba de la habitación (Zona o edificio, Piso), y la habitación puede colgar directo de la zona (`Espacio::PADRES`). La plantilla sigue ese modelo.
- `elemento` (cama, extintor) no se importa: queda en la pantalla.
- **Tipos de espacio**: `tipos_espacio` del sistema (`empresa_id` nulo) y de cada empresa. Se busca por nombre normalizado en ambos; si no existe se crea **propio de la empresa** con `AdministradorEspacios::crearTipo` (pide `espacios.crear`).
- **Área común** (condominio, fraccionamiento): `espacios.uso = area_comun` en el nivel habitación.
- La terminología sale de `rubros.terminologia.area_especifica` (`Etiquetas::para`).

## Columnas

`Sede *`, `Código de sede`, `Ciudad`, `Estado`, `Zona o edificio`, `Piso`, `{Habitación}`, `Tipo de {habitación}`, [`Área común` solo en giros residenciales], `Área de la {habitación}`, `Tipo de área`, `Código`. Los encabezados cambian con el giro (Oficina, Vivienda, Casa) y se aceptan los de cualquier giro como sinónimo, para que un archivo armado en otra empresa también se lea.

## Reglas

- **Reutilizar o crear**: cada nivel se busca entre los hijos de su padre por nombre normalizado (minúsculas, sin acentos, espacios colapsados; `ImportadorEstructura::normal`). La sede, por nombre normalizado o por código. Lo que existe se reutiliza aunque esté desactivado (y entonces, si hay que agregarle algo, el servicio responde «está desactivado: reactívalo»): **nunca reactiva, edita, borra ni da de baja**.
- **Idempotente**: un renglón cuyo camino completo ya existe sale «Ya existe» y se omite; el mismo archivo dos veces no crea nada (prueba `test_subir_el_mismo_archivo_dos_veces…`).
- **Sede nueva** con las reglas de `SedeController::store`: nombre ≤150, código `^[A-Z0-9-]+$` ≤10 y único en la empresa (si viene vacío se arma con el nombre: «HOTELCENTR», «HOTELCEN-2»), ciudad y estado obligatorios, `Limites::exigirSede` (cuenta las sedes que el mismo archivo ya creó, porque corre dentro de la transacción), evento `sedes.creada` con `importada: true`. Requiere `sedes.crear`.
- **Lugares** con `AdministradorEspacios::crear` (mismas reglas de la pantalla: nivel permitido, misma sede, padre activo, nombre libre) y su evento `espacios.creado`. Requiere `espacios.crear` en esa sede (`sedesPermitidas`).
- **Validación del renglón** (todos los errores a la vez, por celda): sede vacía; largos (sede 150, código de sede 10, ciudad y estado 100, lugares 100, tipos 60, código 20); niveles vacíos en medio (piso sin zona, habitación sin zona, área sin habitación, tipo sin su lugar, «Área común» sin habitación, código sin lugar); «Área común» que no es Sí/No; código de sede distinto al de la sede existente; renglones repetidos (mismo camino) → «Repetido en el archivo (igual a la fila N)».
- **Código** va al último lugar del renglón; **tipo** al lugar de su columna, solo cuando se crea.
- **Todo o nada**: la vista previa corre todo en una transacción que se deshace (como los padrones). Con errores, **Guardar** no aparece y el POST responde «No se guardó nada…». Al guardar todo va en **una** transacción; si una fila falla (algo cambió desde la vista previa) se deshace todo, la importación vuelve a `vista_previa` con los errores nuevos y se avisa.
- **Tenant**: todo corre en `Tenant::conEmpresa(empresa de trabajo)`; sedes, espacios, tipos propios e `importaciones` llevan ese `empresa_id`. Una sede con el mismo nombre en otra empresa no se toca.
- **Auditoría**: `sedes.creada` y `espacios.creado` por cada alta, `espacios.tipo_creado` por tipo nuevo y un `sedes.importado` por archivo con filas, creadas, omitidas, con error y el desglose `crea` (sedes, zonas, pisos, habitaciones, áreas, tipos). `creado_por` en cada registro.
- **Límites**: 5,000 renglones (`ImportadorEstructura::MAX_FILAS`), 5 MB, 10 subidas, 10 guardados y 10 exportaciones por minuto por cuenta.

## Rendimiento

Los hijos de cada lugar y los tipos se leen una vez por corrida (memoria del importador; si una fila se deshace, su memoria también). Medido en SQLite: 2,500 renglones (5 torres × 10 pisos × 50 habitaciones, la mitad con baño) ≈ 10 s la vista previa y ≈ 9 s al guardar. En el hospedaje (MySQL compartido) se espera 2–3 veces eso para 5,000 renglones; corre en la misma petición con `set_time_limit(600)` (sin `exec` ni tareas aparte).

## Permiso `sedes.importar` (decisión)

- Acción `importar` en el módulo **Sedes** (no un módulo «estructura» nuevo: la matriz no gana una fila sin pantalla propia; el botón vive en Sedes).
- El **Super Administrador** entra siempre (elige la empresa en «Empresa de trabajo»).
- La migración da `sedes.importar` a **los roles que hoy pueden crear sedes, con el mismo alcance**; de fábrica, el **Administrador** de la empresa (igual que `RolesPlantillaSeeder::importarComoCrear` para instalaciones nuevas). Se puede quitar en la Matriz de permisos.
- Entrar exige `sedes.importar`; crear sedes, `sedes.crear`; crear lugares y tipos, `espacios.crear` en la sede; exportar, además `espacios.ver`.
- `down()` quita la acción del módulo Sedes y sus permisos; la acción `importar` se queda (la usan los padrones).

## Criterios de experiencia de uso

1. Nada queda esperando a nadie (no aplica campana).
2. Un dato se captura una vez: el archivo reemplaza la captura a mano y lo que existe se reconoce; «Descargar lo que ya hay» permite completar sobre lo capturado.
3. Sin esperas (no aplica).
4. Verbo final **Guardar**; **Importar estructura**, **Revisar archivo**, **Descargar plantilla / lo que ya hay / resultado** son las excepciones ya aprobadas de Importar desde Excel (descargas y navegación).
5. El aviso verde ofrece **Ir a Zonas y edificios**, **Ir a Sedes** y **Descargar resultado**.
6. No aplica.
7. «Qué va en cada columna» plegado; las reglas finas en la hoja «Instrucciones».
8. Solo **Sede** es obligatoria; ciudad y estado solo para una sede nueva; lo demás se completa después en Sedes y Zonas y edificios.

## Pruebas

`tests/Feature/Organizacion/ImportarEstructuraTest.php`: plantilla .xlsx por giro (y que la plantilla se puede subir tal cual), pantalla y botón por rol, vista previa sin guardar, alta completa hotel/corporativo/condominio, idempotencia y nombres normalizados, errores con renglón, lugares desactivados y sedes de baja, tenant, permisos (sin crear sedes, sin crear lugares, alcance de sede, sin importar), límite de sedes del plan, 5,000 renglones y 5 MB, límite de peticiones, archivo grande, todo o nada al guardar y exportar → importar ida y vuelta. `ImportacionTest` sigue igual (los padrones no cambian).
