# Proveedores / Empresas Externas

Réplica de `modules/proveedores` de SEGCAT (`proveedor_lista.php`, `proveedor_alta_modal.php`, `proveedor_modal_editar.php`, `proveedor_ficha.php`, `proveedor_proceso.php`). Se conservan los textos ("Empresas Externas", "Registrar Empresa Externa", "Nueva Empresa Externa", "ACTIVA" / "BAJA / VETADA", "N de M sedes"), el color esmeralda (`#10b981`), las píldoras de categoría con sus íconos y las insignias verde (proveedor), azul (agencias y transporte) y naranja (contratista).

Código: `App\Http\Controllers\Padrones\ProveedorController`, `App\Services\Padrones\AdministradorProveedores`, vistas en `resources/views/padrones/proveedores/` (`index`, `show`, `_campos`, `_sedes`, `_alta-rapida`).

## Qué se corrigió respecto a SEGCAT

| SEGCAT | Ahora |
|---|---|
| La casilla "Visible en todas las sedes de la empresa" **no tenía `name`**: `isset($_POST['todas_sedes'])` siempre era falso. Con la casilla marcada (la opción por defecto) la lista de sedes quedaba oculta y vacía, así que el proveedor se guardaba **sin ninguna sede** | `todas_las_sedes` se envía y se valida. Si se desmarca, se exige al menos una sede |
| "Todas las sedes" era una foto de los hoteles que existían ese día (se insertaba una fila por hotel) y se deducía comparando conteos. Una sede nueva no veía a ningún proveedor | Bandera `todas_las_sedes` que incluye las sedes futuras; `proveedor_sede` solo guarda la lista cuando no es "todas" |
| Al editar, `proveedores_hoteles` se **borraba y reconstruía**. Un usuario de sede que editaba quitaba en silencio las sedes de los demás, y se perdían las ligas con sedes desactivadas | `sync()` en transacción. Con alcance de sede solo se marca o desmarca la propia sede; las demás se conservan. Las ligas con sedes desactivadas se conservan |
| No había alcance de sede: la lista mostraba **todos** los proveedores de la empresa y cualquier usuario con `proveedores.editar` editaba o vetaba proveedores que usan otras sedes | Con alcance de sede se ven solo los que operan en sus sedes; los datos y el estado de un proveedor compartido son de toda la empresa (403). Ver *Reglas de alcance* |
| La regla "si ya existe, agrega mi sede" comparaba `razon_social` exacta (sin normalizar espacios) y forzaba todo a MAYÚSCULAS | Se quitan espacios dobles y se compara sin importar mayúsculas; el nombre se guarda como se escribió |
| `tipo_empresa` era un `ENUM` de MySQL y un valor desconocido se convertía en silencio en `PROVEEDOR` | Clave de texto validada contra `Proveedor::CATEGORIAS`; un valor inválido es error de captura |
| RFC: `[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}` sin revisar la fecha | Se valida fecha `AAMMDD` (mes 01–12, día 01–31) y homoclave; se guarda sin espacios ni guiones y en mayúsculas |
| El Super Administrador podía **mover** un proveedor a otra empresa con el campo `id_empresa` de Editar | La empresa sale de la *Empresa de trabajo* y nunca viaja en el formulario |
| El botón **Ficha** solo aparecía con `proveedores.editar`: el Agente, que solo consulta, no podía abrir la ficha | La ficha se abre con `proveedores.ver` |
| Desactivar pedía `eliminar` y reactivar pedía `editar`; además Editar tenía un select de "Estatus en Caseta" que duplicaba el botón | Un solo botón ⊘ / ↺ con `proveedores.eliminar` |
| Alta y edición se cargaban por AJAX como HTML (`fetch('proveedor_modal_editar.php')`), con `onclick`/`onkeyup` en línea y mensajes en la URL (`?error=formato_rfc`) | Diálogos pintados desde el servidor, llenado genérico `data-valores`, sin JS en línea; mensajes en sesión y el diálogo se reabre con lo capturado tras un error |
| El alta rápida JSON (`formato_respuesta=json`) devolvía el proveedor existente **aunque estuviera vetado**, y cualquier error `23000` se reportaba como "duplicado" | `POST /proveedores/rapido`: 201 / 200 / 422; si el existente está dado de baja responde 422 y no se puede usar en un pase |
| Las pestañas Personal y Flotilla de la ficha publicaban directo a `visitante_proceso.php` y `vehiculo_proceso.php` con `id_empresa` oculto | La ficha enlaza a Padrón de personas y Padrón vehicular, que abren su propia alta con el proveedor elegido (ver *Contrato*) |
| HTML inválido (`</dialog>` de más) | Corregido |
| Sin auditoría (solo `creado_por` / `actualizado_por`) | Eventos `proveedores.creado`, `.actualizado`, `.sedes_actualizadas`, `.sede_agregada`, `.desactivado`, `.reactivado` con antes y después |

## Modelo de datos (migración `2026_10_08_000100_crear_padrones`, sin cambios)

- **`proveedores`:** `empresa_id`, `nombre` (150), `categoria` (clave), `rfc`, `telefono` (normalizado), `direccion`, `todas_las_sedes`, `activo` y auditoría. Único `(empresa_id, nombre)`.
- **`proveedor_sede`:** sedes donde opera, solo si `todas_las_sedes = 0`.
- `personas.proveedor_id` y `vehiculos.proveedor_id` forman el personal y la flotilla de la ficha.
- `Proveedor::scopeOperanEn($sedes)`: todas las sedes o alguna de la lista.

## Reglas de alcance

