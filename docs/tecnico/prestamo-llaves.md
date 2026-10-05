# Préstamo de llaves

Réplica de la "Bitácora de Llaves" de SEGCAT (`modules/llaves/bitacora_llaves.php`, `bitacora_llaves_proceso.php`, `llave_historial_ajax.php`, `reporte_llaves_excel.php`; §4.16 del inventario funcional). Menú **Operación → Caseta y Control → Préstamo de llaves**. Usa el [Catálogo de llaves](llaves.md), los [Colaboradores](colaboradores.md) y el [lector universal](lector.md).

## Piezas

| Pieza | Archivo |
|---|---|
| Modelo | `app/Models/PrestamoLlave.php` (+ `Llave::prestamoAbierto()`) |
| Reglas | `app/Services/PrestamoLlaves/AdministradorPrestamosLlaves.php` |
| Pantalla | `app/Http/Controllers/Operacion/PrestamoLlaveController.php` |
| Vistas | `resources/views/operacion/prestamo-llaves/index.blade.php`, `_ficha.blade.php` (ficha "LLAVE FUERA"), `_historial.blade.php` |
| Comportamiento | bloque "Operación: Préstamo de llaves y Responsivas" al final de `public/js/plataforma.js` |
| Estilos | bloque del mismo nombre al final de `public/css/plataforma.css` y `public/css/modos-pantalla.css` |
| Pruebas | `tests/Feature/Seguridad/PrestamoLlavesTest.php` |

## Tabla (migración `2026_10_10_000210_crear_prestamos_llaves_y_responsivas`)

**`prestamos_llaves`** (SEGCAT: `bitacora_llaves`):

| Columna | Qué guarda |
|---|---|
| `empresa_id`, `sede_id` | Empresa y sede (la de la llave) |
| `llave_id`, `colaborador_id` | Qué llave y quién se la llevó |
| `tipo_garantia` | `gafete_interno`, `ine`, `licencia`, `pasaporte`, `ninguna` (en pantalla: GAFETE INTERNO, INE…) |
| `folio_garantia` | Folio o detalle de la identificación (mayúsculas, opcional, 80) |
| `estado` | `en_uso` → `devuelta` |
| `prestado_en`, `entregado_por` | Salida (UTC) y guardia que entregó |
| `devuelto_en`, `recibido_por` | Regreso (UTC) y guardia que recibió |
| `anulado`, `anulado_en`, `anulado_por` | Captura equivocada (no se borra) |
| `llave_en_uso` | Columna generada: `llave_id` si está en uso y no anulado; `NULL` si no. **Única**: la base impide que una llave quede prestada dos veces |
| `creado_por`, `actualizado_por`, timestamps | Auditoría |

`colaborador_id` está registrado en `AdministradorColaboradores::REFERENCIAS` (al unir un provisional, sus préstamos pasan al registro correcto).

## Endpoints

| Método y ruta | Nombre | Permiso | Qué hace |
|---|---|---|---|
| `GET /prestamo-llaves` | `prestamo_llaves.index` | `prestamo_llaves.ver` | Pestañas Llaves en Uso / Historial de Entregas (últimos 300) y el diálogo "Prestar Llave" |
| `POST /prestamo-llaves` | `prestamo_llaves.store` | `prestamo_llaves.crear` | Prestar. Con `Accept: application/json` responde **201** `{ok, mensaje, llave_id, ficha}` (la ficha ya dibujada) o **422** `{errores}`; sin JavaScript redirige |
| `PATCH /prestamo-llaves/{id}/recibir` | `prestamo_llaves.recibir` | `prestamo_llaves.editar` | EN USO → DEVUELTA |
| `PATCH /prestamo-llaves/{id}/anular` | `prestamo_llaves.anular` | `prestamo_llaves.eliminar` | Anular (captura equivocada) |
| `PATCH /prestamo-llaves/{id}/reactivar` | `prestamo_llaves.reactivar` | `prestamo_llaves.eliminar` | Quitar la anulación |
| `GET /prestamo-llaves/llaves/{llave}/historial` | `prestamo_llaves.historial` | `prestamo_llaves.ver` | Fragmento HTML "Historial de Movimientos" (últimos 15) |
| `GET /prestamo-llaves/exportar?sede=&estado=en_uso\|devuelta\|anulado&desde=&hasta=` | `prestamo_llaves.exportar` | `prestamo_llaves.exportar` | CSV `Auditoria_Llaves_<fecha>.csv` (UTF-8 con BOM); `desde`/`hasta` son días de la hora local |

- Todo corre con la empresa de trabajo fijada en el Tenant. Un préstamo o una llave de otra empresa, o fuera del alcance, responde **404**.
- **Alcance** (`AdministradorPrestamosLlaves::limitar()`): con alcance de sede se ve, presta, recibe, anula y exporta solo en sus sedes; con "propios", además, solo lo que él registró.

## Permisos

