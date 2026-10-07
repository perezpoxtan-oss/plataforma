# Equipos de seguridad

Réplica de `modules/equipos` de SEGCAT (§4.19 del inventario funcional), **sin Responsivas** (§4.20, va en Operación). Radios, lámparas, detectores, chalecos, botiquines y demás equipo prestable de la guardia, por sede.

## Piezas

| Pieza | Archivo |
|---|---|
| Migración | `database/migrations/2026_10_09_000400_crear_equipos_y_estacionamientos.php` |
| Modelos | `app/Models/Equipo.php` (Identificable), `app/Models/TipoEquipo.php` |
| Reglas | `app/Services/Equipos/AdministradorEquipos.php` |
| Controlador | `app/Http/Controllers/Seguridad/EquipoController.php` |
| Vistas | `resources/views/seguridad/equipos/index.blade.php`, `etiqueta.blade.php` |
| JS / CSS | bloque "Padrones: Equipos de seguridad y Estacionamientos" al final de `public/js/plataforma.js`, `public/css/plataforma.css` y `public/css/modos-pantalla.css` |
| Pruebas | `tests/Feature/Seguridad/EquiposTest.php` |

## Tablas

**`tipos_equipo`** (SEGCAT: `cat_tipos_equipo`): `empresa_id`, `nombre` (único por empresa), `activo`, auditoría y fechas. Se crean al vuelo desde el alta ("+ Nuevo tipo..."); si el nombre ya existe (sin importar mayúsculas) se reutiliza y se reactiva.

**`equipos`** (SEGCAT: `cat_equipos_seguridad`):

| Columna | Notas |
|---|---|
| `empresa_id`, `sede_id` | sede obligatoria (restrict on delete) |
| `tipo_equipo_id` | FK a `tipos_equipo` |
| `marca`, `modelo` | mayúsculas, opcionales |
| `numero_serie` | mayúsculas, sin espacios dobles; **único por empresa** `unique(empresa_id, numero_serie)` |
| `costo` | decimal(10,2), opcional; base del voucher |
| `observaciones` | texto |
| `estado` | `disponible` · `asignado` · `en_mantenimiento` · `baja` |
| `codigo_qr` | 24 caracteres aleatorios (trait `TieneIdentificador`) |
| `etiqueta_nfc` | normalizada; `unique(empresa_id, etiqueta_nfc)` |
| `creado_por`, `actualizado_por`, fechas | |

Se eliminaron de SEGCAT: `categoria_equipo` (siempre `EQUIPO_GUARDIA`; Protección Civil tendrá su catálogo), `id_edificio` e `id_seccion` (ya no se usaban; si hiciera falta, se ligará a Espacios).

## Endpoints

| Método y ruta | Permiso | Qué hace |
|---|---|---|
| `GET /equipos` | `equipos.ver` | Lista (fichas) con filtros |
| `POST /equipos` | `equipos.crear` | Alta (nace DISPONIBLE) |
| `PUT /equipos/{id}` | `equipos.editar` | Edición |
| `POST /equipos/{id}/baja` | `equipos.eliminar` | Baja con voucher → `baja` |
| `PATCH /equipos/{id}/reactivar` | `equipos.eliminar` | `baja` → `disponible` (el voucher se conserva) |
| `GET /equipos/{id}/etiqueta` | `equipos.imprimir` | Etiqueta para imprimir (QR en SVG local) |
| `GET /equipos/{id}/qr` | `equipos.ver` | Imagen SVG del QR para el diálogo "Ver QR" |
| `GET /e/{codigo}` | `equipos.ver` | (lector universal) abre `/equipos#equipo-{id}` |

Todo responde **404** si el equipo es de otra empresa o está fuera del alcance del permiso.

## Permisos y alcance

- Pantalla: `equipos.ver`; el resto según la tabla. Ningún permiso está escrito en las vistas: el motor decide (`$actor->can()`).
- **Alcance de sede**: ve, edita, da de baja e imprime solo equipos de sus sedes; da de alta y cambia de sede solo a sus sedes activas.
- **Alcance propios**: además, solo lo que él dio de alta.
- Plantilla **Agente** (Padrones = solo `ver`): consulta y "Ver QR"; no crea, no edita, no da de baja, no imprime.

## Reglas