| Quién | Ve | Alta | Datos generales y estado | Sedes donde opera |
|---|---|---|---|---|
| Alcance de **empresa** | Todos | Todas las sedes o lista. Nombre repetido = error "Ya existe «X»…" | Todos | Libre (puede quedar sin sedes, como en SEGCAT) |
| Alcance de **sede** | Los que operan en sus sedes (o en todas) | Solo para sus sedes (aunque pida "todas"). Si el nombre ya existe en la empresa **no se duplica**: se agrega su sede y avisa "Ya existía «X»: se agregó a tu sede" | Solo los que operan **exclusivamente** en sus sedes; los compartidos responden 403 | Solo su casilla; si era "todas" y quita la suya, pasa a lista con las demás. Si deja de operar en su sede, deja de verlo |

Un proveedor de otra empresa o fuera de las sedes del usuario responde 404. La plantilla Agente (Padrones = solo `ver`) consulta lista, ficha y búsqueda.

## Rutas

| Método | Ruta | Permiso |
|---|---|---|
| GET | `/proveedores` | `proveedores.ver` |
| POST | `/proveedores` | `proveedores.crear` |
| GET | `/proveedores/{id}?tab=resumen\|personal\|flotilla` | `proveedores.ver` |
| PUT | `/proveedores/{id}` (datos; `_volver=ficha` regresa a la ficha) | `proveedores.editar` |
| PUT | `/proveedores/{id}/sedes` | `proveedores.editar` |
| PATCH | `/proveedores/{id}/estado` | `proveedores.eliminar` |
| GET | `/proveedores/buscar?q=` | `proveedores.ver` |
| POST | `/proveedores/rapido` | `proveedores.crear` |

### JSON para otros módulos (Pases de salida, Accesos…)

- **`GET /proveedores/buscar?q=texto`**: mínimo 2 letras, máximo 15, solo activos, de la empresa y de las sedes del usuario. Busca en nombre y RFC. Respuesta `{"resultados": [{"id", "nombre", "categoria", "categoria_etiqueta"}]}`.
- **`POST /proveedores/rapido`** (`nombre`, `categoria`, `rfc`, `telefono`, `direccion`, `todas_las_sedes`): `201` creado; `200` si ya existía (`ya_existia: true`; con alcance de sede se agrega su sede); `422` con `{ok:false, mensaje, errores}` por captura inválida o si el existente está dado de baja. Respuesta `{ok, ya_existia, proveedor: {id, nombre, categoria, categoria_etiqueta}}`.
- **Parcial `padrones.proveedores._alta-rapida`**: diálogo `dialogoAltaRapidaProveedor`. Al guardar, `plataforma.js` lanza el evento `proveedor:registrado` en `document` con `detail = {id, nombre, categoria, categoria_etiqueta, ya_existia}`.

## Contrato con Padrón de personas y Padrón vehicular

- **Agregar persona** enlaza a `route('personas.index', ['nuevo' => 1, 'proveedor' => $id])` y **Agregar vehículo** a `route('vehiculos.index', ['nuevo' => 1, 'proveedor' => $id])`. Esas pantallas abren su alta con el proveedor elegido y un `volver=proveedor` oculto; al guardar regresan a `proveedores.show`.
- Cada persona o vehículo de la ficha enlaza a `personas.index#persona-{id}` / `vehiculos.index#vehiculo-{id}`.
- Los botones y enlaces solo aparecen si la ruta existe (`Route::has`) y el usuario tiene `visitantes.crear` / `vehiculos.crear`; las pestañas se ven con `visitantes.ver` / `vehiculos.ver`.

## Pruebas

`tests/Feature/Seguridad/ProveedoresTest.php` (11 pruebas): alta y normalización, validaciones, nombre repetido, sedes y estado, regla "se agregó a tu sede", visibilidad y límites con alcance de sede, ficha con pestañas, JSON de búsqueda y alta rápida, aislamiento entre empresas, Agente solo consulta, Super Administrador.

## Altas por verificar

Lo que la caseta registra en este padrón desde Operación (con el registro rápido, o al guardar un ingreso o un movimiento) pasa por dos pasos:

1. Antes de crear, se sugieren los parecidos («¿Es alguno de estos?»).
2. Si quien lo registra no puede editar el padrón, queda «Pendiente de verificar» hasta que alguien lo acepta, lo rechaza o lo une con el existente.

Ver [altas-por-verificar.md](altas-por-verificar.md) y ADR-0006.

## Ronda 5 de ajustes (parte 2): agregar desde la ficha (PV-05 / PV-06)

«Agregar persona» y «Agregar vehículo» de la ficha siguen usando el contrato `?nuevo=1&proveedor={id}`, ahora con dos mejoras:

- La empresa de la ficha **queda fija** en el alta (sin lista para elegir otra): en Personas la «Empresa que representa» y el tipo que le corresponde; en Vehículos la «Agencia o Empresa Propietaria» y solo las categorías que llevan empresa.
- **Cerrar o Cancelar regresa a la ficha** (pestaña Personal o Flotilla) gracias a `<dialog data-al-cerrar-ir="…">`; antes el usuario quedaba en el Padrón de personas o en el Padrón vehicular. Guardar ya regresaba a la ficha.

## Ronda 6: aviso de duplicado en vivo

La razón social usa el mecanismo único (`data-duplicado` → `GET /proveedores/duplicado`, ver `avisos-duplicado.md`) en el alta, la edición y la ficha; se quitó `data-nombres-existentes`. «Abarrotes del Caribe» ≈ «Abarrotes del Caribe S.A. de C.V.» (no cuentan «S.A.», «S.A. de C.V.», «S. de R.L.», «S.A.P.I.»…). Una desactivada ofrece **Reactivar**. Con alcance de sede, si ya existe se avisa que al guardar solo se agrega a su sede (no se rechaza).
