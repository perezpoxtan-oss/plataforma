# Responsivas y firmas

Réplica del "Control de Resguardos" de SEGCAT (`modules/equipos/responsivas_lista.php`, `responsiva_proceso.php`, `ticket_responsiva.php`, `equipo_historial_ajax.php`; §4.20 del inventario funcional). Menú **Operación → Control de Activos → Responsivas**. Usa [Equipos de seguridad](equipos.md), [Colaboradores](colaboradores.md), el [lector universal](lector.md) y las [firmas](firmas.md).

## Piezas

| Pieza | Archivo |
|---|---|
| Modelos | `app/Models/Responsiva.php` (lote), `app/Models/EquipoResponsiva.php` (equipos del lote) (+ `Equipo::resguardoActual()`) |
| Reglas | `app/Services/Responsivas/AdministradorResponsivas.php` |
| Pantalla | `app/Http/Controllers/Operacion/ResponsivaController.php` |
| Vistas | `resources/views/operacion/responsivas/index.blade.php`, `_fila.blade.php` (fila "+ Añadir Equipo"), `_historial.blade.php`, `hoja.blade.php` |
| Comportamiento | bloque "Operación: Préstamo de llaves y Responsivas" al final de `public/js/plataforma.js` |
| Estilos | bloque del mismo nombre al final de `public/css/plataforma.css` y `public/css/modos-pantalla.css` |
| Pruebas | `tests/Feature/Seguridad/ResponsivasTest.php` |

## Tablas (migración `2026_10_10_000210_crear_prestamos_llaves_y_responsivas`)

En SEGCAT el "lote" no existía: era el mismo colaborador con la misma fecha exacta (`"<id_colaborador>_<timestamp>"`), y la firma se repetía en base64 en cada renglón. Ahora el lote es un registro propio.

**`responsivas`** (el lote):

| Columna | Qué guarda |
|---|---|
| `empresa_id`, `sede_id`, `colaborador_id` | Empresa, sede de origen y resguardante |
| `numero`, `folio` | Consecutivo por empresa y folio como SEGCAT: 3 letras de la sede + `RES-` + 6 dígitos (`CENRES-000001`). Las letras salen del código de la sede o, si no tiene, de su nombre sin artículos. Únicos por empresa |
| `firma_ruta` | Ruta de la firma en el disco **privado** (`firmas/<empresa>/responsivas/aaaa/mm/<uuid>.jpg`). Oculta en JSON y en la auditoría |
| `estado` | `en_campo` → `devuelta` |
| `entregado_en`, `entregado_por` | Entrega (UTC) y guardia |
| `devuelto_en`, `recibido_por` | Recepción del lote (UTC) y guardia |
| `creado_por`, `actualizado_por`, timestamps | Auditoría |

**`equipos_responsiva`** (los equipos del lote): `empresa_id`, `responsiva_id`, `equipo_id`, `modalidad` (`prestado` = "Turno", `asignado` = "Fijo"), `estado_devolucion` (`ok` o `baja`), `devuelto_en` y la columna generada `equipo_en_campo` (`equipo_id` mientras no se devuelve), **única**: un equipo no puede estar en dos resguardos abiertos.

`responsivas.colaborador_id` está registrado en `AdministradorColaboradores::REFERENCIAS`.

## Endpoints

| Método y ruta | Nombre | Permiso | Qué hace |
|---|---|---|---|
| `GET /responsivas` | `responsivas.index` | `responsivas.ver` | Pestañas Equipos en Campo / Historial Devueltos (últimos 200 lotes) y el diálogo "Nuevo Resguardo (Lote)" |
| `POST /responsivas` | `responsivas.store` | `responsivas.crear` **y** `responsivas.firmar` | Guardar el lote con la firma |
| `PATCH /responsivas/{id}/recibir` | `responsivas.recibir` | `responsivas.editar` | "Recibir Lote Completo (OK)" |
| `GET /responsivas/{id}/firma` | `responsivas.firma` | `responsivas.ver` | Imagen de la firma (`Firmas::respuesta()`, `Cache-Control: private`, `nosniff`) |
| `GET /responsivas/{id}/hoja` | `responsivas.hoja` | `responsivas.imprimir` | "RESGUARDO MÚLTIPLE DE ACTIVOS DE SEGURIDAD" para imprimir, con folio y firma |
| `GET /responsivas/equipos/{equipo}/historial` | `responsivas.historial` | `responsivas.ver` | Fragmento HTML "Historial de Auditoría" del equipo (últimos 15) |

- Un resguardo o equipo de otra empresa o fuera del alcance responde **404**; la firma, igual (antes de llamar a `Firmas::respuesta()` se revisa el permiso y la sede).
- **Alcance** (`AdministradorResponsivas::limitar()`): por la sede del resguardo; "propios" = los que registró.

## Permisos

SEGCAT usaba `equipos.ver | crear | editar`. Ahora:

