# Avisos de duplicado en vivo

Ronda 5 de ajustes de QA (parte 2; observaciones PE-03, Z-02 y Z-07). Mientras se escribe en un formulario, la pantalla avisa si lo capturado **ya existe** o **se parece** a algo que ya está registrado, sin esperar a pulsar Guardar.

Es **un solo mecanismo** para todos los padrones: un atributo en el campo, un bloque de `plataforma.js` y un endpoint JSON pequeño por módulo que responde siempre con el mismo formato.

## Cómo se usa en una vista

```blade
<input type="text" name="folio_identificacion"
       data-duplicado="{{ route('personas.duplicado') }}"
       data-duplicado-min="4"
       data-duplicado-aviso="nuevo_pe_aviso_folio">
...
<div class="aviso-duplicado" id="nuevo_pe_aviso_folio" data-aviso-duplicado role="status" aria-live="polite" hidden></div>
```

| Atributo | Para qué |
|---|---|
| `data-duplicado="URL"` | Endpoint que se consulta (obligatorio). |
| `data-duplicado-campo` | Lo que se manda como `campo`. Por omisión, el `name` del campo. |
| `data-duplicado-con="nivel,padre_id,sede_id"` | Otros campos del mismo formulario que se mandan. Si cambian, se vuelve a revisar. |
| `data-duplicado-min` | Letras mínimas para preguntar (por omisión 2). |
| `data-duplicado-aviso="id"` | Contenedor del aviso. Por omisión se crea uno justo debajo del campo. Úsalo cuando el campo está en una columna angosta, para que el aviso ocupe toda la fila. |

El bloque JS (al final de `plataforma.js`, «Ronda 5 de ajustes (parte 2)»):

- Espera 400 ms después de la última tecla y manda `GET URL?campo=…&valor=…[&excluir=ID][&…]`.
- En una edición manda `excluir` con el id del registro (lo toma de `[data-campo-dialogo]` = `editar-ID`), para que no avise de sí mismo.
- Solo pinta la respuesta más nueva y solo si el texto no cambió mientras tanto.
- Si lo que existe está **dado de baja** y la respuesta trae `reactivar`, muestra el botón **Reactivar**: envía `PATCH {reactivar}` con `activo=1` y el `_token` del mismo formulario.
- Al cerrar el diálogo se borra el aviso. En un diálogo que regresó con errores (`data-abrir-al-cargar`) se vuelve a revisar al cargar.
- Sin respuesta (sin red, 403…) no muestra nada: el servidor vuelve a revisar al guardar.

## Formato de la respuesta

Servicio `App\Services\Padrones\AvisoDuplicado`:

```json
{ "estado": "existe", "mensaje": "Ya existe «Torre Jardín» en este mismo lugar, pero está desactivado. ¿Lo reactivas en lugar de crearlo de nuevo?",
  "coincidencias": [ { "titulo": "Torre Jardín (TJ)", "detalle": "Desactivado", "inactivo": true, "reactivar": "https://…/espacios/20/estado" } ] }
```

| `estado` | Significa | Se ve |
|---|---|---|
| `existe` | El servidor lo rechazará al guardar (o ya está en la lista). | Caja roja. |
| `parecido` | Solo es un aviso: puede ser el mismo. | Caja amarilla. |
| `libre` | No hay nada igual ni parecido. | «Disponible.» en verde. |
| `nada` | Muy poco texto, o falta el lugar (sede): no se revisa. | Nada. |

`reactivar` solo viene si el registro está inactivo **y** quien pregunta tiene el permiso de baja/reactivación del módulo.

### Cómo se compara

Se reutilizan las reglas de «¿Es alguno de estos?» de las altas por verificar (`AltasPorVerificar::similitud()` y `claveNombre()`, ahora públicas; ADR-0006):

