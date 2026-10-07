# Inventario de gafetes

Réplica de `modules/gafetes` de SEGCAT (`gafete_lista.php`, `gafete_modal_editar.php`, `gafete_proceso.php`, `gafete_imprimir.php`, `gafete_costo_sugerido_ajax.php`). Pantalla `/gafetes` (Seguridad → Padrones → Inventarios de Seguridad).

- Controlador: `app/Http/Controllers/Seguridad/GafeteController.php`
- Reglas: `app/Services/Gafetes/AdministradorGafetes.php` (lote y nomenclatura, tipos, edición, baja con voucher, reactivación, alcance, auditoría)
- Modelos: `app/Models/Gafete.php` (`Identificable`, lector universal) y `app/Models/TipoGafete.php`
- Migración: `2026_10_09_000310_crear_gafetes`
- Vistas: `resources/views/seguridad/gafetes/{index,imprimir}.blade.php`
- JS: bloque "Padrones: Gafetes y Vouchers de reposición" al final de `public/js/plataforma.js`; CSS: bloque del mismo nombre al final de `public/css/plataforma.css` y `public/css/modos-pantalla.css`
- Pruebas: `tests/Feature/Seguridad/GafetesTest.php`

## Tablas

### `tipos_gafete` (SEGCAT: `cat_tipos_gafete`)

| Columna | Tipo | Notas |
|---|---|---|
| `empresa_id` | FK empresas | scope de tenant |
| `nombre` | string 50 | único por empresa (`empresa_id + nombre`) |
| `activo` | bool | |
| `creado_por`, `actualizado_por`, timestamps | | auditoría |

La primera vez que alguien con `gafetes.crear` o `gafetes.editar` abre la pantalla (o al correr el demo) se crean **Visitante, Proveedor y Contratista**, como la semilla de SEGCAT. En el lote y en la edición, "**+ Nuevo tipo...**" (`tipo_gafete_id=__nuevo__` + `nombre_tipo_nuevo`) crea un tipo nuevo; si ya existe uno con ese nombre (sin importar mayúsculas) se reutiliza. Auditoría `gafetes.tipo_creado`.

### `gafetes` (SEGCAT: `gafetes`)

| Columna | Tipo | Notas |
|---|---|---|
| `empresa_id` | FK empresas | scope de tenant |
| `sede_id` | FK sedes (restrict) | el gafete es de una sede |
| `tipo_gafete_id` | FK tipos_gafete (restrict) | |
| `nomenclatura` | string 50 | `HOT-CEN-VIS-001`; **única por empresa** |
| `consecutivo` | int | el número con el que se generó |
| `codigo_qr` | string 32, único | aleatorio (24), va en el QR impreso (trait `TieneIdentificador`) |
| `etiqueta_nfc` | string 64, null | número de serie del chip o tarjeta, normalizado; único por empresa |
| `activo` | bool | `false` = **NO DISPONIBLE** (extraviado, dañado o robado) |
| `creado_por`, `actualizado_por`, timestamps | | trazas "Creado por / Editado por" |

## Reglas

### Generar lote — `POST /gafetes/lote`

Campos: `sede_id`\*, `tipo_gafete_id`\* (o `__nuevo__` + `nombre_tipo_nuevo`), `cantidad`\* (1 a 50; el tope se valida en el servidor).

- La sede debe estar activa, ser de la empresa y estar dentro de las sedes del usuario para `gafetes.crear`.
- **Nomenclatura**: `<3 letras de la empresa>-<código de la sede>-<3 letras del tipo>-<consecutivo de 3 dígitos>`, en mayúsculas y sin acentos (`Str::ascii`): "Hotel Ébano", sede `CEN`, tipo "Visitante" → `HOT-CEN-VIS-001`. Ver `AdministradorGafetes::prefijo()` y `siglas()`.
- **Consecutivo**: sigue al mayor número que ya exista **con el mismo prefijo** (aunque el gafete esté de baja o sea de otro tipo que empiece con las mismas tres letras: "Proveedor" y "Promotor" comparten `PRO`), así nunca se repite una nomenclatura. Pasa de 999 a 1000 sin romperse.
- Todo el lote se crea en una transacción. Auditoría `gafetes.lote` (sede, tipo, cantidad, desde, hasta).

### Editar — `PUT /gafetes/{id}`

Nomenclatura (mayúsculas, espacios dobles fuera; letras, números, `-`, `.`, `/`), tipo (o tipo nuevo) y **Etiqueta NFC / RFID (opcional)** con el lector universal en modo `capturar`. La nomenclatura es única **por empresa**; la etiqueta se normaliza y se valida con `Gafete::etiquetaOcupada()`: el mensaje dice qué gafete la tiene. El código interno (QR) se muestra fijo. Auditoría `gafetes.actualizado` (solo si algo cambió).

### Dar de baja con voucher — `POST /gafetes/{id}/baja`

Usa el servicio común `App\Services\Inventarios\Vouchers` (no se reimplementa):

