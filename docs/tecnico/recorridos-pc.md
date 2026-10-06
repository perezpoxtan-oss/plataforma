# Recorridos de Protección Civil

Réplica de `modules/bitacora/recorridos_pc_*` y `pc_equipos_*` de SEGCAT (`recorridos_pc_lista.php`, `recorridos_pc_proceso.php`, `recorridos_pc_detalle_ajax.php`, `recorridos_pc_pdf.php`, `pc_equipos_lista.php`, `pc_equipos_modal_editar.php`, `pc_equipos_proceso.php`, `pc_equipos_ticket.php`; inventario §4.26). Pantalla `/recorridos-pc` (menú Operación → Recorridos de Protección Civil, submódulo `recorridos_pc` de Bitácora de novedades).

- Controladores: `app/Http/Controllers/Seguridad/RecorridoPcController.php` (recorridos, reporte, exportación) y `EquipoPcController.php` (catálogo, QR, etiqueta, `ir`)
- Reglas: `app/Services/RecorridosPc/AdministradorRecorridosPc.php` (alcance, iniciar, punto de inspección, ticket, guardar / finalizar, avance) y `CatalogoEquiposPc.php` (alcance, alta, edición, baja, reactivación, validación)
- Ubicaciones: `app/Services/RecorridosPc/Ubicaciones.php` (zona · piso · área de Zonas y áreas con una sola consulta)
- Modelos: `EquipoPc` (Identificable, `TieneIdentificador`), `RecorridoPc`, `RevisionRecorridoPc` (los tres con `PerteneceAEmpresa` y `RegistraAutor`)
- Migración: `2026_10_10_000350_crear_recorridos_de_proteccion_civil`
- Vistas: `resources/views/seguridad/recorridos-pc/{index,show,equipos,etiqueta,reporte}.blade.php`
- JS: bloque «Recorridos de Protección Civil» al final de `public/js/plataforma.js`; CSS: bloque al final de `public/css/plataforma.css` y de `public/css/modos-pantalla.css` (Sol y Noche)
- Pruebas: `tests/Feature/Seguridad/RecorridosPcTest.php` (18)

## Tablas

Todas llevan `empresa_id` (scope de tenant) y autoría `creado_por` / `actualizado_por` + timestamps. Las horas se guardan en **UTC** y se muestran con `@fecha`.

| Tabla | Qué guarda | Notas |
|---|---|---|
| `equipos_pc` | Catálogo: `sede_id`, `categoria` (`EquipoPc::CATEGORIAS`), `numero_serie` (mayúsculas), `espacio_id` (zona, piso o área específica de Zonas y áreas), `referencia` («Junto al elevador»), `activo`, `codigo_qr`, `etiqueta_nfc` | Únicos `(empresa_id, sede_id, numero_serie)` y `(empresa_id, etiqueta_nfc)`; `codigo_qr` único. |
| `recorridos_pc` | La ronda: `sede_id`, `numero` (consecutivo por empresa → `#00012`), `espacio_id` (edificio o zona, opcional), `estatus`, `observaciones_generales`, `novedad_id` (ticket), `finalizado_en`, `finalizado_por` | Único `(empresa_id, numero)`. `creado_por` = quien lo inició. |
| `recorrido_pc_revisiones` | Un punto: `recorrido_pc_id`, `equipo_pc_id` (null si se capturó a mano), `identificador`, `categoria`, `ubicacion` (texto copiado), `criterios` (JSON `{clave: bool}`), `resultado` (`ok` / `falla`), `observaciones` | Identificador, categoría y ubicación se **copian**: el reporte no cambia si después se edita el catálogo. |

### Mapeo para el importador de SEGCAT