- **Estados**: al editar solo se eligen DISPONIBLE o EN MANTENIMIENTO. ASIGNADO lo pone Responsivas y BAJA/PERDIDO solo la baja con voucher; en ambos casos el estado se muestra de solo lectura y se conserva al editar los datos.
- **Gancho para Responsivas**: `AdministradorEquipos::asignarPorResponsiva($actor, $equipo, true|false)` cambia `disponible ↔ asignado` y audita `equipos.asignado` / `equipos.devuelto`. Rechaza equipos en mantenimiento o de baja.
- **Baja con voucher**: usa `Vouchers::validar()` y `Vouchers::darDeBaja(..., 'equipo', $equipo->descripcion(), ..., referenciaCosto: "MARCA|MODELO")`. Monto sugerido = último monto cobrado para esa marca y modelo (`costos_reposicion`) o el costo del equipo. Con cobro, monto y responsable son obligatorios; el responsable se elige con el lector universal (`tipos=colaborador`).
- **Etiqueta NFC/RFID**: se valida contra **todos** los tipos de `config/lector.php` (no solo equipos); el mensaje dice qué registro la tiene.
- **Sugerencias**: `<datalist>` con las marcas y modelos ya usados en la empresa, y costo sugerido al escribir marca y modelo (mapa `MARCA|MODELO → costo`, sin AJAX).
- **Auditoría**: `equipos.creado`, `equipos.actualizado`, `equipos.desactivado` (baja, incluye folio del voucher), `equipos.reactivado`, `equipos.tipo_creado`, `equipos.asignado`, `equipos.devuelto`; más `vouchers.creado` del servicio común.
- **Lector universal**: tipo `equipo`, columna legible `numero_serie`; detalle "Tipo Marca Modelo · ESTADO · Sede".
- Sin N+1: la lista carga tipo y sede con `with()`, trazas con `leftJoin` y el monto sugerido con un solo mapa.

## Qué se corrigió respecto a SEGCAT

- El número de serie era único **en toda la base** (una empresa bloqueaba a otra); ahora es único por empresa y se avisa si el existente está dado de baja.
- El QR de "Ver QR" y de la etiqueta se pedía a `api.qrserver.com` (filtraba el código a un tercero y sin internet no salía); ahora se genera en el servidor (`bacon/bacon-qr-code`).
- El código QR era `PREFIJO-SEDE-consecutivo` (adivinable); ahora es aleatorio y lleva la dirección `/e/{código}`, que también se puede grabar en una etiqueta NFC.
- La edición permitía elegir ASIGNADO a mano (sin responsiva); ahora solo lo pone Responsivas.
- Reactivar exigía `editar` y la baja `eliminar`; ahora ambos son `equipos.eliminar` (como el resto de la plataforma).
- La baja usaba un folio y un insert propios; ahora es el servicio común de vouchers, en una transacción.
- Los filtros se aplicaban por sesión (`id_hotel`) y no por permiso; ahora por el alcance del permiso de cada acción.
- Mensajes de error por URL (`?error=serie_duplicada`); ahora se muestran dentro del diálogo, con el dato que se capturó.
- Se quitó código muerto (`categoria_equipo`, edificio y sección) y JS en línea (`onclick`).

## Ronda 5: diálogo común de QR

El «Ver QR» propio (`dialogoQrEquipo`) se reemplazó por el diálogo común «Código e identificación» (también en Equipos de Protección Civil, Gafetes, Colaboradores y Lost & Found). La ruta `equipos.qr` se conserva. Ver [lector.md](lector.md#código-e-identificación-ronda-5).

## Pendiente / ganchos

- **Responsivas** (Operación, conectado): usa `asignarPorResponsiva()`; la ficha muestra **A cargo de: colaborador · Turno/Fijo · folio** (`Equipo::resguardoActual()` cargado en `index()`). Ver [responsivas.md](responsivas.md). Pendiente: al dar de baja un equipo ASIGNADO, prellenar el responsable (SEGCAT lo hacía con `equipo_responsable_activo_ajax.php`).
- Catálogo de tipos: no tiene pantalla propia (tampoco en SEGCAT); se administra desde el alta.

## Ronda 6

- **EQ-04 (claridad)**: el número nunca va sin su rótulo. `Equipo::resumenLector()['titulo']` es «Serie: 130TXP1568» (lector, «Código e identificación», etiquetas, avisos) y Responsivas muestra «Serie: … · Radio …». Al editar, `[data-explicacion-estado]` explica por qué el estado está limitado: DISPONIBLE (puede pasar a En mantenimiento), EN MANTENIMIENTO (solo Disponible o seguir en mantenimiento), ASIGNADO (lo cambia Responsivas) y BAJA con el folio de su voucher («márcalo Recuperado en Vouchers o Reactivar en su ficha»). El folio sale de `vouchersBaja` (una consulta, el último voucher de cada equipo de baja).
- **Aviso de duplicado en vivo**: el número de serie usa `data-duplicado` (`GET /equipos/duplicado`, ver `avisos-duplicado.md`) en lugar de `data-series-existentes`; la etiqueta NFC avisa «ya la tiene X» al leerla (`identificacion.etiqueta-duplicado`).
- Un equipo de baja con voucher también se reactiva desde Vouchers → **Recuperado** (ver `vouchers.md`).