- `clave()`: mayúsculas, sin acentos, espacios, guiones ni signos. **«TB» = «T-B» = «T B» = «tb»**.
- `seParecen()`: iguales sin espacios ni signos, a 1–2 letras de distancia, mismas palabras en otro orden o una contenida en la otra.

## Endpoints

| Ruta | Permiso | Campos |
|---|---|---|
| `GET /personas/duplicado` (`personas.duplicado`) | `visitantes.crear` o `visitantes.editar` | `folio_identificacion` (exacto, normalizado como al guardar: **existe**), `nombre_completo` (parecidos: **parecido**) |
| `GET /espacios/duplicado` (`espacios.duplicado`) | `espacios.crear` o `espacios.editar` | `nombre` (igual en el mismo lugar: **existe**; parecido: **parecido**), `codigo` (misma clave en la misma sede y nivel: **parecido**), `tipo` (tipo propio ya en la lista: **existe**), `seccion` (misma sede: **existe**) |

Las dos rutas llevan `throttle:120,1`, están en el bloque `// Padrones: Ajustes Ronda 5 (parte 2)` de `routes/web.php` y siempre:

- Corren con la empresa de trabajo (`Tenant::conEmpresa`): lo de otra empresa no existe.
- En Zonas y áreas solo miran las **sedes visibles** del usuario (`espacios.ver`); un contenedor (`padre_id`) o un registro (`excluir`) de otra sede responde `nada`.
- En Personas el folio nunca viaja completo: el detalle usa `folioEnmascarado()` (••••1234).

## Dónde se usa

| Pantalla | Campo | Aviso |
|---|---|---|
| Padrón de personas (alta y edición) | Nombre completo | «Ya hay personas con un nombre parecido…» |
| Padrón de personas (alta y edición) | Folio / Número | «Ese folio ya está registrado en la empresa (los espacios y guiones no cuentan)…» |
| Zonas y áreas (todas las altas y ediciones) | Nombre | «Ya existe «X» en este mismo lugar» · «… pero está desactivado. ¿Lo reactivas…?» · «Se parece a «Torre A (TA)»» |
| Zonas y áreas (zonas / edificios) | Código | «Se parece a «Torre A» (TA): los espacios y guiones no cuentan…» |
| Zonas y áreas → detalle | ¿No encuentras el tipo…? | ««Cama» ya existe en la lista: no hace falta agregarlo…» |
| Zonas y áreas → Secciones | Nombre de la sección | «Ya existe la sección «X» en Hotel Demo Centro.» |

### Ronda 6: los demás padrones pasan al mismo mecanismo

Proveedores, Vehículos, Colaboradores, Gafetes, Equipos y Equipos de Protección Civil dejaron sus avisos propios (`data-nombres-existentes` en Proveedores, `data-placas-existentes`, `data-numeros-existentes`, `data-series-existentes` y la caja de homónimos `data-homonimos` del alta de Colaboradores). Ahora usan `data-duplicado`, con `App\Http\Controllers\Padrones\DuplicadoController` (bloque `// Padrones: Ajustes Ronda 6` de `routes/web.php`, `throttle:120,1`):