SEGCAT usaba `llaves.ver | crear | editar | eliminar`. Ahora el módulo tiene los suyos (`prestamo_llaves.*`), separados del Catálogo:

| Acción | Permiso | Plantillas que lo traen |
|---|---|---|
| Ver la bitácora y el historial | `prestamo_llaves.ver` | todas las de Seguridad |
| Prestar | `prestamo_llaves.crear` | Administrador, Jefe, Asistente, Supervisor, **Agente** |
| Recibir | `prestamo_llaves.editar` | ídem |
| Anular / Reactivar | `prestamo_llaves.eliminar` | Administrador, Jefe (el Agente **no** anula) |
| Excel (Auditoría) | `prestamo_llaves.exportar` | Administrador, Director, Jefe, Asistente, Supervisor |

El lector necesita además `llaves.ver` (tipo `llave`) y `colaboradores.ver` (tipo `colaborador`), que todas esas plantillas ya tienen.

## Reglas

- **Prestar** (todo se revalida en el servidor, en una transacción con la llave bloqueada):
  - la **sede** debe ser activa y de las del usuario (`prestamo_llaves.crear`);
  - la **llave** debe existir en la empresa, estar **activa**, ser **de esa sede** y **no estar en uso** (un préstamo anulado no cuenta). El mensaje dice quién la tiene;
  - el **colaborador** debe estar activo, no ser un duplicado ya unido, y trabajar en la sede (sede principal o adicional) o ser corporativo (sin sede fija). **Los provisionales sí cuentan** (SEGCAT: solo "estatus = 1"; RH los valida después y, si eran duplicados, el préstamo se mueve);
  - la **garantía** es obligatoria (GAFETE INTERNO por omisión, como SEGCAT); el folio es opcional.
- **Recibir**: solo EN USO y no anulado. Guarda `devuelto_en` y `recibido_por`.
- **Anular**: solo un préstamo EN USO no anulado. La llave queda libre de inmediato y el préstamo pasa al Historial marcado ANULADO.
- **Reactivar**: solo un anulado. Si estaba EN USO y esa llave ya se volvió a prestar, se rechaza ("No se puede reactivar — esa llave ya se volvió a prestar…").
- **Transiciones prohibidas** (mensaje claro, sin cambios): recibir dos veces, recibir o anular un anulado, anular uno devuelto, reactivar uno que no está anulado.
- **Registrar y Capturar Siguiente**: el diálogo se envía por `fetch`; al guardar pinta la ficha nueva, limpia llave, colaborador y garantía, deja la sede y vuelve a poner el cursor en la llave (el lector USB escribe ahí). Avisa en pantalla si la llave escaneada ya está fuera o es de otra sede.
- **Sede sugerida**: si el usuario tiene una sola sede, ya viene elegida; si no, la de su colaborador.
- **Auditoría**: `prestamo_llaves.creado`, `prestamo_llaves.recibido`, `prestamo_llaves.anulado`, `prestamo_llaves.reactivado` (con llave, garantía y estado).
- **Fechas**: se guardan en UTC y se muestran con `@fecha` (hora local). En "Llaves en Uso" la salida de hoy se ve solo con la hora; la de otro día, con la fecha.
- Sin N+1: la lista carga sede, llave, colaborador y guardias con `with()`.

## Qué se corrigió respecto a SEGCAT

| SEGCAT | Ahora |
|---|---|
| Permisos prestados de `llaves.*` (quien edita el catálogo también recibía y anulaba) | Permisos propios `prestamo_llaves.*` |
| `recibir` no revisaba el estado: se podía recibir dos veces (cambiaba la hora de regreso) o "recibir" un anulado | Solo EN USO y no anulado |
| Nada impedía dos préstamos vigentes de la misma llave si dos casetas guardaban a la vez | Transacción con bloqueo y columna única `llave_en_uso` en la base |
| El Excel usaba el permiso `ver`, no filtraba por sede ni marcaba los anulados | Permiso `exportar`, alcance por sede, filtros (sede, estado, fechas) y columnas de anulación |
| El historial de la llave mezclaba anulados como si fueran préstamos | Los anulados se marcan ANULADO con quién y cuándo |
| Select2 + jQuery + jsQR desde CDN + Web NFC repetidos en la pantalla | Lector universal (USB/Bluetooth, cámara también en iPhone, NFC de Android) |
| Fechas en la hora del servidor | UTC guardado, hora local mostrada |
| Mensajes de error por URL (`?error=llave_ya_prestada`) | Dentro del diálogo, sin perder lo capturado |
| "Instalación (Hotel)" | "Sede" |
| Colaborador validado contra `id_hotel` o NULL | Sede principal, sedes adicionales o corporativo |

## Pendiente / notas para el integrador

- El Catálogo de llaves permite dar de baja una llave que está **EN USO** (no lo bloquea); el préstamo sigue abierto y se recibe normalmente. Conviene decidir si la baja debe pedir recibirla antes.
