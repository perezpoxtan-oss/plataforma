# Altas pendientes de verificar (Vehículos, Empresas externas y Personas)

Decisión: [ADR-0006](../decisiones/ADR-0006-altas-pendientes-de-verificar.md). Colaboradores tiene su propio flujo de altas provisionales con Recursos Humanos ([colaboradores.md](colaboradores.md), ADR-0004).

Cuando la caseta, desde **Operación**, necesita un vehículo, una empresa externa o una persona que no está en su padrón:

1. se le sugieren los parecidos («¿Es alguno de estos?»);
2. si no es ninguno, el registro se crea **pendiente de verificar** y se usa de inmediato;
3. quien edita el padrón lo **acepta** (corrigiendo datos), lo **rechaza** con motivo o lo **une** con el registro correcto.

## Piezas

| Pieza | Qué hace |
|---|---|
| `App\Services\Padrones\AltasPorVerificar` | Reglas: padrones, orígenes, parecidos, alta, aceptar / rechazar / unir, alcance de verificación, avisos de Inicio, datos del diálogo |
| `App\Models\Concerns\VerificableEnPadron` | Trait de `Vehiculo`, `Proveedor` y `Persona`: constantes `PENDIENTE`/`VERIFICADO`/`RECHAZADO`, `estaPendiente()`, `estaRechazado()`, `fusionadoEn()`, scope `pendientesDeVerificar()` |
| `App\Http\Controllers\Padrones\AltaPorVerificarController` | Endpoint de parecidos y acciones Aceptar / Rechazar / Unir de los tres padrones |
| `App\Services\Padrones\HayParecidos` | Excepción interna: el registro rápido ya validó, hay parecidos y se revierte la transacción para preguntar |
| `App\Mail\AltaPorVerificarRegistrada` + `correos/alta-por-verificar` | Correo a quien verifica |
| Vistas `padrones/altas-por-verificar/*` | `_insignia` (estado en la ficha), `_pildora` («Pendientes de verificar»), `_boton` («Verificar»), `_dialogo` («Verificar Alta Pendiente»), `_aviso-alta` (aviso en los diálogos de alta rápida) |
| `panel/_altas-por-verificar` | Aviso de Inicio agrupado por padrón |
| `plataforma.js` (bloque «Altas por verificar») | «¿Es alguno de estos?» en las altas rápidas y en el Registro Inteligente de Ingreso; píldora y diálogo de verificación |

## Tablas

Migración `2026_10_12_000300_altas_pendientes_de_verificar`. Columnas nuevas en `vehiculos`, `proveedores` y `personas`:

| Columna | Tipo | Uso |
|---|---|---|
| `verificacion` | string(12), por omisión `verificado` | `pendiente` · `verificado` · `rechazado`. Lo que ya existía queda `verificado` |
| `verificado_por` | id de usuario (sin llave foránea, como `creado_por`) | Quién aceptó, rechazó o unió |
| `verificado_en` | timestamp (UTC) | Cuándo |
| `motivo_rechazo` | string(255) | Motivo del rechazo, o «Unido con «X»» |
| `fusionado_en_id` | FK a la misma tabla, `nullOnDelete` | Al unir: el registro que lo sustituye |
| `origen_alta` | string(20) | Pantalla de Operación donde nació (`accesos`, `transporte`, `pases_salida`, `lost_found`) |
| `sede_alta_id` | id de sede (sin llave foránea, para no impedir eliminar una sede) | Sede donde se registró |

Índice: (`empresa_id`, `verificacion`). Las columnas se cambian solo con `forceFill` (no son `fillable`).

## Estados y transiciones

| De → a | Acción | Quién | Efecto |
|---|---|---|---|
| (nuevo) → `pendiente` | alta desde Operación | quien **no** puede editar el padrón | se usa de inmediato; auditoría `<permiso>.provisional`; correo |
| (nuevo) → `verificado` | alta desde Operación o desde el padrón | quien puede editar el padrón | normal |
| `pendiente` → `verificado` | **Aceptar** (`PUT …/aceptar`) | `<permiso>.editar` en su alcance | opcionalmente corrige datos con las reglas del padrón (`actualizar`); auditoría `.verificado` (y `.actualizado` si cambió algo) |
| `pendiente` → `rechazado` | **Rechazar** (`PUT …/rechazar`, motivo de 5 a 255) | `<permiso>.editar` | `activo = false`; auditoría `.rechazado` |
| `pendiente` → `rechazado` + `fusionado_en_id` | **Unir** (`PUT …/unir`, `destino_id`) | `<permiso>.editar`; el destino debe estar **activo y verificado**, ser de la empresa y visible para el actor | mueve REFERENCIAS; auditoría `.fusionado` con lo movido |