1. `Vouchers::validar()`: **Motivo** (Extraviado, Dañado, Robado), **¿Cómo pasó?**, **Aplica CXC**; con cobro, **Monto** y **Colaborador Responsable** (de la empresa) son obligatorios.
2. `Vouchers::darDeBaja($actor, $gafete, 'gafete', $nomenclatura, $datos, fn () => …activo=false…, referenciaCosto: <nombre del tipo>)`: en una transacción marca la baja (auditoría `gafetes.desactivado`), crea el voucher `VR-aamm-nnnnn` (auditoría `vouchers.creado`) y recuerda el costo por tipo.
3. El monto se sugiere con `Vouchers::costoSugerido('gafete', <tipo>)`: el último cobrado para ese tipo de gafete. Se calcula al cargar la lista (uno por tipo, sin consultas por ficha) y va en `data-valores` del botón.
4. Al terminar, la lista muestra el aviso con el folio y el botón **Imprimir voucher** (si el usuario tiene `vouchers.imprimir`).

Un gafete ya dado de baja no se vuelve a dar de baja (mensaje claro, sin voucher nuevo). El responsable se elige con el lector universal (`tipos=colaborador`): número de empleado, credencial con QR o tarjeta NFC/RFID.

### Reactivar — `PATCH /gafetes/{id}/reactivar`

Vuelve a **DISPONIBLE** (por ejemplo, apareció). El voucher se conserva. Auditoría `gafetes.reactivado`.

### Imprimir — `GET|POST /gafetes/imprimir` con `gafetes[]=id`

"Impresión Doble Vista (Libro)": cada gafete mide 172 × 54 mm, frente y reverso lado a lado para doblar por la línea punteada.

