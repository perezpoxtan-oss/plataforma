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

Permisos: `espacios.ver`, `espacios.crear`, `espacios.editar` y `espacios.eliminar` (desactivar o reactivar). Con alcance de sede, el usuario solo ve y toca las sedes asignadas.

## Interfaz

- **Plantillas:** vistas en `resources/views/organizacion/espacios/`, con partes compartidas: `migas`, `acciones` y `formulario`. El formulario sirve para alta y edición de cualquier nivel.
- **Colores:** `tema-rojo` en zonas y detalle, `tema-ambar` en pisos y habitaciones, como en SEGCAT.
- **JavaScript:** `data-abrir-dialogo` acepta `data-padre` y `data-padre-nombre`, para usar un solo diálogo "Agregar Elemento" en todas las áreas. El filtro genérico de fichas acepta `data-filtro-sede`.
- **Datos demo:** `plataforma:demo` crea Torre A con 2 pisos, 8 habitaciones, la sección "Vista al mar" y el detalle de la 101.

## Pruebas

`tests/Feature/Organizacion/EspaciosTest.php`
