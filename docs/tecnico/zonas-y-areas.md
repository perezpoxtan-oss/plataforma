# Zonas y áreas

Réplica de `modules/ubicaciones` de SEGCAT. Se conservan las mismas cuatro pantallas y el mismo flujo:
**Zonas / Edificios → Pisos → Habitaciones → Detalle** (áreas y elementos), y la pestaña **Secciones**.

## Qué se corrigió respecto a SEGCAT

| SEGCAT | Ahora |
|---|---|
| Cinco tablas casi iguales (`edificios`, `secciones`, `areas_especificas`, `habitacion_areas`, `habitacion_area_elementos`), cada una con su propio código de alta, edición y baja | Una sola tabla `espacios` con `nivel` y `padre_id`. Se escribe una sola vez la lógica |
| La tabla de pisos se llamaba `secciones` y chocaba con `catalogo_secciones_habitacion` | **Piso** es el nivel `area`; **Sección** es `grupos_espacio`. Ya no hay dos cosas con el mismo nombre |
| Desactivar una zona dejaba activos sus pisos y habitaciones | Desactivar arrastra todo lo que cuelga (por `ruta`). Reactivar algo no se permite si su padre sigue inactivo |
| Tipos de área y elemento creados al vuelo con texto libre, lo que generaba duplicados ("Baño", "baño ", "BAÑO") | Catálogo con tipos de sistema (`empresa_id` nulo) y tipos propios por empresa. Al agregar uno existente se reutiliza, sin importar mayúsculas |
| Catálogos de tipos sin empresa, compartidos entre clientes | Un tipo propio solo lo ve su empresa |
| Lote sin tope real (501) y relleno de ceros que no daba 1001 con prefijo 10 | Tope de 500 y relleno de al menos dos dígitos (prefijo 10 + 1..5 → 1001..1005). Duplicados, también dentro de la misma lista, se omiten y se reportan |
| Sin filtro por empresa en varias consultas; alcance por sede revisado a mano en cada acción | Scope de empresa automático y `Autorizador::sedesPermitidas()`. Un id de otra empresa o sede da 404 |
| Sin auditoría | Eventos `espacios.*` con antes y después |
| Nombres repetidos posibles en algunos niveles | Nombre único entre hermanos en todos los niveles (sin importar mayúsculas) |

## Modelo de datos (migración `2026_10_04_000700`)

- **`espacios`**: `empresa_id`, `sede_id`, `padre_id`, `nivel`, `tipo_espacio_id`, `grupo_espacio_id`, `nombre`, `codigo`, `ruta`, `profundidad`, `orden`, `activo` y auditoría.
  - `ruta` materializada, por ejemplo `/12/45/301/`: los descendientes se obtienen con `ruta LIKE '/12/%'`, sin recursión.
  - Niveles y de cuál puede colgar cada uno (`Espacio::PADRES`):

    | Nivel | Puede colgar de |
    |---|---|
    | `edificio` | raíz |
    | `area` (Piso) | edificio |
    | `area_especifica` (Habitación, Oficina…) | piso o edificio |
    | `subarea` (Área de la habitación) | área específica |
    | `elemento` | área, área específica o piso |
- **`tipos_espacio`**: `empresa_id` nulo para los tipos de sistema (`TiposEspacioSeeder`); con valor, tipos propios de la empresa.
- **`grupos_espacio`**: las Secciones de SEGCAT. Una sección por habitación, siempre de la misma sede.

## Reglas (`App\Services\Espacios\AdministradorEspacios`)

- Padre, tipo y sección deben ser de la misma empresa y sede. El tipo debe corresponder al nivel.
- No se agregan espacios dentro de uno inactivo.
- **Sin nombre**, en áreas y elementos, se usa el del tipo y se numera si ya existe: Cama, Cama 2, Cama 3. Equivale a la "etiqueta opcional" de SEGCAT.
- **`crearLote`**: crea habitaciones por rango o por lista y omite las que ya existen.
- **`copiarPisos`**: copia los pisos activos de otro edificio de la misma empresa.
- **`asignarGrupo`**: deja en la sección exactamente las habitaciones marcadas. Las de otra sede se ignoran.
- La etiqueta del nivel `area_especifica` sale de la terminología del rubro: Habitación, Oficina, Departamento, Casa (`App\Support\Espacios\Etiquetas`).

## Rutas y permisos