| Ruta | Permiso (cualquiera) | Campo | existe | parecido |
|---|---|---|---|---|
| `GET /proveedores/duplicado` | `proveedores.crear` / `.editar` | `nombre` | mismo nombre (sin mayúsculas ni espacios dobles). Con alcance de sede **no** se rechaza: «al guardar no se crea otra, solo se agrega a tu sede» (sale como parecido) | sin «S.A. de C.V.», «S. de R.L.», «S.A.P.I.»… ni signos (`AltasPorVerificar::parecidos('proveedores')`) |
| `GET /vehiculos/duplicado` | `vehiculos.crear` / `.editar` | `placas` | mismas placas sin espacios, guiones ni puntos | O/0, Q/0, I/1 y una letra de diferencia (`AltasPorVerificar::parecidos('vehiculos')`). Libre: «Se guardarán como ABC123A.» |
| `GET /colaboradores/duplicado` | `colaboradores.crear` / `.editar` / `.aprobar` | `num_empleado` | mismo número (sin mayúsculas) | — |
| | | `nombre` (+ `apellido_paterno`, `apellido_materno` con `data-duplicado-con`) | — | homónimos (`AdministradorColaboradores::parecidos`) |
| `GET /gafetes/duplicado` | `gafetes.crear` / `.editar` | `nomenclatura` (el folio) | mismo folio | igual sin guiones, espacios, puntos ni diagonales |
| `GET /equipos/duplicado` | `equipos.crear` / `.editar` | `numero_serie` | misma serie (en toda la empresa) | igual sin guiones ni espacios |
| `GET /equipos-pc/duplicado` | `equipos_pc.crear` / `.editar` | `numero_serie` + `sede_id` (`data-duplicado-con`) | mismo ID **en la sede** | igual sin guiones ni espacios, en la sede |
| `GET /identificacion/{tipo}/{id}/etiqueta-duplicado` ({id} = registro que se edita, 0 = alta nueva; de otra empresa o sede: 404) | `<módulo del tipo>.crear` / `.editar` | `etiqueta_nfc` | la tarjeta ya la tiene otro registro de **cualquier** tipo del lector: «Esa tarjeta o etiqueta ya la tiene la llave «HDC-101»…» | — |

Reglas comunes:

- **Inactivo con Reactivar**: si lo que existe está dado de baja y quien captura puede reactivarlo (`proveedores.estado`, `vehiculos.estado`, `colaboradores.estado`, `gafetes.reactivar`, `equipos.reactivar`, `equipos_pc.reactivar`), la coincidencia trae `reactivar`.
- **Otras sedes**: si la coincidencia es de una sede que el usuario no tiene a cargo, se dice que existe «en una sede que no tienes a cargo» **sin** nombre ni datos (Colaboradores, Gafetes, Equipos y la etiqueta NFC). Equipos PC solo revisa sedes del usuario.
- **Edición**: `excluir` (de `[data-campo-dialogo]` = `editar-ID`). En la etiqueta NFC (edición genérica, dirección con id 0), `excluir` debe ser un registro del tipo que el usuario puede editar (`Identificacion::buscar`); si no, responde `nada`.
- **GV-03 (etiqueta NFC en vivo)**: el lector en modo capturar acepta `'duplicado' => route('identificacion.etiqueta-duplicado', ['gafete', 0])` y pinta el aviso debajo de sí mismo. Lo leído con el NFC del celular o el Enter del lector USB dispara la revisión (evento `lector:capturado`). En el diálogo «Código e identificación» la dirección y el registro los pone el JS al abrir (`duplicadoUrl` en `data-ver-identificacion`, con el tipo y el id del registro en la dirección). Ahí, lo leído con el lector se guarda en el acto, así que el resultado lo da el propio diálogo; lo tecleado se avisa mientras se escribe.
- Los campos de `data-duplicado-con` vuelven a revisar **mientras se escribe** en ellos (no solo al salir del campo): bloque «Ajustes Ronda 6» de `plataforma.js`.

### Ronda 7: los últimos padrones

Llaves, Departamentos, Puestos, Turnos y Usuarios dejaron sus avisos propios (`data-nombres-existentes`, `data-nombres-por-sede`, `data-aviso-nombre` y la caja de homónimos `data-homonimos` de Usuarios; se quitó la ruta `GET /usuarios/homonimos`). Ya no queda ningún aviso propio: **todo usa `data-duplicado`**. Controlador `App\Http\Controllers\Padrones\DuplicadoCatalogosController`, bloque `// Padrones: Ajustes Ronda 7` de `routes/web.php` (`throttle:120,1`):