| SEGCAT | Plataforma |
|---|---|
| `cat_equipos_pc` (`id_hotel`, `tipo_equipo`, `numero_serie`, `id_seccion`, `id_area_especifica`, `estatus`) | `equipos_pc` (`sede_id`, `categoria`, `numero_serie`, `espacio_id` = el área específica o, si no hay, la sección migrada a Zonas y áreas, `activo`). Generar `codigo_qr` (lo hace el modelo). |
| `recorridos_pc_sesiones.id_sesion` | `recorridos_pc.numero` (se conserva el número) |
| `id_hotel`, `id_edificio`, `realizado_por`, `fecha_hora`, `observaciones_generales` | `sede_id`, `espacio_id`, `creado_por`, `created_at` (hora local de la sede → UTC), `observaciones_generales` |
| `estatus` (`EN_PROCESO`, `COMPLETO`, `CON_HALLAZGOS`) | `estatus` según `RecorridoPc::ESTATUS_SEGCAT` |
| `id_novedad_generada` | `novedad_id` (id del ticket migrado) |
| `recorridos_pc_items` (`identificador`, `categoria`, `edificio`, `nivel`, `area`, `criterios`, `estado_item`, `observaciones`) | `recorrido_pc_revisiones` (`identificador`, `categoria`, `ubicacion` = «edificio · nivel · área», `criterios` con cada pieza de la categoría en `true`/`false`, `resultado` = `ok`/`falla`, `observaciones`); `equipo_pc_id` buscando el `numero_serie` en el catálogo de la sede |

## Rutas (endpoints) y permisos

| Método | Ruta | Nombre | Permiso |
|---|---|---|---|
| GET | `/recorridos-pc` | `recorridos_pc.index` | `recorridos_pc.ver` |
| POST | `/recorridos-pc` (`sede_id`, `espacio_id`, `observaciones_generales`) | `recorridos_pc.store` | `recorridos_pc.crear` (sede de su alcance) |
| GET | `/recorridos-pc/{id}` (`?equipo=ID` o `?manual=1` abre el punto de inspección) | `recorridos_pc.show` | `recorridos_pc.ver` |
| POST | `/recorridos-pc/{id}/revisiones` (`equipo_pc_id` o `identificador`+`categoria`+`zona_id`, `criterios[clave]=1`, `observaciones`) | `recorridos_pc.revisiones.store` | `recorridos_pc.crear` sobre el recorrido |
| PUT | `/recorridos-pc/{id}` (`observaciones_generales`, `finalizar=1`) | `recorridos_pc.update` | `recorridos_pc.crear` sobre el recorrido |
| GET | `/recorridos-pc/reporte?desde=&hasta=&sede=` | `recorridos_pc.reporte` | `recorridos_pc.imprimir` |
| GET | `/recorridos-pc/exportar?desde=&hasta=&sede=` | `recorridos_pc.exportar` | `recorridos_pc.exportar` (CSV con BOM, un renglón por equipo revisado) |
| GET | `/recorridos-pc/equipos` | `recorridos_pc.equipos.index` | `recorridos_pc.ver` |
| POST | `/recorridos-pc/equipos` (`_siguiente=1`: «Guardar y capturar siguiente») | `recorridos_pc.equipos.store` | `equipos.crear` |
| PUT | `/recorridos-pc/equipos/{id}` | `recorridos_pc.equipos.update` | `equipos.editar` |
| PATCH | `/recorridos-pc/equipos/{id}/desactivar` · `/reactivar` | `recorridos_pc.equipos.desactivar` · `.reactivar` | `equipos.eliminar` |
| GET | `/recorridos-pc/equipos/{id}/etiqueta` | `recorridos_pc.equipos.etiqueta` | `equipos.imprimir` |
| GET | `/recorridos-pc/equipos/{id}/qr` (SVG) | `recorridos_pc.equipos.qr` | `recorridos_pc.ver` |
| GET | `/recorridos-pc/equipos/{id}/ir` | `recorridos_pc.equipos.ir` | `recorridos_pc.ver` (a donde lleva el lector: ver abajo) |

- **Alcance:** con alcance de **sede** solo se ve y se toca lo de sus sedes (otra sede u otra empresa → 404); con **propios**, lo que él registró. El superadministrador elige la empresa de trabajo.
- **Catálogo con permisos de Equipos de seguridad.** El catálogo se **consulta** con `recorridos_pc.ver` (la guardia lo necesita en su ronda) y se **administra** con `equipos.crear | editar | eliminar | imprimir` (Padrones). Así el **Agente** lo consulta pero no da de alta equipos, como en el resto de los padrones; Asistente, Supervisor y Jefe de seguridad sí, en su sede. El catálogo de módulos no se tocó.
- **Plantillas de rol:** el Agente inicia, continúa y finaliza recorridos de su sede e imprime el Reporte de Auditoría; no exporta.

