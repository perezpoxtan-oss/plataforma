# Padrón Vehicular

Réplica de `modules/vehiculos` de SEGCAT (`vehiculo_lista.php`, `vehiculo_modal_editar.php`, `vehiculo_proceso.php`, `vehiculo_ticket.php`). Pantalla `/vehiculos` (área Seguridad → Padrones), calcomanía con QR, búsqueda y registro rápido para los módulos que vienen (Bitácora de accesos, Estacionamientos).

- Controlador: `app/Http/Controllers/Seguridad/VehiculoController.php`
- Reglas: `app/Services/Vehiculos/AdministradorVehiculos.php` (validación, alcance, búsqueda, resumen JSON, auditoría)
- Modelo: `app/Models/Vehiculo.php` (migración `2026_10_08_000100_crear_padrones`, sin cambios de esquema)
- Vistas: `resources/views/seguridad/vehiculos/{index,calcomania,_registro-rapido}.blade.php`
- Pruebas: `tests/Feature/Seguridad/VehiculosTest.php`

## Qué se corrigió respecto a SEGCAT

| SEGCAT | Plataforma |
|---|---|
| El QR de la calcomanía se pedía a `api.qrserver.com`: el código salía a un tercero y sin internet la calcomanía no tenía QR. | El QR se dibuja en el servidor, en SVG, con `bacon/bacon-qr-code` (PHP puro, sin `exec`, sin Imagick). Funciona en Neubox y sin internet. |
| El QR contenía `VEH-000123-AB12CD`: consecutivo, adivinable. | `codigo_qr` aleatorio de 24 caracteres; el QR solo lleva la dirección `/vehiculos/qr/{código}`, ningún dato del vehículo ni de personas. |
| Placas limpiadas solo de espacios (`ABC-123` y `ABC123` eran distintas). | Placas normalizadas (mayúsculas, sin espacios, guiones ni puntos) antes de validar y guardar; la unicidad es real. Mensaje claro con el vehículo que ya las tiene y aviso si está dado de baja. |
| El mensaje decía "registradas en cualquier empresa" pero la regla era por empresa. | Únicas por empresa (índice `empresa_id + placas`); la misma placa puede existir en otra empresa. |
| Concatenaba `id_empresa` en SQL y confiaba en `id_empresa` del POST. | Tenant fijo: el filtro de empresa lo pone el modelo; proveedor y colaborador se validan dentro de la empresa. |
| `id_empresa_ext` ajeno se descartaba en silencio. | Error claro: "La empresa propietaria no existe en esta empresa o está desactivada." |
| La flotilla de un proveedor podía guardarse sin proveedor. | Proveedor obligatorio para `empresa_proveedor`; opcional para agencia, taxi y transporte de personal; vacío para propios. |
| El vehículo de un colaborador no se ligaba a él. | `colaborador_id` (solo con "Propio Colaborador"), validado en la empresa; registrado en `AdministradorColaboradores::REFERENCIAS` para la unión de duplicados. |
| Capacidad para cualquier vehículo; descripción "Otro" opcional. | Capacidad (1–99) solo en autobús, camión ligero o transporte de personal; "Describe el tipo" obligatorio si el tipo es Otro. Lo que no aplica se guarda vacío aunque llegue forzado. |
| "Activar" exigía `editar` y "eliminar" `eliminar`; la edición también cambiaba el estatus. | Baja y reactivación con el mismo botón y el permiso `vehiculos.eliminar`; la edición no toca el estado. |
| Sin bitácora de cambios. | Auditoría `vehiculos.creado`, `vehiculos.actualizado`, `vehiculos.desactivado`, `vehiculos.reactivado`. |
| La lista solo mostraba los "propios"; flotillas y taxis vivían en la ficha del proveedor. | Una sola lista con píldoras **Propios / Flotillas / Taxis** y contadores; la ficha del proveedor abre el alta ya prellenada (ver contrato). |
| Mensajes en la URL (`?msg=creado`), `onclick` y JS en línea. | Mensajes en sesión; JS en `plataforma.js` con atributos `data-*`. |

