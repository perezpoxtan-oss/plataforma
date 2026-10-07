# Catálogo de llaves

Réplica de `modules/llaves` de SEGCAT ("Control de Llaves": `llave_lista.php`, `llave_modal_editar.php`, `llave_proceso.php`, `llave_imprimir.php`, `llave_verificar_nomenclatura_ajax.php`, `llave_costo_sugerido_ajax.php`). El **Préstamo de llaves** (bitácora `bitacora_llaves.php`) queda fuera: vive en Operación y usará este catálogo.

## Piezas

| Pieza | Archivo |
|---|---|
| Modelos | `app/Models/Llave.php`, `app/Models/HorarioLlave.php` |
| Reglas | `app/Services/Llaves/AdministradorLlaves.php` |
| Pantallas | `app/Http/Controllers/Seguridad/LlaveController.php` |
| Vistas | `resources/views/seguridad/llaves/index.blade.php`, `_formulario.blade.php`, `etiquetas.blade.php` |
| Comportamiento | bloque "Catálogo de llaves" al final de `public/js/plataforma.js` |
| Estilos | bloque "Catálogo de llaves" al final de `public/css/plataforma.css` y `public/css/modos-pantalla.css` |
| Pruebas | `tests/Feature/Seguridad/LlavesTest.php` |

## Tablas (migración `2026_10_09_000200_crear_llaves`)

- **`llaves`**: `empresa_id`, `sede_id`, `departamento_id`, `puesto_id`, `colaborador_id` (responsable permanente, excepcional), `nomenclatura` (mayúsculas, única por sede desde la Ronda 5), `descripcion`, `tipo_dispositivo`, `alcance`, `alcance_otro`, `id_externo`, `plataforma_externa`, `fecha_caducidad`, `codigo_qr` (único), `etiqueta_nfc` (única por empresa), `activo` y auditoría (`creado_por`, `actualizado_por`, timestamps).
- **`horarios_llave`**: `empresa_id`, `llave_id`, `nombre` (libre), `hora_inicio`, `hora_fin`. Sin horarios = válida las 24 horas. Si el fin es menor que el inicio, termina al día siguiente.
- **`espacio_llave`** (pivote): zonas (`edificio`), pisos (`area`) o áreas específicas (`area_especifica`) de **Zonas y áreas** que abre la llave.
- **`grupo_espacio_llave`** (pivote): secciones (`grupos_espacio`) que abre.

Constantes del modelo (antes eran catálogos `cat_tipos_llave` y `cat_alcances` sin empresa):

| `tipo_dispositivo` | Texto | ID externo |
|---|---|---|
| `electronica_rfid` | Electrónica (Magnética/RFID) | sí |
| `metalica` | Metálica tradicional | no |
| `biometrica` | Biométrica / Huella | sí |
| `clave_pin` | Clave / PIN | sí |

| `alcance` | Texto | Qué se elige |
|---|---|---|
| `global` | Global (Master Key) | nada: toda la sede |
| `zona` | Edificio / Zona completa | espacios nivel `edificio` |
| `piso` | Piso | espacios nivel `area` |
| `area` | Área específica / Cuarto | espacios nivel `area_especifica` |
| `seccion` | Sección (grupo de habitaciones) | `grupos_espacio` |
| `otra` | Otra | texto libre `alcance_otro` |

## Endpoints

| Método y ruta | Nombre | Permiso | Qué hace |
|---|---|---|---|
| `GET /llaves` | `llaves.index` | `llaves.ver` | Fichas, filtros, alta, edición y baja |
| `POST /llaves` | `llaves.store` | `llaves.crear` | Alta |
| `PUT /llaves/{id}` | `llaves.update` | `llaves.editar` | Edición (no cambia el estado) |
| `POST /llaves/{id}/baja` | `llaves.baja` | `llaves.eliminar` | Baja con voucher de reposición |
| `PATCH /llaves/{id}/reactivar` | `llaves.reactivar` | `llaves.eliminar` | Reactivación (el voucher se conserva) |
| `GET /llaves/etiquetas?llaves[]=…` | `llaves.imprimir` | `llaves.imprimir` | "Etiquetas de Llaveros Listas", hasta 120 por hoja |
| `GET /llaves/exportar?sede=&tipo=&estado=&caducidad=&q=` | `llaves.exportar` | `llaves.exportar` | CSV (UTF-8 con BOM) con los mismos filtros de la pantalla |