## Lector universal

- `EquipoPc` es `Identificable`: tipo **`equipo_pc`** en `config/lector.php`, permiso `recorridos_pc.ver`, columna legible `numero_serie` (se encuentra tecleando «EXT-01», con el QR, con la etiqueta NFC/RFID o con un lector USB/Bluetooth).
- La pantalla del recorrido usa `@include('componentes.lector', ['tipos' => 'equipo_pc', 'nombre' => 'equipo'])`; el JS escucha `lector:elegido` y abre `?equipo=ID#punto`. En PC (puntero fino) el campo queda enfocado para el lector USB; en el celular no se abre el teclado solo.
- `urlLector()` → `recorridos_pc.equipos.ir`: si el usuario puede crear y hay un recorrido **En Proceso** de la sede del equipo (primero uno que él inició), abre ese recorrido con el equipo listo para inspeccionar; si no, la ficha del catálogo. Así el iPhone que lee la etiqueta NFC con dirección `/e/{codigo}` (sin app) cae directo en el punto de inspección.
- La etiqueta impresa lleva el QR dibujado en el servidor (`bacon/bacon-qr-code`) con `route('lector.ir', codigo_qr)`.

## Reglas

- **Iniciar:** sede activa de su alcance (si solo tiene una, ya viene elegida); edificio/zona opcional (nivel edificio, activo, de esa sede); observaciones generales. Nace **EN PROCESO**, número consecutivo por empresa. Auditoría `recorridos_pc.creado`.
- **Punto de inspección (uno a la vez):**
  - Del catálogo: el equipo debe ser de la sede del recorrido y estar activo.
  - A mano (equipo sin etiqueta o fuera del catálogo): identificador + categoría (+ ubicación opcional de la sede). Si el identificador sí existe activo en el catálogo de la sede, se liga y se usa su categoría.
  - Criterios = piezas de la categoría + «Criterios Operativos Universales» (los mismos textos de SEGCAT, en `Formatos\RecorridoPc::CATEGORIAS` / `UNIVERSALES`). Cada criterio que **no llega marcado** es falla.
  - **OK** si todos están sanos y no hay observación; si no, **FALLA**.
  - Escanear otra vez un equipo ya revisado en ese recorrido avisa, pero se permite (queda otro registro, como en SEGCAT).
  - Se bloquea la fila del recorrido (`lockForUpdate`) para que dos guardias no abran dos tickets.
- **Ticket de Protección Civil** (con `AdministradorNovedades`, sin duplicar su lógica):
  - Primer hallazgo → `AdministradorNovedades::crear()` con categoría `proteccion_civil`, ubicación «VER DETALLE EN RECORRIDO PC #00012», descripción «Hallazgo durante Recorrido de Protección Civil …: EXT-01 (Extintor) — Torre A · Piso 1: Falla en Manómetro. Obs: …», Área General = el edificio del recorrido; nota de sistema en el Minuto a Minuto; `recorridos_pc.novedad_id`; auditoría `novedades.creado` y `recorridos_pc.ticket_generado`.
  - Hallazgos siguientes → `AdministradorNovedades::anotar()` (nota de sistema «Nuevo hallazgo…») en el mismo ticket. Si ese ticket ya está **Resuelto**, se abre uno nuevo y el recorrido se liga a él.
  - Quien registra el punto necesita `novedades.crear` en la sede (todas las plantillas que crean recorridos lo tienen). Si no lo tiene, el punto con falla **no se guarda** y se le explica qué permiso pedir: un hallazgo nunca queda sin ticket.