| Acción | Permiso | Plantillas |
|---|---|---|
| Ver, ver la firma, historial | `responsivas.ver` | todas las de Seguridad |
| Nuevo resguardo | `responsivas.crear` + `responsivas.firmar` (la firma del colaborador es obligatoria) | Administrador, Jefe, Supervisor, **Agente** (el Asistente no firma) |
| Recibir el lote | `responsivas.editar` | Administrador, Jefe, Asistente, Supervisor, Agente |
| Hoja / Archivo | `responsivas.imprimir` | Administrador, Director, Jefe, Asistente, Supervisor, Agente |

El lector necesita `equipos.ver` (tipo `equipo`) y `colaboradores.ver`; la lista de equipos disponibles respeta el alcance de `equipos.ver`.

## Reglas

- **Nuevo resguardo** (todo se revalida en el servidor):
  - sede activa y de las del usuario (`responsivas.crear`);
  - colaborador activo de esa sede (principal o adicional) o corporativo; los provisionales cuentan (misma regla que Préstamo de llaves);
  - de 1 a 25 equipos, sin filas vacías ni repetidas; cada uno de la empresa, **de esa sede**, **DISPONIBLE** y sin otro resguardo abierto;
  - modalidad Turno o Fijo por equipo;
  - **firma obligatoria**: `Firmas::guardar()` valida que sea JPEG/PNG real, no vacía y de hasta 300 KB. Se guarda al final de las validaciones; si algo falla después, se borra (no quedan firmas huérfanas).
  - En una transacción: consecutivo con bloqueo, folio, renglones y `AdministradorEquipos::asignarPorResponsiva($actor, $equipo, true)` por equipo (queda ASIGNADO y audita `equipos.asignado`).
- **Recibir Lote Completo (OK)**: solo un lote EN CAMPO. Cada equipo ASIGNADO vuelve a DISPONIBLE (`asignarPorResponsiva(..., false)`, audita `equipos.devuelto`); uno que se dio de baja mientras estaba en campo queda de baja y su renglón se marca `baja`. El lote pasa a LOTE CERRADO con quién y cuándo.
- **Transiciones prohibidas**: recibir un lote ya cerrado; resguardar un equipo que no está DISPONIBLE o que sigue en otro lote.
- **Escaneo**: el lector del colaborador lleva al lector de equipos; cada equipo escaneado se agrega solo a la lista (o llena la fila vacía). "+ Añadir Equipo" agrega una fila para elegirlo de la lista; las opciones se limitan a la sede elegida y no se repiten.
- **Auditoría**: `responsivas.creado`, `responsivas.recibido` (folio, colaborador, equipos con modalidad, estado), más `equipos.asignado` / `equipos.devuelto`. Nunca la ruta de la firma.
- **Ficha del equipo** (Equipos de seguridad): muestra **A cargo de: colaborador · Turno/Fijo · folio** (`Equipo::resguardoActual()`).
- Sin N+1: la lista carga sede, colaborador, guardias y equipos con su tipo con `with()`.

## Qué se corrigió respecto a SEGCAT

| SEGCAT | Ahora |
|---|---|
| El lote era "mismo colaborador + misma fecha exacta": dos lotes en el mismo segundo se mezclaban; la clave viajaba en la URL | Tabla propia `responsivas` con folio único |
| La firma se guardaba como base64 en cada renglón y se mostraba en `data-firma` en la página (cualquiera con `equipos.ver` la veía en el HTML) | Una sola imagen en el disco privado, servida por la plataforma con permiso y sede |
| No se validaba que la firma fuera una imagen ni que existiera en el servidor (solo en el navegador) | Firma obligatoria y validada en el servidor |
| Se podían resguardar equipos no disponibles o de otra sede (el UPDATE de estado no lo revisaba) | Solo DISPONIBLES de la sede, sin otro lote abierto (también en la base) |
| El estado del equipo quedaba en "PRESTADO"/"ASIGNADO" según la modalidad | El equipo queda ASIGNADO (único estado del inventario) y la modalidad se guarda en el renglón |
| "Recibir lote" no revisaba si ya estaba recibido ni el estado de cada equipo (un equipo de baja volvía a DISPONIBLE) | Solo lotes en campo; los dados de baja se quedan así |
| `ticket_responsiva.php` solo pedía `equipos.ver` y no revisaba empresa ni sede | `responsivas.imprimir` + alcance; 404 fuera de su empresa o sede |
| Hoja y folio con el id del primer renglón (consecutivo global) | Consecutivo por empresa |
| Canvas propio y JS en línea (`onclick`) | Componente de firma y lector universal; sin JS en línea |
| "Hotel de Origen" | "Sede de Origen" |

## Pendiente / notas para el integrador

- SEGCAT tenía un diálogo de devolución individual con estado DAÑADO / EXTRAVIADO (`responsiva_modal_devolver.php`) que la lista ya no usaba; no se migró. Para un equipo perdido en campo: darlo de baja con voucher en Equipos y luego recibir el lote.
- Al dar de baja un equipo ASIGNADO, Equipos aún no prellena al responsable del resguardo (ver [equipos.md](equipos.md)).