Transiciones **prohibidas** (422 «Este registro ya no está pendiente de verificar…»):

- aceptar, rechazar o unir algo `verificado` o `rechazado`;
- unir con un destino pendiente, rechazado, dado de baja o consigo mismo.

Un `rechazado` tampoco se **reactiva** (`PATCH …/estado`) ni se **edita** (`PUT /vehiculos/{id}`…): `AltasPorVerificar::exigirNoRechazado()` desde `cambiarEstado()` y `actualizar()` de cada administrador.

`<permiso>` es `vehiculos`, `proveedores` o `visitantes` (Personas).

## Uso en Operación (`paraOperacion`)

Lo que la operación reutiliza pasa por `AltasPorVerificar::paraOperacion($padron, $registro, $campo)`:

- **unido** → devuelve el registro correcto (sigue la cadena, máximo 5);
- **rechazado** → error de captura en `$campo`: ««XTR901C» fue rechazado al verificar el Padrón vehicular (motivo: …). Ya no se puede usar: revisa los datos o avisa a tu supervisor.»

Dónde se aplica:

- `RegistroAccesos`: placas existentes, `persona_id` elegido y empresa externa (por id o por nombre).
- `BitacoraTransporte`: placas existentes y chofer existente.
- Los tres registros rápidos.

Lo que esas pantallas **crean** llama a `registrarAlta($actor, $padron, $registro, $origen, $sedeId)`:

- guarda `origen_alta` y `sede_alta_id`;
- si quien registra no puede editar el padrón, lo deja pendiente, lo audita y manda el correo.

Pantallas que lo hacen:

- **Accesos**: `vehiculo()`, `nuevaPersona()` y `empresaExterna()`, con la sede del ingreso.
- **Transporte**: `vehiculo()` y `chofer()`, con la sede del movimiento.
- **Registros rápidos**: con la única sede del actor en el permiso operativo, si tiene una sola.

## Orígenes y permisos

`AltasPorVerificar::ORIGENES` y `ORIGENES_POR_PADRON`:

| Origen | Permiso operativo | Prefijo de rutas | Padrones |
|---|---|---|---|
| `accesos` | `accesos.crear` | `accesos.` | vehículos, empresas, personas |
| `transporte` | `transporte.crear` | `transporte.` | vehículos, personas |
| `pases_salida` | `pases_salida.crear` | `pases-salida.` | empresas |
| `lost_found` | `lost_found.firmar` | `lost_found.` | personas |

### Los registros rápidos

`POST /vehiculos/rapido`, `/personas/rapido` y `/proveedores/rapido` se permiten con:

- el permiso de alta del padrón (`<permiso>.crear`), o
- el permiso operativo del `origen` enviado.

Los diálogos (`_registro-rapido` / `_alta-rapida`) calculan el origen con el nombre de la ruta que se está mostrando (`origenDeRuta`) y lo mandan en un campo oculto. Si no llega `origen`, se toma la primera pantalla de Operación de ese padrón cuyo permiso tiene el actor. Un `origen` que no corresponde al padrón responde 403.

Las empresas externas tienen dos reglas más:

- Con solo el permiso operativo, la empresa nace en las sedes de ese permiso (`AdministradorProveedores::crear(..., permiso:)`).
- Si ya existía, se usa tal cual: no se le agregan sedes, porque el Agente no modifica el directorio.

Los diálogos se muestran a quien puede usarlos (`puedeAltaRapida`). Las pantallas de Operación encienden sus botones con el permiso operativo (`AccesoController` `persona`, `LostFoundController` `persona`, `PaseSalidaController` `proveedor`).

## Parecidos

`AltasPorVerificar::parecidos($padron, $datos, $sedes = null, $excepto = null, $maximo = 5)`.

Busca en un grupo acotado de candidatos (máximo 300) y compara en PHP:

- **Vehículos** (`placas`):
  - candidatos: mismas 2 primeras letras, mismas 3 últimas o las 3 de en medio;
  - compara sin separadores, con O/Q→0 e I→1;
  - Levenshtein ≤ 1 (≤ 5 caracteres) o ≤ 2, o `similar_text` ≥ 82 %.
- **Personas** (`nombre_completo`, `folio_identificacion`):
  - candidatos: las 3 primeras letras de hasta 3 palabras, o el folio exacto;
  - compara en minúsculas, sin acentos ni signos;
  - mismas palabras en otro orden, una contenida en la otra (2 palabras o más), Levenshtein ≤ 2 o `similar_text` ≥ 82 %;
  - el folio exacto siempre cuenta.