- **Guardar y Continuar Después:** guarda las observaciones generales; sigue EN PROCESO (`recorridos_pc.actualizado` si cambiaron).
- **Finalizar Recorrido:** exige al menos un punto («Agrega al menos un equipo al recorrido antes de finalizarlo.») → **COMPLETO** si ningún punto tiene falla, **CON HALLAZGOS** si alguno la tiene (cuentan todos los puntos del recorrido). Fija `finalizado_en/por`. Auditoría `recorridos_pc.finalizado`.
- **Transiciones prohibidas:** un recorrido COMPLETO o CON HALLAZGOS ya no recibe puntos, no se vuelve a finalizar ni cambia sus observaciones (error «… ya se finalizó …»). No hay reapertura.
- **Avance:** equipos activos del catálogo de la sede (o de la zona elegida y todo lo que cuelga de ella) contra los ya revisados; lista de **pendientes** con botón «Revisar» (por si la etiqueta está dañada).
- **Catálogo:** Núm. de Serie / ID obligatorio, en mayúsculas, **único por sede** (en otra sede se puede repetir); la ubicación (zona/piso y área específica) debe ser de la sede, activa, y el área debe colgar de la zona elegida; la etiqueta NFC no puede estar en ningún otro registro de la empresa. Dar de baja (`recorridos_pc.desactivado`) lo quita de los pendientes y del escaneo; reactivar (`recorridos_pc.reactivado`). Alta y edición: `recorridos_pc.creado` / `recorridos_pc.actualizado` sobre `EquipoPc`.
- **Reporte de Auditoría:** por rango de fechas en la hora local (por omisión del 1.º del mes a hoy, máximo 366 días) y sede; resumen (recorridos, equipos revisados, hallazgos) y una tabla por recorrido con las piezas con falla.
- **Lista:** todos los recorridos En Proceso más los últimos 100 finalizados (SEGCAT: 100); para lo anterior, el Reporte de Auditoría. Filtros por estatus, sede y texto, sin consultas extra (`withCount`).

## Qué se corrigió respecto a SEGCAT

- **Los criterios desmarcados nunca contaban como falla:** una casilla desmarcada no se envía, así que SEGCAT solo recibía las marcadas y todo salía OK salvo que hubiera observación. Ahora el servidor conoce las piezas de cada categoría y la que no llega es falla.
- **Continuar un recorrido duplicaba los puntos:** al retomarlo, SEGCAT volvía a cargar y a enviar todos los puntos anteriores, que se insertaban otra vez. Ahora cada punto se guarda una sola vez, al momento (si se cae la señal no se pierde lo revisado).
- **Un recorrido con hallazgos guardado «para después» ya no se podía continuar** (pasaba a CON_HALLAZGOS). Ahora sigue EN PROCESO hasta «Finalizar», y el estatus final toma en cuenta **todos** los puntos (SEGCAT solo miraba los de la última vuelta).
- **El hallazgo se anotaba concatenando la descripción del ticket.** Ahora el ticket se abre con el servicio de Novedades (número por empresa, auditoría, Área General) y los siguientes hallazgos van al Minuto a Minuto; si el ticket ya se resolvió, se abre otro en lugar de esconder el hallazgo en un caso cerrado.
- **Escaneo:** html5-qrcode desde un CDN y Web NFC solo en Android. Ahora el lector universal (USB/Bluetooth en PC y iPhone, cámara también en iPhone, NFC en Android) y el QR lleva una dirección que el iPhone abre sin app directo en el punto de inspección.
- **QR de la etiqueta:** se pedía a `api.qrserver.com` con el número de serie (dato a un tercero, sin internet no salía). Ahora se dibuja en el servidor con un código no adivinable.
- **Ubicación:** edificios, secciones y áreas específicas se cargaban de **todas** las sedes de **todas** las empresas en el JavaScript de la página. Ahora salen de Zonas y áreas, solo de las sedes del usuario, y se validan contra la sede.
- **Número de recorrido por empresa** (antes el id global, compartido entre empresas) y alcance por sede en todas las rutas (antes el detalle respondía 403 a otra sede pero mostraba que existía; ahora 404).
- **Permisos:** todo se hacía con `bitacora.*`. Ahora el recorrido usa `recorridos_pc.*` y el catálogo los de Equipos de seguridad: el Agente ya no puede dar de alta o editar extintores.
- **Exportación** a CSV con los mismos filtros del reporte (no existía).
- Sin `onclick` ni JavaScript en la página; mensajes de validación en español dentro del punto o del diálogo; modos Sol y Noche.
