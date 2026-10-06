# Padrón de personas

Réplica de `modules/visitantes` de SEGCAT (`visitante_lista.php`, `visitante_modal_editar.php`, `visitante_proceso.php`) y de la búsqueda `modules/accesos/persona_buscar_ajax.php`. Es el registro previo de las personas externas que entran a la empresa: **visitantes** (general, candidato/prospecto de Recursos Humanos, familiar), **personal de proveedores** y **contratistas**.

La pantalla conserva los textos y el color azul de SEGCAT: título «Padrón de Personas», «Registrar Persona», insignias de tipo con sus colores (azul visitante, verde proveedor, naranja contratista, morado prospecto, gris familiar), etiqueta ACTIVO/BAJA, «Viene de:», identificación, motivo, «Editar Perfil», diálogos «Registrar Persona» / «Actualizar Perfil» y los botones «Guardar en Padrón» / «Actualizar Cambios».

## Qué se corrigió respecto a SEGCAT

| SEGCAT | Ahora |
|---|---|
| La lista mostraba el **folio completo** de la identificación (INE, pasaporte, CURP…) a cualquiera con `visitantes.ver`, y el buscador del navegador lo leía del HTML | Quien solo consulta ve `••••5678`; el folio completo solo va en el HTML (ficha, buscador y diálogo) de las personas que el usuario **puede editar** |
| La búsqueda de Accesos (`persona_buscar_ajax.php`) devolvía folio y **teléfono completos** a cualquiera con `accesos.ver`; `LIKE '%$q%'` sin escapar `%` ni `_`; solo por nombre | `GET /personas/buscar`: folio enmascarado (`INE ••••5678`), sin teléfono; comodines escapados; por palabras del nombre o por el inicio del folio |
| Folio "único" solo con `strtoupper`: `ABC 123`, `ABC-123` y `ABC123` eran tres personas distintas y la regla no servía | El folio se guarda **normalizado** (`Persona::normalizarFolio`: mayúsculas, sin espacios, guiones, puntos ni diagonales) y el índice `(empresa_id, folio_identificacion)` es real. Solo letras y números, de 4 a 30 |
| «Ya existe otra persona… con ese folio» sin decir quién; cualquier error SQL 23000 se reportaba como folio duplicado y los demás como «error general» | El mensaje dice **quién** lo tiene («Laura Méndez Ríos» (Visitante), y si está de baja). Validación previa por campo; el índice queda como red de seguridad (dos altas simultáneas → mismo mensaje, no error del sistema) |
| Teléfono texto libre en `varchar(15)`: «+52 998 123 4567» fallaba en SQL y salía como «error general» | Solo dígitos, de 10 a 15; espacios, guiones, paréntesis y `+` se quitan solos |
| El formulario de edición traía **Estatus**: con solo `editar` se daba de baja. Además «activar» exigía `editar` y «eliminar» exigía `eliminar` | Baja y reactivación solo con el botón ⊘ / ↺ y el permiso `visitantes.eliminar`, ambas auditadas. Editar nunca cambia el estado |
| `tipo` era un `enum` que mezclaba tipo y categoría («Prospecto RRHH», «Familiar») y la categoría vivía en otro `enum`; agregar uno exigía alterar la tabla | Claves de texto validadas en la aplicación (`Persona::TIPOS`, `Persona::CATEGORIAS`). La categoría solo distingue visitantes: para proveedor o contratista se guarda `general` |
| El nombre se forzaba a MAYÚSCULAS | Se respeta lo capturado (solo se quitan espacios dobles), igual que en Colaboradores |
| Proveedor y Contratista se habían sacado de la lista (se editaban en la ficha del proveedor con el mismo modal inyectado por AJAX; la acción relativa fallaba desde Proveedores y hubo que parcharla) | Una sola pantalla con los tres tipos y píldoras de filtro. La ficha del proveedor abre el alta aquí con el contrato `?nuevo=1&proveedor={id}` y regresa a su ficha al guardar |
| Modal de edición cargado por `fetch` con HTML y `<script>` en línea; `onclick`, `onkeyup` y `onsubmit` en el HTML | Diálogos nativos en la misma página, llenados con `data-valores`; comportamiento en `plataforma.js` (bloque «Padrón de personas»), sin código en línea |
| `$mi_emp` concatenado en el SQL; el superadministrador elegía «Consorcio Responsable» en el formulario (`id_empresa` del POST); `visitante_modal_editar.php` leía el registro sin filtro de empresa y revisaba después | Filtro de empresa automático (`PerteneceAEmpresa`); la empresa es siempre la de trabajo; otra empresa o fuera de alcance responde **404** |
| Proveedor de otra empresa se descartaba en silencio (`id_empresa_ext = null`) | Se rechaza con mensaje: «El proveedor no existe en esta empresa o está desactivado.» (al editar se conserva uno que ya se desactivó) |
| Sin alcance: con el permiso se editaba a cualquiera | Alcance «propios»: edita y da de baja solo lo que él registró (ver «Alcance») |
| Sin bitácora | Eventos `visitantes.creado/actualizado/desactivado/reactivado`, con folio y teléfono **enmascarados** (últimos 4) |
| `error_log`: `Undefined array key "motivo_visita"` y `trim(null)` en cada baja | Validación con nulos explícitos |
| Fechas «Creado por / Editado por» con `date()` en la hora del servidor | `@fecha()`: hora local de la empresa |
| «Padrón de: Consorcio» en cada ficha | Se quita: la pantalla siempre es de la empresa de trabajo (se muestra en el selector de empresa del superadministrador) |
| Mensajes en la URL (`?msg=creado`, `?error=folio_duplicado`) | Mensajes en sesión; tras guardar se vuelve a la lista con la ficha resaltada (`#persona-{id}`). Con error, el diálogo se reabre con lo capturado y el error **dentro** del diálogo |