Rutas:
- `GET /espacios`, con `?pestana=secciones` para la pestaña de Secciones.
- `GET /espacios/{id}`: muestra pisos, habitaciones o detalle según el nivel. Un área o un elemento redirige a su habitación.
- `POST /espacios`, `PUT /espacios/{id}`, `PATCH /espacios/{id}/estado`.
- `POST /espacios/{id}/lote`, `POST /espacios/{id}/copiar-pisos`.
- `POST /espacios/tipos`, `POST /espacios/secciones`, `PUT /espacios/secciones/{id}` (asignar habitaciones).
- `GET /espacios/{id}/destinos` (JSON) y `PATCH /espacios/{id}/mover` («Mover a…»).
- `DELETE /espacios/{id}/eliminar-varias` («Eliminar definitivamente las marcadas» de los hijos de `{id}`).

Permisos: `espacios.ver`, `espacios.crear`, `espacios.editar` (también «Mover a…»), `espacios.eliminar` (dar de baja o reactivar) y `espacios.borrar` (eliminar definitivamente, uno o varios). Con alcance de sede, el usuario solo ve y toca las sedes asignadas.

## Interfaz

- **Plantillas:** vistas en `resources/views/organizacion/espacios/`, con partes compartidas: `migas`, `acciones` y `formulario`. El formulario sirve para alta y edición de cualquier nivel.
- **Colores:** `tema-rojo` en zonas y detalle, `tema-ambar` en pisos y habitaciones, como en SEGCAT.
- **JavaScript:** `data-abrir-dialogo` acepta `data-padre` y `data-padre-nombre`, para usar un solo diálogo "Agregar Elemento" en todas las áreas. El filtro genérico de fichas acepta `data-filtro-sede`.
- **Datos demo:** `plataforma:demo` crea Torre A con 2 pisos, 8 habitaciones, la sección "Vista al mar" y el detalle de la 101.

## Mover a… y eliminar varias (2026-10)

**Mover a…** (`AdministradorEspacios::mover`, botón en `partes/acciones` → `partes/mover`, diálogo único por página):

- Cualquier espacio con padre (las zonas / edificios no se mueven). El destino debe ser del nivel permitido por `Espacio::PADRES`, de la misma sede y empresa, activo, distinto de su lugar actual y fuera de su propio subárbol (`ruta`).
- Nombre repetido entre los hijos del destino (sin importar mayúsculas) o mismo `codigo` en el mismo nivel: «Ya existe «101» en ese piso.».
- En una transacción se actualizan `padre_id`, `ruta` y `profundidad` del espacio y se reescribe el prefijo de `ruta` (y la profundidad) de todo lo que cuelga. El `id` no cambia: `espacio_llave`, equipos, recorridos, novedades y auditoría siguen apuntando a él.
- Auditoría `espacios.movido` (en la Bitácora: «Cambio de lugar») con `padre_id` y `lugar` («Torre A › Piso 1») antes y después.
- `destinos()` arma la lista (tope `MAX_DESTINOS` = 3000) con la ruta legible de cada lugar; la pide `public/js/espacios.js`.
- Permiso `espacios.editar`, revisado también sobre el registro (alcance de sede y «solo los propios»).

**Eliminar definitivamente las marcadas** (`AdministradorEspacios::eliminarVarios`, `partes/eliminar-varias`, en las listas de habitaciones de un piso y de pisos de una zona):

- Permiso `espacios.borrar` (el mismo del botón individual `componentes.borrar`); sin él no se ven casillas ni botón y el servidor responde 403. Cada espacio se revisa además con `can('espacios.borrar', $espacio)` (alcance).
- Confirmación: la cantidad marcada o `ELIMINAR` (sin importar mayúsculas). Tope `MAX_ELIMINAR` = 200 por envío. Solo se toman los ids que son hijos directos del espacio de la URL (de otra empresa o sede: 404).
- Cada espacio se borra **con todo lo que tiene dentro**: en una transacción por espacio se llama a `BorradoSeguro::eliminar` para cada descendiente (de lo más profundo hacia arriba) y al final para él; cada uno deja su `espacios.eliminado_definitivo` con la copia de sus datos. Si él o algo de lo que tiene dentro está en uso (llave, equipo, recorrido, registro…), se revierte todo ese espacio y el resultado lo explica: «101: tiene 1 llave que la abre (HDC-101)» o «102: dentro, «Terraza» tiene 1 llave que la abre (HDC-102T)».
- Diferencia con el botón individual: el individual sigue tratando a los hijos como dependencia («Tiene 2 zonas o áreas dentro»); el borrado por lote, aprobado por el dueño, se lleva los hijos que nadie usó.