## Permisos y alcance

| Acción | Permiso |
|---|---|
| Ver lista, buscar (`/vehiculos/buscar`), abrir el QR | `vehiculos.ver` |
| Alta (pantalla y registro rápido) | `vehiculos.crear` |
| Editar | `vehiculos.editar` |
| Dar de baja / reactivar | `vehiculos.eliminar` |
| Calcomanía | `vehiculos.imprimir` |

Los vehículos son **de la empresa**, no de una sede: quien tiene `vehiculos.ver` ve todo el padrón con cualquier alcance. `crear` funciona con cualquier alcance. En `editar` y `eliminar`, el alcance **propios** limita a los vehículos que el usuario dio de alta (`creado_por`); sede o empresa = todos. Un vehículo fuera del alcance o de otra empresa responde 404. El Agente (plantilla) solo consulta.

> No se usa `Autorizador::dentroDeAlcance()` con el modelo porque el vehículo no tiene `sede_id`: con alcance de sede negaría todo. La regla vive en `AdministradorVehiculos::limitar()`.

## Rutas

| Método | Ruta | Nombre |
|---|---|---|
| GET | `/vehiculos` | `vehiculos.index` |
| POST | `/vehiculos` | `vehiculos.store` |
| PUT | `/vehiculos/{id}` | `vehiculos.update` |
| PATCH | `/vehiculos/{id}/estado` | `vehiculos.estado` (`activo=0/1`) |
| GET | `/vehiculos/{id}/calcomania` | `vehiculos.calcomania` |
| GET | `/vehiculos/qr/{codigo}` | `vehiculos.qr` → redirige a `/vehiculos#vehiculo-{id}` |
| GET | `/vehiculos/buscar?q=` | `vehiculos.buscar` |
| POST | `/vehiculos/rapido` | `vehiculos.rapido` (30 por minuto) |

Cada ficha tiene `id="vehiculo-{id}"`: el enlace `#vehiculo-12` la resalta y, si había un filtro guardado, se muestran todas.

### Calcomanía y QR

La calcomanía es una página propia (sin menús) con empresa, placas, QR, código legible, marca/modelo, color, tipo, categoría, número económico y empresa propietaria. `@media print` oculta los botones. El QR codifica solo `route('vehiculos.qr', $codigo_qr)`; la ruta exige sesión y `vehiculos.ver` y busca el código dentro de la empresa del usuario (el de otra empresa da 404). **Accesos usará el mismo código** para registrar entradas con el lector.

### Búsqueda — `GET /vehiculos/buscar?q=`

Por placas (desde el inicio, sin importar guiones ni espacios: `abc-12` encuentra `ABC123A`) o por marca, modelo y color (todas las palabras). Mínimo 2 caracteres, máximo 15 resultados, solo activos, solo de la empresa.

```json
{"resultados": [{"id": 12, "placas": "ABC123A", "descripcion": "NISSAN VERSA · BLANCO",
  "propiedad": "taxi_app", "propiedad_etiqueta": "Taxi / App", "numero_economico": "T-045",
  "empresa": "Taxis Cancún", "colaborador": null, "codigo_qr": "…", "activo": true}]}
```

`empresa` es la agencia o empresa propietaria (proveedor), si la hay.

### Registro rápido — `POST /vehiculos/rapido`

Campos: `placas`, `propiedad`, `tipo`, `marca`, `color`, y según el caso `modelo`, `descripcion_otro`, `proveedor_id`, `colaborador_id`, `numero_economico`, `capacidad` (mismas reglas que la pantalla).

- **201** `{"ok": true, "vehiculo": {...}}`
- **409** placas ya registradas (activas o de baja): `{"ok": false, "mensaje": "...", "vehiculo": {...el existente}}`
- **422** `{"ok": false, "mensaje": "...", "errores": {...}}`

Ventana lista para incluir en cualquier pantalla:

```blade
@include('seguridad.vehiculos._registro-rapido')
<button type="button" data-abrir-dialogo="dialogoRegistroRapidoVehiculo">Registrar vehículo</button>
```

Al guardar (o al elegir "Usar …" en el 409) `plataforma.js` lanza en `document` el evento **`vehiculo:registrado`** con `detail` = el objeto `vehiculo`. Solo se muestra a quien tiene `vehiculos.crear` y requiere el Tenant activo.

## Ronda 5 (VE-04): QR en un diálogo

El botón QR de la ficha ya no abre la calcomanía en otra página: abre el diálogo común «Código e identificación» (QR, dirección con Copiar, **Imprimir calcomanía** → `vehiculos.calcomania` si hay `vehiculos.imprimir`, y asignar etiqueta NFC/RFID con `vehiculos.editar`). Detalle en [lector.md](lector.md#código-e-identificación-ronda-5).

## Contrato con la ficha de Proveedor

- `/vehiculos?nuevo=1&proveedor={id}` abre sola el **Alta de Vehículo** con ese proveedor elegido y la categoría según su `categoria`: `taxi` → `taxi_app`, `agencia_autos` → `agencia_renta`, `transporte_personal` y `transporte_huespedes` → `transporte_personal`, cualquier otra → `empresa_proveedor` (constante `AdministradorVehiculos::PROPIEDAD_POR_CATEGORIA`). Lleva el campo oculto `volver=proveedor`. Un proveedor inexistente, inactivo o de otra empresa se ignora.
- `?volver=proveedor` (sin `nuevo`) pone la misma marca en la edición.
- Tras guardar o editar con `volver=proveedor` y un `proveedor_id` válido, regresa a `route('proveedores.show', $id)` si esa ruta existe; si no, a `/vehiculos#vehiculo-{id}`. Nunca se acepta una URL del cliente.

## Componentes de `public/js/plataforma.js` (bloque "Padrón Vehicular")

- **Campos que dependen de otro (genérico):** `data-mostrar-si='{"propiedad":["a","b"],"tipo":["otro"]}'` en un contenedor (se ve si alguna condición se cumple; oculto, sus campos se vacían y deshabilitan) y `data-requerido-si='{...}'` en un campo. Se sincroniza al cargar, al cambiar, al abrir una edición y al cerrar el diálogo.
- Filtro de fichas propio (`data-filtro-vehiculos` + píldoras `data-filtro-tipo="vehiculos"`): el texto también se compara sin guiones ni espacios, para buscar placas como se escriban. Se recuerda en la pestaña.
- Aviso en vivo de placas repetidas (`data-placas-existentes` + `data-aviso-placas`).
- Registro rápido (`data-registro-rapido-vehiculo`) y botón de imprimir (`data-accion="imprimir"`).

## Dependencia nueva

`bacon/bacon-qr-code` ^3.1 (BSD-2-Clause) y su dependencia `dasprid/enum`. Requiere `ext-iconv` (disponible en Neubox). Tras traer la rama: `composer install`.

## Datos demo

`plataforma:demo` crea 13 vehículos la primera vez (`vehiculosDemo()`, protegido por `Vehiculo::exists()`): huéspedes, visitante, familiar, tres de colaboradores demo (#1003, #1006, #1011), dos taxis, una unidad rentada, un autobús de transporte de personal, un camión ligero de flotilla y un carrito de golf dado de baja. Usa los proveedores demo si ya existen (transporte, taxi, agencia); si no, quedan sin empresa propietaria y la flotilla pasa a transporte de personal.

## Altas por verificar

Lo que la caseta registra en este padrón desde Operación (con el registro rápido, o al guardar un ingreso o un movimiento) pasa por dos pasos:

1. Antes de crear, se sugieren los parecidos («¿Es alguno de estos?»).
2. Si quien lo registra no puede editar el padrón, queda «Pendiente de verificar» hasta que alguien lo acepta, lo rechaza o lo une con el existente.

Ver [altas-por-verificar.md](altas-por-verificar.md) y ADR-0006.