## Modelo de datos

Tabla `personas` (migración `2026_10_08_000100_crear_padrones`, compartida con Proveedores y Vehículos; este módulo no cambia el esquema):

- `empresa_id` (obligatorio, sin `sede_id`: las personas son de la empresa), `tipo` (`visitante|proveedor|contratista`), `categoria` (`general|prospecto_rrhh|familiar`), `proveedor_id` (nulo; `nullOnDelete`), `nombre_completo` (150), `empresa_procedencia` (100, texto libre si no es un proveedor registrado), `tipo_identificacion` (`ine|pasaporte|licencia|curp|cedula|id_imss|otra`), `folio_identificacion` (50, normalizado), `telefono` (15, dígitos), `motivo_visita`, `activo`, `creado_por`, `actualizado_por`, timestamps.
- Únicos: `(empresa_id, folio_identificacion)` (los nulos no chocan).

Reglas al guardar (`App\Services\Personas\AdministradorPersonas`):

- Visitante → `proveedor_id = null` (un visitante no representa a un proveedor). Proveedor/contratista → `categoria = general`.
- Con proveedor registrado, `empresa_procedencia = null` (el texto libre sobra).
- Sin folio → `tipo_identificacion = null`. Con folio, el tipo es obligatorio.
- `Persona::empresaQueRepresenta()` = nombre del proveedor o el texto libre; `Persona::folioEnmascarado()` = `INE ••••5678`.

## Permisos y alcance

| Permiso | Para qué |
|---|---|
| `visitantes.ver` | Lista (todo el padrón de la empresa) y `GET /personas/buscar` |
| `visitantes.crear` | Alta y `POST /personas/rapido` |
| `visitantes.editar` | Edición |
| `visitantes.eliminar` | Baja lógica y reactivación |