**Interfaz:** sin JavaScript en línea; todo en `public/js/espacios.js` (cargado con `@pushOnce`). El botón círculo rojo dice «Dar de baja» en `title` y `aria-label`.

**Excepción de vocabulario (criterios UX, regla 4), aprobada por el dueño:** los textos «Mover a…» y «Eliminar definitivamente las marcadas». El botón de envío del diálogo de mover dice **Guardar**.

## Pruebas

`tests/Feature/Organizacion/EspaciosTest.php` y `tests/Feature/Organizacion/EspaciosMoverEliminarTest.php` (mover: éxito con subárbol y auditoría, nivel inválido, descendiente, destino inactivo, nombre repetido, otra sede, otra empresa, sin permiso; eliminar varias: mezcla de usadas y libres con sus motivos, confirmación, sin permiso 403, otra empresa 404, ids de otro padre, tope).

## Pendiente (nota del dueño, Ronda 5 · Z-08)

No se construye todavía; se diseñará junto con Llaves:

1. **Las "Secciones" de hoy cambiarán de nombre a «Categoría de habitación».** En hotelería cada habitación tiene una categoría (por ejemplo "Vista al mar", "Junior Suite") y cada categoría tiene un **costo** propio. Se conserva lo construido (tabla `grupos_espacio` y la pestaña), se le cambia el nombre en pantalla y se le agregará el costo por categoría.
2. **Nuevo concepto «Sección de llaves».** Un grupo de cuartos que abre una llave maestra, sin importar edificio ni piso (por ejemplo, "Sección 1 A abre B101, B102 y C305"). Será propio de Llaves: hoy el alcance "Sección" de una llave usa las secciones de Zonas y áreas (`grupo_espacio_llave`); al separar los conceptos, ese alcance apuntará a la Sección de llaves.
3. Mientras tanto no se cambia nada: las secciones actuales siguen sirviendo para categorizar habitaciones y para el alcance "Sección" de las llaves.

## Ronda 5 de ajustes (parte 2): avisos en vivo (Z-02, Z-07)

Con el mecanismo genérico [avisos-duplicado.md](avisos-duplicado.md) y `GET /espacios/duplicado` (`espacios.duplicado`, permiso `espacios.crear` o `espacios.editar`, solo sedes visibles):

- **Nombre**: igual en el mismo lugar (misma sede y mismo contenedor) = «Ya existe…» (el servidor lo rechaza al guardar). Si está **desactivado** lo dice y ofrece **Reactivar** (con `espacios.eliminar`); al guardar, el error también lo dice: «… pero está desactivado: reactívalo (flecha verde) en lugar de crearlo de nuevo.». Parecido sin espacios ni guiones («Torre-A» y «Torre A») = «Se parece a «Torre A (TA)»» (solo aviso).
- **Código** (zonas / edificios): misma clave en la misma sede y nivel (**TB = T-B = T B = tb**) = «Se parece a «Torre B» (TB): los espacios y guiones no cuentan…». Es un aviso: el código sigue siendo opcional y no único; el formato (solo letras, números y guiones) lo sigue validando el servidor.
- **Tipo propio** («¿No encuentras el tipo…?»): si ya está en la lista (global o de la empresa, sin importar mayúsculas ni acentos) avisa «… ya existe en la lista: no hace falta agregarlo». Al guardar uno repetido ya **no** dice «disponible»: responde «El tipo «Jacuzzi» ya existía en la lista: no se duplicó y ya puedes elegirlo.» (`TipoEspacio::wasRecentlyCreated`).
- **Sección**: nombre repetido en la misma sede = «Ya existe la sección…».

Los avisos se pintan debajo de toda la fila (`data-duplicado-aviso`), no dentro de la columna angosta del código.

Datos demo: `ronda5bDemo()` crea, una sola vez, la zona **Torre Jardín (TJ)** desactivada en Centro para practicar «está desactivado, ¿lo reactivas?».

> Mejoras de 2026-10 (roles sin números, asistente de permisos, configuración por módulo, avisos por persona, historial y varias sedes): ver [estructura-mejoras.md](estructura-mejoras.md) y [ADR-0016](../decisiones/ADR-0016-mejoras-de-estructura.md).