- Todo corre con la empresa de trabajo fijada en el Tenant. Una llave de otra empresa o fuera del alcance responde **404**.
- **Alcance:** con alcance de sede se ven, registran, editan, imprimen y exportan solo las llaves de sus sedes; con "propios", además, solo las que dio de alta (`AdministradorLlaves::limitar()`).
- El lector universal la encuentra como tipo `llave` (`config/lector.php`) por `codigo_qr`, `etiqueta_nfc` o `nomenclatura`. `/e/{codigo}` abre `llaves.index#llave-{id}`.

## Reglas (`AdministradorLlaves`)

- **Nomenclatura** en mayúsculas y sin espacios dobles; única por sede (Ronda 5; antes por empresa). Si la existente está de baja, el mensaje pide reactivarla. Aviso en vivo con `data-nombres-existentes`.
- **Sede** obligatoria, activa (o la que ya tenía) y entre las sedes del permiso (`crear` en alta, `editar` en edición).
- **Departamento** debe aplicar en esa sede (`Departamento::aplicanEn`). **Puesto**: si está ligado a departamentos, debe corresponder al elegido.
- **Responsable** (se elige con el lector, `tipos=colaborador`): colaborador activo de la empresa (o el que ya tenía). Registrado en `AdministradorColaboradores::REFERENCIAS` para que "unir duplicados" lo mueva.
- **Lugares:** cada id se revalida en el servidor contra la sede elegida y el nivel del alcance; uno ajeno o desactivado (salvo que ya lo tuviera) rechaza el guardado. Los alcances con lugares exigen al menos uno. Al cambiar de sede hay que volver a elegirlos.
- **ID externo** (mayúsculas) y **plataforma**: solo en dispositivos programables. No se repite "este ID en esta plataforma, en esta sede" (como el índice `uk_llaves_hotel_plataforma_externo` de SEGCAT); dos plataformas sí pueden coincidir.
- **Etiqueta NFC/RFID**: se guarda normalizada; `Llave::etiquetaOcupada()` dice qué llave la tiene.
- **Horarios:** filas vacías se ignoran; una fila a medias se rechaza con un mensaje claro; inicio y fin distintos; hasta 12.
- **Caducidad** (`Llave::caducidad()`, días contados en la hora local): vencida < 0, "Vence pronto" ≤ 30, vigente. La fecha se muestra con `@fecha($llave->caducidadParaMostrar(), 'd/m/Y')` (mediodía UTC, para que la zona horaria no la mueva de día).
- **Baja:** `Vouchers::validar()` + `Vouchers::darDeBaja()` con `referenciaCosto` = texto del tipo de dispositivo; audita `vouchers.creado` y `llaves.desactivado` (con el folio). El costo sugerido sale de `Vouchers::costoSugerido('llave', tipo)` y va incrustado en el diálogo (`data-costos`).
- **Auditoría:** `llaves.creado`, `llaves.actualizado` (con lugares y horarios), `llaves.desactivado`, `llaves.reactivado`.

## Gancho con Préstamo de llaves (conectado)

- `Llave::prestamoAbierto()` (`HasOne` a `prestamos_llaves` en uso y no anulado) y `LlaveController::consulta()` con `->withExists(['prestamoAbierto as en_uso'])` y el colaborador del préstamo: `Llave::enUso()` pinta la insignia **EN USO**.
- La ficha muestra **Usada por: nombre · desde dd/mm HH:MM** y, con `prestamo_llaves.ver`, el enlace **Historial de préstamos** (`/prestamo-llaves#historial-llave-{id}` abre el historial de esa llave).
- `index()` usa `addSelect()` para no perder `en_uso`. Detalle en [prestamo-llaves.md](prestamo-llaves.md).

## Ronda 5 de ajustes (QA del dueño)

Migración `2026_10_12_000210_ajustes_ronda_5_llaves_y_vouchers`.