- **Ver no se acota por alcance:** quien tiene `visitantes.ver` con cualquier alcance ve todo el padrón de su empresa (la caseta necesita reconocer a cualquiera).
- **Editar / dar de baja:** con alcance **empresa** o **sede** (las personas no tienen sede) → todo el padrón; con **propios** → solo las personas que él registró (`creado_por`). Fuera de alcance responde **404**.
- **Crear:** cualquier alcance permite registrar.
- **Folio en la lista:** completo solo en las fichas que el usuario puede editar (y solo entonces entra en `data-texto`, así el buscador de la pantalla encuentra por folio). Para las demás, `••••` + últimos 4 y fuera del buscador.
- **Plantillas:** Administrador (todo, empresa); Jefe de seguridad (todo, sede = todo el padrón); Asistente (ver, crear, editar); Supervisor (todo menos eliminar); Director (solo ver); **Agente: solo ver** (Padrones es consulta para la caseta, QA M-01): ve el folio enmascarado y busca, pero no registra. Si la Bitácora de accesos requiere que la caseta registre personas nuevas, se le da `visitantes.crear` en la Matriz de permisos.
- El aviso de folio repetido muestra el nombre de quien lo tiene porque quien registra también ve el padrón (`visitantes.ver`); sin ese permiso el mensaje no dice el nombre.

## Rutas

| Método y ruta | Nombre | Uso |
|---|---|---|
| `GET /personas` | `personas.index` | Lista con filtros (tipo, categoría, texto) |
| `POST /personas` | `personas.store` | Alta |
| `PUT /personas/{id}` | `personas.update` | Edición |
| `PATCH /personas/{id}/estado` | `personas.estado` | Baja (`activo=0`) o reactivación (`activo=1`) |
| `GET /personas/buscar?q=` | `personas.buscar` | Autocompletar (JSON) |
| `POST /personas/rapido` | `personas.rapido` | Registro rápido (JSON) |

El menú enlaza el módulo `visitantes` («Padrón de personas», menú Padrones) con `CatalogoSeeder::RUTAS['visitantes'] = 'personas.index'`.

### Contrato con la ficha del Proveedor

- `GET /personas?nuevo=1&proveedor={id}` abre el diálogo de alta (`data-abrir-al-cargar`) con el proveedor elegido, el tipo **contratista** si su categoría es `contratista` (si no, **proveedor**), el aviso «Registrando personal de …» y el campo oculto `volver=proveedor`. Un proveedor de otra empresa o desactivado se ignora: el alta se abre sin preselección ni regreso. Sin `visitantes.crear` no hay diálogo.
- Al guardar (alta o edición) con `volver=proveedor` y la persona ligada a un proveedor válido: `redirect()->route('proveedores.show', $proveedorId)` si `Route::has('proveedores.show')`; si no (o sin proveedor), a `personas.index#persona-{id}`. Nunca se acepta una URL del cliente.
- Al cerrar el diálogo sin guardar, `volver` se vacía: si después se abre «Registrar Persona», se queda en el padrón.
- Cada ficha tiene `id="persona-{id}"`: un enlace `/personas#persona-12` la resalta.

### Búsqueda — `GET /personas/buscar`

Permiso `visitantes.ver` (si no, 403). `q`: mínimo 2 caracteres; todas las palabras en el nombre, o el folio por su **inicio** (normalizado: `ekcs-js88` encuentra `EKCSJS88…`). Solo activos, de la empresa de trabajo, máximo 15, por nombre.

```json
{"resultados": [{"id": 9, "nombre_completo": "José Luis Ek Cauich", "tipo": "contratista", "tipo_etiqueta": "Contratista",
  "categoria": "general", "empresa": "Construcciones y Mantenimiento Maya", "proveedor_id": 1, "folio": "INE ••••H800", "activo": true}]}
```

### Registro rápido — `POST /personas/rapido`

Permiso `visitantes.crear`. Mismos campos y reglas que el alta: `tipo`, `categoria`, `nombre_completo`, `proveedor_id`, `empresa_procedencia`, `tipo_identificacion`, `folio_identificacion`, `telefono`, `motivo_visita`.

- **201:** `{"ok": true, "persona": {…resumen…}}`
- **409** (el folio ya lo tiene alguien de la empresa): `{"ok": false, "mensaje": "Ya existe otra persona… «Laura Méndez Ríos» (Visitante).", "persona": {…resumen de quien lo tiene…}}`. La caseta usa a esa persona en vez de duplicarla (si `activo` es falso, hay que reactivarla en el padrón).
- **422** (siempre JSON): `{"ok": false, "mensaje": "primer error", "errores": {"campo": ["…"]}}`