- **Frente**: franja de color por tipo (Visitante naranja, Proveedor azul, cualquier otro gris, como SEGCAT), empresa, "Sede: …", tipo en grande y logo.
- **Logo**: `empresas.logo_ruta` si existe un archivo en `public/`; si no, el símbolo de la Identidad de la plataforma; si no, el recuadro "Espacio Logo" de SEGCAT. El logo se sube en Empresas (ver [empresas-y-sedes.md](empresas-y-sedes.md#logo-de-la-empresa)).
- **Reverso**: QR, nomenclatura y reglas ("Portar en lugar visible…"). Los dados de baja llevan "GAFETE DADO DE BAJA".
- El QR codifica **solo** `route('lector.ir', $codigo_qr)` (`/e/{código}`) y se dibuja en el servidor en SVG con `bacon/bacon-qr-code` (igual que la calcomanía vehicular). No se usa `api.qrserver.com`.
- POST desde "Marcar todos" + "Imprimir" (las casillas usan `form="formImprimirGafetes"` para no anidar formularios); GET desde el botón de impresora de cada ficha. Sin nada marcado: aviso en la lista, sin abrir una pestaña vacía. Máximo 500 por impresión. Los ids fuera de la empresa o del alcance se descartan (si no queda ninguno: 404).

## Lector universal

`Gafete` implementa `Identificable` con `$columnaLegible = 'nomenclatura'` y está en `config/lector.php` (`'gafete' => Gafete::class`). El lector lo encuentra por:

- el QR impreso (`/e/{código}`) → abre `/gafetes#gafete-{id}` (la ficha se resalta);
- la etiqueta NFC/RFID en cualquier formato (hexadecimal, decimal de 10 dígitos, bytes invertidos);
- la nomenclatura tecleada o leída con un lector de código de barras.

`resumenLector()`: título = nomenclatura, detalle = "Tipo · Sede", `sede_id`. Permiso `gafetes.ver`.

### Gancho "EN SITIO" (Bitácora de accesos)

En SEGCAT un gafete solo se presta si está activo **y no está EN SITIO** (prestado a una visita o a un acompañante que sigue dentro). Esa información vendrá de la Bitácora de accesos cuando se migre. El punto único para esa regla es `Gafete::disponibleParaAsignar()`: hoy devuelve `activo`; Accesos debe agregar ahí la condición "sin acceso abierto con este gafete" y usar ese método (o un scope equivalente) al ofrecer gafetes en caseta.

## Permisos y alcance

| Acción | Permiso |
|---|---|
| Ver la lista, encontrarlo con el lector | `gafetes.ver` |
| Generar lote (y crear tipos ahí) | `gafetes.crear` |
| Editar (y crear tipos ahí) | `gafetes.editar` |
| Dar de baja con voucher / reactivar | `gafetes.eliminar` |
| Imprimir gafetes | `gafetes.imprimir` |
| Botón "Imprimir voucher" tras la baja | `vouchers.imprimir` |

El gafete **es de una sede**: con alcance de **sede** el usuario solo ve, genera, edita, da de baja e imprime gafetes de sus sedes; con alcance **propios**, solo los que él generó (dentro de sus sedes); con alcance de **empresa**, todos. La regla vive en `AdministradorGafetes::limitar()`; un gafete fuera del alcance o de otra empresa responde **404**. El Super Administrador elige la empresa de trabajo. El **Agente** (plantilla) solo consulta (Padrones = solo `ver`).

## Rutas

| Método | Ruta | Nombre |
|---|---|---|
| GET | `/gafetes` | `gafetes.index` |
| POST | `/gafetes/lote` | `gafetes.lote` |
| GET, POST | `/gafetes/imprimir` | `gafetes.imprimir` |
| PUT | `/gafetes/{id}` | `gafetes.update` |
| POST | `/gafetes/{id}/baja` | `gafetes.baja` |
| PATCH | `/gafetes/{id}/reactivar` | `gafetes.reactivar` |

## Pantalla

- Fichas con nomenclatura, casilla para imprimir (44 px), Tipo, Sede, código interno, etiqueta NFC si tiene, traza "Creado por / Editado por" con `@fecha`, estado **DISPONIBLE / NO DISPONIBLE** y botones imprimir, editar, dar de baja o reactivar (según permiso y alcance).
- Filtros (genéricos de `plataforma.js`, se recuerdan en la pestaña): píldoras por tipo con conteo, sede, estado (Todos / Disponibles / No disponibles) y búsqueda por nomenclatura, tipo, sede, etiqueta o código.
- **Marcar todos** marca solo lo que se ve con el filtro actual; el botón **Imprimir** muestra cuántos van marcados.
- Diálogos: Generar Gafetes, Editar Gafete y Dar de Baja. Los errores se muestran dentro del diálogo, que se reabre solo con lo capturado.

## Qué se corrigió respecto a SEGCAT

| SEGCAT | Plataforma |
|---|---|
| La nomenclatura se revisaba única **contra todas las empresas** (y el índice no existía en la base). | Única **por empresa** (índice `empresa_id + nomenclatura`). |
| El consecutivo seguía al máximo de `id_hotel + id_tipo_gafete`: dos tipos con las mismas tres letras ("Proveedor", "Promotor") generaban nomenclaturas repetidas. | Sigue al máximo del **prefijo** completo: nunca repite. |
| Las tres letras se cortaban con `substr` sin quitar acentos (`ÉBA`, o letras partidas en UTF-8). | Mayúsculas y sin acentos (`Str::ascii`), solo letras y números. |
| Código interno `GAF-<id>-xxxxxx`: expone el consecutivo. | `codigo_qr` aleatorio de 24 caracteres; el QR solo lleva `/e/{código}`. |
| QR (lista, ventana y gafete impreso) generado en `api.qrserver.com`: el código salía a un tercero y sin internet no había QR. | QR en SVG generado en el servidor (`bacon/bacon-qr-code`). |
| Baja, activar y voucher repetidos en el módulo; dar de baja y reactivar exigían `editar`. | Servicio común `Vouchers` (transacción + auditoría); baja y reactivación con `gafetes.eliminar`, como el resto de la plataforma. |
| El monto del voucher era de solo lectura y venía de un costo fijo por hotel que se configuraba en otra pantalla. | Monto editable, sugerido con el último costo cobrado para ese tipo de gafete (`costos_reposicion`). |
| El responsable se buscaba solo por nombre. | Lector universal: número de empleado, credencial con QR o tarjeta NFC/RFID. |
| La impresión filtraba los gafetes en PHP después de consultarlos todos (y un usuario de sede podía pedir ids ajenos). | Consulta limitada desde el inicio a la empresa y al alcance. |
| Sin bitácora. | Auditoría `gafetes.lote`, `gafetes.tipo_creado`, `gafetes.actualizado`, `gafetes.desactivado`, `gafetes.reactivado` y `vouchers.creado`. |
| Sin NFC propio: el código interno "se grababa" en una etiqueta. | Columna `etiqueta_nfc` (serie del chip) además del QR; ambos sirven para encontrarlo. |
| `onclick`, mensajes en la URL (`?msg=generados`), filtros en `sessionStorage` propios. | Sin JS en línea; mensajes en sesión; filtros genéricos de la plataforma. |
| "Sede / Hotel". | Siempre "Sede". |

## Ronda 6

- **GV-02**: el máximo del lote se ve antes de capturar («Máximo 50 por lote.» bajo el campo) y el error dice «Máximo 50 gafetes por lote. Si necesitas más, genera otro lote.» (servidor) / «Máximo 50 gafetes por lote.» (navegador, `data-mensaje-max`).
- **Aviso de duplicado en vivo**: la nomenclatura (folio) de la edición usa `GET /gafetes/duplicado` (igual o igual sin guiones/espacios; de otra sede sin datos) y la etiqueta NFC `identificacion.etiqueta-duplicado` («ya la tiene la llave «HDC-101»»), en la edición y en «Código e identificación» (GV-03).
- **GV-04**: un gafete de baja con voucher se reactiva desde Vouchers → **Recuperado**.