- **Empresas externas** (`nombre`):
  - compara como en personas, quitando la sociedad del final («S.A. de C.V.», «S. de R.L.», «SAPI»…);
  - solo las que operan en `$sedes` (null = todas).

En todos:

- nunca rechazados ni unidos;
- los dados de baja salen con `usable = false` y `estado_texto` «Dado de baja»;
- los pendientes, con «Pendiente de verificar».

Cada resultado es el `resumen` del padrón (el mismo de sus búsquedas) más `titulo`, `detalle`, `activo`, `verificacion`, `estado_texto` y `usable`.

### Dónde se usan

- **Registros rápidos.** Primero se crea y se valida dentro de una transacción. Si hay parecidos y no llegó `confirmar_nuevo=1`, se revierte (`HayParecidos`) y se responde **409** `{ok:false, mensaje, parecidos:[…]}`. Así primero se ven los errores de captura y después los parecidos. Se conservan los 409 de siempre: placas idénticas (`vehiculo`) y folio repetido (`persona`).
- **JavaScript de los registros rápidos.** Antes de enviar consulta `GET /altas-por-verificar/parecidos` (en fase de captura, antes del manejador de cada padrón). Muestra la lista:
  - «Usar» lanza el evento del padrón (`vehiculo:registrado`, `persona:registrada`, `proveedor:registrado` con `ya_existia`);
  - «No es ninguno: registrarlo como nuevo» envía con `confirmar_nuevo=1`.
- **Registro Inteligente de Ingreso.** Si la búsqueda de placas, nombre o empresa dice «sin coincidencias», agrega los parecidos a la misma lista: un toque los elige con el lector universal, la persona o la empresa. La dirección del endpoint va en `[data-alta-sugerencias]` del diálogo.

## Endpoints

| Ruta | Permiso | Qué hace |
|---|---|---|
| `GET /altas-por-verificar/parecidos` (`altas_por_verificar.parecidos`) | `<permiso>.ver` o permiso operativo del `origen`; con `de` (comparar un alta), `<permiso>.editar` | `?padron=vehiculos\|proveedores\|personas` y lo capturado (`placas`, `nombre`, `nombre_completo`, `folio_identificacion` o `q`), o `de={id}` del alta. Con `de` devuelve hasta 8, solo activos y verificados (para unir). Sin `padron` válido: 403. 120 por minuto |
| `PUT /vehiculos/{vehiculo}/aceptar` · `/rechazar` · `/unir` | `vehiculos.editar` | Ver «Estados». JSON `{ok, mensaje, ir}` y aviso en sesión, o redirección a la ficha (`#vehiculo-{id}`) |
| `PUT /proveedores/{proveedor}/aceptar` · `/rechazar` · `/unir` | `proveedores.editar` | Ídem (`#proveedor-{id}`) |
| `PUT /personas/{persona}/aceptar` · `/rechazar` · `/unir` | `visitantes.editar` | Ídem (`#persona-{id}`) |
| `POST /vehiculos/rapido`, `/personas/rapido`, `/proveedores/rapido` | `<permiso>.crear` o operativo (`origen`) | Además: 409 con `parecidos`, `confirmar_nuevo`, alta pendiente |

### Campos que se pueden corregir al aceptar

Los de `CAMPOS_AL_ACEPTAR`:

| Padrón | Campos |
|---|---|
| Vehículos | `placas`, `propiedad`, `tipo`, `marca`, `modelo`, `color` |
| Empresas | `nombre`, `categoria`, `rfc`, `telefono` |
| Personas | `nombre_completo`, `tipo`, `tipo_identificacion`, `folio_identificacion`, `telefono` |

Se completan con lo actual y pasan por `actualizar()` del padrón: mismas validaciones y mensajes. Los errores (422) se muestran dentro del diálogo.

### Alcance de verificación (`limitarVerificacion`)

- **Alcance de empresa:** todo.
- **Alcance de sede:** las altas con `sede_alta_id` en sus sedes o sin sede conocida. En empresas externas, además, las que operan en sus sedes (`AdministradorProveedores::consulta(…, 'proveedores.editar')`).
- **Solo los propios:** las que él registró.

Fuera de su alcance o de otra empresa responde **404**. El destino de una unión debe ser visible para el actor (proveedores: en sus sedes).

## Unir: `REFERENCIAS`

`AltasPorVerificar::REFERENCIAS` lista, por padrón, las columnas `[tabla, columna]` que se cambian al unir:

| Padrón | Columnas |
|---|---|
| Vehículos | `accesos.vehiculo_id`, `movimientos_transporte.vehiculo_id` |
| Empresas externas | `accesos.proveedor_id`, `pases_salida.proveedor_id`, `personas.proveedor_id`, `rutas.proveedor_id`, `vehiculos.proveedor_id` |
| Personas | `accesos.persona_id`, `lost_found_entregas.persona_id`, `movimientos_transporte.chofer_id` |

Además, al unir:

- **Empresas externas:** el registro correcto suma las sedes del alta (si no es «todas las sedes»).
- **Personas:** si el registro correcto no tenía identificación, se queda con la que capturó la caseta (se le quita al alta para respetar el folio único).

La prueba `test_toda_columna_que_apunta_a_vehiculos_proveedores_o_personas_esta_en_referencias` recorre todas las llaves foráneas de la base. Excluye `*.fusionado_en_id` y `proveedor_sede.proveedor_id` y **falla si un módulo nuevo agrega una columna y no la registra**.

## Avisos

- **Inicio** (`PanelController`, bloque «Altas por verificar»): una sola tarjeta, «N altas por verificar», con un botón por padrón que el usuario puede editar y tiene pendientes en su alcance (`pendientesPorPadron`). Lleva a `/<padrón>?verificacion=pendiente`, que enciende la píldora.
- **Lista de cada padrón** (`paraLista`, sin consultas por ficha):
  - quien verifica ve la píldora «Pendientes de verificar N» y el botón **Verificar** en las fichas de su alcance;
  - todos ven la insignia «Pendiente de verificar» o «Rechazado / Unido» con el motivo;
  - las fichas rechazadas no tienen Editar ni Reactivar.
- **Correo** (`AvisosCorreo::altaPorVerificar`):
  - va a los usuarios activos con `<permiso>.editar` que alcanzan la sede del alta;
  - se envía después de responder (`defer`);
  - lleva solo el título, la sede, el origen, quién lo registró y el enlace;
  - se apaga con `alta_por_verificar` (Configuración → Avisos por correo).

## Auditoría

| Evento | Cuándo |
|---|---|
| `vehiculos.provisional`, `proveedores.provisional`, `visitantes.provisional` | Alta pendiente. `despues`: verificacion, origen, sede, título |
| `<permiso>.verificado` | Aceptar |
| `<permiso>.rechazado` | Rechazar, con el motivo |
| `<permiso>.fusionado` | Unir, con el destino y cuántas filas se movieron por tabla |

`<permiso>.creado` / `.actualizado` se siguen registrando como siempre.

## Datos demo

`plataforma:demo` (`altasPorVerificarDemo`, solo la primera vez). Registradas por `agente.demo` en Centro desde la Bitácora de accesos:

- **Vehículos:** `ABC128A` (parecido a `ABC123A`) y `XTR901C` (nuevo).
- **Empresas externas:** «Abarrotes del Caribe S.A. de C.V.» (parecida a «Abarrotes del Caribe») y «Plomería Express Cancún».
- **Personas:** «Laura Mendez Rios» (parecida a «Laura Méndez Ríos») y «Pedro Canul Dzib».
- **Un rechazado:** el vehículo `ZZZ000`, con motivo.

Además, los ingresos y movimientos de transporte demo que registran Agentes dejan sus vehículos y choferes nuevos como pendientes.

## Qué se corrigió respecto a SEGCAT

- SEGCAT (y la primera migración) creaba vehículos, personas y empresas desde la caseta **sin revisión**. Los padrones se llenaban de duplicados con otra escritura. Ahora:
  - se sugieren los parecidos antes de crear;
  - lo nuevo queda pendiente hasta que alguien lo revisa.
- Un duplicado no se podía corregir sin perder historia. **Unir** mueve accesos, movimientos, entregas, personal y flotilla al registro correcto.
- Las placas se comparaban tal cual («ABC-123-A» ≠ «ABC123A»). Ahora se comparan sin separadores y con las confusiones O/0 e I/1.
- El Agente no podía registrar a una persona con identificación ni una empresa nueva desde la caseta: dependía de que alguien la diera de alta en el padrón. Ahora puede hacerlo, sin poder editar los padrones.
- Se avisa a quien administra el padrón (Inicio y correo). Antes nadie se enteraba de lo que crecía.

## Reutilizado por los avisos en vivo (Ronda 5, parte 2)

`similitud()` y `claveNombre()` son públicas: las usa `App\Services\Padrones\AvisoDuplicado` (ver [avisos-duplicado.md](avisos-duplicado.md)). En Transporte, Placas y Chofer consultan `GET /altas-por-verificar/parecidos?origen=transporte` mientras se escribe y muestran «¿Es alguno de estos?» como en Accesos.