**Usarlo desde otro módulo** (Bitácora de accesos):

```blade
@include('seguridad.personas._registro-rapido', ['proveedorSugerido' => $proveedorId])
<button type="button" data-abrir-dialogo="dialogoRegistroRapidoPersona">Registrar persona</button>
```

El parcial solo se dibuja con `visitantes.crear` y empresa de trabajo. Al guardar (o al elegir «Usar a esta persona» tras un 409), `plataforma.js` cierra el diálogo y lanza en `document` el evento **`persona:registrada`** con el resumen en `detail`:

```js
document.addEventListener('persona:registrada', function (e) { /* e.detail.id, e.detail.nombre_completo, e.detail.folio… */ });
```

## Auditoría

`visitantes.creado`, `visitantes.actualizado` (antes y después), `visitantes.desactivado`, `visitantes.reactivado`. La foto lleva tipo, categoría, nombre, proveedor, procedencia, tipo de identificación, motivo y estado; `folio_identificacion` y `telefono` van enmascarados (`Colaborador::enmascarar`: `**************M700`).

## Componentes

- Vista `resources/views/seguridad/personas/index.blade.php`; parcial `_registro-rapido.blade.php`.
- Controlador `App\Http\Controllers\Seguridad\PersonaController`; reglas en `App\Services\Personas\AdministradorPersonas`; `FolioDuplicado` (es un `ValidationException` que además lleva a la persona existente, para el 409).
- Filtros con el filtro genérico de fichas (`data-fichas="personas"`): texto (`data-filtro-texto`), tipo (`data-filtro-tipo`, píldoras) y **categoría** usando el selector genérico `data-filtro-sede` (las personas no tienen sede; cada ficha lleva su categoría en `data-sede`, vacía para proveedor y contratista).
- `plataforma.js`, bloque «Padrón de personas»: muestra la categoría solo para visitantes, «Empresa que representa» solo para proveedor/contratista y «Empresa de procedencia» si no se eligió un proveedor; al elegir un proveedor el tipo sigue a su categoría; al cerrar limpia `volver`; registro rápido.
- CSS: bloque «Padrón de personas» al final de `plataforma.css` y sus ajustes Sol/Noche en `modos-pantalla.css`.

## Datos demo

`plataforma:demo` crea, solo la primera vez (`Persona::exists()`), 12 personas en Hotel Demo con folios ficticios de formato realista: 6 visitantes (2 generales, 2 candidatos/prospectos, 1 familiar y 1 de baja: Raúl Domínguez Can), 3 de proveedores y 3 de contratistas. Si existen los proveedores demo (por nombre o, si no, el primero activo de la categoría), su personal se liga a ellos; si no, la empresa queda como texto de procedencia. Algunas las registró `jefe.demo` (para probar «Creado por»). `personasDemo()` corre después de los demás datos demo.

## Pruebas

`tests/Feature/Seguridad/PersonasTest.php`: alta con normalización y auditoría enmascarada, folio único por empresa sin importar formato (y permitido en otra), validaciones y reglas de tipo, enmascarado para quien solo consulta (Agente), alcance propios, contrato `?nuevo=1&proveedor=` y regreso con `volver=proveedor` (con y sin la ruta de la ficha), búsqueda y registro rápido (201/409/422), baja y reactivación, aislamiento entre empresas, superadministrador y datos demo.

## Altas por verificar

Lo que la caseta registra en este padrón desde Operación (con el registro rápido, o al guardar un ingreso o un movimiento) pasa por dos pasos:

1. Antes de crear, se sugieren los parecidos («¿Es alguno de estos?»).
2. Si quien lo registra no puede editar el padrón, queda «Pendiente de verificar» hasta que alguien lo acepta, lo rechaza o lo une con el existente.

Ver [altas-por-verificar.md](altas-por-verificar.md) y ADR-0006.