- **LL-03 · Nombre único por sede.** El índice `unique(empresa_id, nomenclatura)` cambia a `unique(empresa_id, sede_id, nomenclatura)` (se crea el nuevo antes de quitar el anterior, por la llave foránea de `empresa_id` en MariaDB). Mensaje: «Ya existe una llave con el nombre «X» en la sede S.». El aviso en vivo usa `data-nombres-por-sede` (JSON `{sede_id: [nombres]}`) y cambia la lista al elegir la sede; `data-ambito-nombre="esta sede"` ajusta el texto del aviso genérico. El lector puede devolver dos llaves con la misma nomenclatura (de sedes distintas): muestra las dos para elegir.
- **LL-02 · Lugares en cascada** (como las píldoras de `llave_lista.php`): en Piso, píldoras de edificios (`data-pildora-edificio`); en Área específica, edificios y luego pisos (`data-pildora-piso data-edificio`). Cada lugar lleva `data-edificio` y `data-piso`. Sin píldoras marcadas se ve todo lo de la sede; marcadas, se suman (unión); lo ya marcado nunca se oculta. El servidor no cambia: sigue revalidando cada lugar contra sede y nivel. Bloque "Ajustes Ronda 5" en `plataforma.js`.
- **LL-05 · Costo de reposición**: columnas `costo_reposicion` (decimal, opcional) y `costo_variable` (bool). `Llave::costoFijo()` devuelve el costo cuando no es variable: en la baja con cobro el monto se fuerza a ese valor (`AdministradorLlaves::darDeBaja`). Con costo variable el diálogo sugiere `costo_reposicion` y se respeta lo capturado. Sin costo, se sugiere el último cobro del tipo (`Vouchers::costoSugerido`). Botón de baja: `data-costo-fijo`, `data-costo-propio`.
- **LL-04 · Firmas del voucher** en el diálogo de baja (`seguridad/vouchers/_firmas-baja.blade.php`): física (por omisión) o digital. Ver [vouchers.md](vouchers.md#ronda-5-firmas-copias-y-correo).
- **LL-05 · Código e identificación**: botón QR en cada ficha (`componentes.boton-identificacion`) y diálogo común (`componentes.codigo-identificacion`). Al **reactivar**, la redirección lleva `session('identificacion')` y la ficha se abre sola con su QR. Ver [lector.md](lector.md#código-e-identificación-ronda-5).
- Auditoría: `llaves.creado` / `llaves.actualizado` incluyen `costo_reposicion` y `costo_variable`; `llaves.etiqueta_asignada` / `llaves.etiqueta_quitada` desde el diálogo.

## Qué se corrigió respecto a SEGCAT

| SEGCAT | Ahora |
|---|---|
| QR de las fichas, del diálogo "Ver QR" y de las etiquetas generados en `api.qrserver.com` (fuga de datos, sin internet no hay QR) | QR en SVG generado en el servidor (`bacon/bacon-qr-code`), con la dirección `/e/{código}` aleatorio |
| `codigo_interno` armado con el id consecutivo (`LLV-000012-…`) | `codigo_qr` aleatorio de 24 caracteres, sin datos |
| La edición permitía poner "EXTRAVIADA / ROTA (Baja)" sin voucher | El estado solo cambia con la baja con voucher o con Reactivar |
| Tipo y alcance en catálogos globales sin empresa, interpretados buscando palabras en el nombre ("lectr", "piso"…) | Claves fijas en el modelo |
| Cuatro tablas de relación (`llaves_edificios`, `llaves_pisos`, `llaves_areas_especificas`, `llaves_catalogo_secciones`) | Un pivote a `espacios` (árbol de Zonas y áreas) y otro a secciones |
| Ids de lugares ajenos descartados en silencio | Se rechaza el guardado con un mensaje |
| Horarios incompletos descartados en silencio | Mensaje "Completa el horario…" |
| `llave_imprimir.php` solo exigía `llaves.ver` y filtraba por sesión después de consultar | Permiso `llaves.imprimir` y consulta ya limitada a la empresa y a las sedes del usuario |
| Verificación de nomenclatura por AJAX con `id_empresa` del cliente | Aviso local con la lista de la empresa; la regla real vive en el servidor |
| Responsable buscado solo por nombre | Lector universal: gafete, NFC, QR o número de empleado |
| Bajas sin auditoría y voucher armado a mano en cada módulo | Servicio común de Vouchers (transacción) y auditoría |
| `onclick` en línea y JS inyectado por AJAX en el modal de edición | Sin JS en línea; edición con `data-valores` |
| Sin exportación del catálogo | CSV con los filtros de la pantalla |