| Ruta | Permiso | Campo | existe | parecido |
|---|---|---|---|---|
| `GET /llaves/duplicado` | `llaves.crear` / `.editar` | `nomenclatura` + `sede_id` (`data-duplicado-con`) | mismo nombre **en esa sede** (de baja: «Reactivar» → `llaves.reactivar`) | igual sin guiones ni espacios, en la sede. Sin sede o sede ajena: `nada` |
| `GET /departamentos/duplicado` · `/puestos/duplicado` · `/turnos/duplicado` | `X.crear` / `.editar` **con alcance de empresa** (catálogos de toda la empresa; si no, 403) | `nombre` | mismo nombre sin mayúsculas (desactivado: «Reactivar» → `X.estado`) | `seParecen()` (acentos, una letra, orden) |
| `GET /usuarios/duplicado` | `usuarios.crear` / `.editar` | `username`, `email` | ya lo usa otra cuenta (único en **toda** la plataforma; de otra empresa, de una sede ajena o el Super Administrador: sin datos). Inactiva: «Reactivar» → `usuarios.estado` | `username` igual sin puntos ni guiones (`d.canul` ≈ `dcanul`) |
| | | `name` (+ `colaborador_id`) | — | homónimos (`HomonimosUsuarios`) y colaboradores con ese nombre **sin cuenta** |

La etiqueta NFC de Llaves ya usaba el aviso único desde la Ronda 6 (`identificacion.etiqueta-duplicado`).

**Usuarios, extras**: la respuesta de `name` trae `requiere_confirmacion` y, en cada colaborador sin cuenta, `vincular: {id, num_empleado, nombre_completo}`. El bloque genérico emite el evento **`duplicado:pintado`** (`detail` = la respuesta) después de pintar el aviso —una sola línea agregada al bloque de la Ronda 5—; el bloque «Ajustes Ronda 7» de `plataforma.js` lo usa para agregar el botón **Vincular** (llena el número, el colaborador y el nombre) y mostrar «Sí, es otra persona con el mismo nombre». El servidor sigue exigiendo esa confirmación al guardar (`confirmar_homonimo`). Otros módulos pueden usar el mismo evento para acciones propias.

El endpoint `GET /colaboradores/homonimos` se conserva (lo usan pruebas). Para un padrón nuevo, usa `data-duplicado`.

## Agregar el aviso a otro módulo

1. Agrega `duplicado(Request $request): JsonResponse` al controlador: `Gate`/`abort_unless` con el permiso de crear o editar, la empresa de trabajo y el alcance de sedes. Responde con `AvisoDuplicado::existe()`, `parecido()`, `libre()` o `nada()`.
2. Agrega la ruta GET con `throttle:120,1` (el recorrido de `tests/Feature/SeguridadAuditoria` la visita sola).
3. Pon `data-duplicado="{{ route('…duplicado') }}"` en el campo.
4. Prueba: existe, parecido, libre, edición (`excluir`), otra empresa, otra sede y un usuario sin permiso (403).

## Pruebas

`tests/Feature/Seguridad/AjustesRonda5bTest.php`; los padrones de la Ronda 6 y la etiqueta NFC, `AjustesRonda6Test.php`; Llaves, Departamentos, Puestos, Turnos y Usuarios, `AjustesRonda7Test.php` y `tests/Feature/Administracion/UsuariosHomonimosTest.php`.

### Candidatos (fase 1: una ficha por persona)

`GET /candidatos/duplicado` (`candidatos.duplicado`, permiso `candidatos.crear`, en `DuplicadoController::candidatos`). En «Nuevo candidato» de RR. HH., los campos **Teléfono celular** (`data-duplicado-min="10"`) y **CURP** (`data-duplicado-min="18"`) preguntan si otra ficha de la empresa ya los tiene (`AdministradorCandidatos::buscarFicha()`). Responde `parecido` (no `existe`): al guardar no se rechaza, se usa la ficha existente. La coincidencia trae `abrir` (dirección de su ficha) solo si está en las sedes del usuario; el bloque JS lo pinta como botón **Abrir su ficha**. Si la ficha es de una sede que no tiene a cargo, el aviso no muestra nombre ni datos.
