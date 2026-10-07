# Colaboradores

Réplica de `modules/colaboradores` de SEGCAT (y de la búsqueda `modules/usuarios/colaborador_buscar_ajax.php`). La pantalla, los textos y el color esmeralda son los mismos: fichas con iniciales, número de empleado, puesto, departamento, sede, sedes adicionales y la etiqueta ACTIVO/BAJA.

## Qué se corrigió respecto a SEGCAT

| SEGCAT | Ahora |
|---|---|
| `num_empleado`, `curp`, `rfc` y `nss` eran `UNIQUE` en **toda la base**: dos empresas no podían tener al empleado "100". El aviso en vivo revisaba por empresa y decía "Disponible", pero el guardado fallaba | Únicos **dentro de cada empresa** (`(empresa_id, num_empleado)`, etc.). El aviso en vivo y la validación al guardar usan la misma regla |
| El duplicado se detectaba atrapando el error SQL 23000 y buscando el nombre del índice en el mensaje (`strpos($msg, "'curp'")`) | Validación previa con mensajes por campo; el índice único queda como red de seguridad |
| Sede, puesto y departamento llegaban del navegador sin revisar que fueran de la misma empresa (`id_hotel`, `id_puesto`, `id_departamento` sin validar en alta ni edición); `id_empresa`/`id_hotel` sin llave foránea | Llaves foráneas reales y validación: la sede debe ser de la empresa y estar activa; el departamento debe aplicar en esa sede (`Departamento::aplicanEn`); el puesto debe aplicar en el departamento (si está ligado a departamentos) |
| CURP, RFC, NSS, nacimiento y dirección los veía y cambiaba cualquiera con `colaboradores.editar`; la lista cargaba `SELECT c.*` | Acción propia **`colaboradores.datos_personales`**. La lista no carga esas columnas; el diálogo de edición los pide aparte (`GET /colaboradores/{id}/datos-personales`, `Cache-Control: no-store`) |
| Sin bitácora | Eventos `colaboradores.creado/actualizado/desactivado/reactivado/sedes`. CURP, RFC, NSS y teléfono van **enmascarados** (últimos 4); de fecha, lugar, correo y dirección solo se anota el nombre del campo que cambió (`datos_personales_modificados`) |
| La edición traía un selector Estado ON/OFF: con solo `editar` se daba de baja sin el permiso `eliminar` | Baja y reingreso solo con el botón ⊘ / ↺ y el permiso `colaboradores.eliminar` |
| Modales cargados por AJAX con HTML aparte; el `<script>` del registro rápido nunca se ejecutaba al inyectarse con `innerHTML` | Diálogos nativos en la misma página, llenados con `data-valores`; todo el comportamiento en `plataforma.js`, sin código en línea |
| Alcance de sede inconsistente: la lista mostraba a los corporativos (`id_hotel IS NULL`) y a los de sedes adicionales, pero editar exigía `id_hotel = mi sede`; "Sedes adicionales" no revisaba la sede del usuario | Un solo criterio para ver, editar, dar de baja y sedes adicionales: presencia (sede física o adicional) en sus sedes, según el alcance de cada permiso. Al registrar, la sede física es obligatoria y debe ser suya. En sedes adicionales solo marca o desmarca sus sedes; las demás se conservan |
| La búsqueda para Usuarios, con sede en sesión, incluía `id_hotel IS NULL` **sin filtro de empresa**: se veían corporativos de otras empresas. Además excluía por `num_empleado NOT IN (SELECT num_colaborador FROM usuarios)` de todas las empresas | Siempre dentro de la empresa (filtro automático) y de las sedes del usuario; excluye solo a quien ya tiene cuenta vinculada (`users.colaborador_id`) |
| Resultados de la búsqueda pintados con `innerHTML` y el objeto en un `onclick='...'`: un nombre con `<` o un apóstrofo rompía la lista (XSS) | Se construyen nodos con `textContent` |
| El registro rápido respondía `200` con `{ok:false, error: $e->getMessage()}` (podía exponer el mensaje SQL); decía que el puesto "define el departamento automáticamente", pero no lo hacía | `201` con el colaborador o `422` con errores por campo; departamento y puesto se eligen y validan igual que en el alta |
| El registro rápido pasaba nombres a mayúsculas y el alta completa no | Mismo criterio en ambos: se respeta lo capturado (solo se quitan espacios dobles). CURP y RFC sí van en mayúsculas; NSS y teléfono sin espacios ni guiones |
| "Estado de nacimiento" era texto libre | Lista de las 32 entidades y "Extranjero" |
| Un colaborador podía quedar "Sin Empresa Asignada" o heredar la empresa del puesto | Siempre pertenece a una empresa (`empresa_id` obligatorio, filtro automático) |

## Modelo de datos (migración `2026_10_06_000300`)

- **`colaboradores`:** `empresa_id`, `sede_id` (sede física; nula = corporativo), `departamento_id`, `puesto_id` (llaves foráneas, `nullOnDelete`), `num_empleado` (20), `nombre`, `apellido_paterno`, `apellido_materno` (60), `telefono` (15, solo dígitos), datos personales (`fecha_nacimiento`, `lugar_nacimiento`, `nacionalidad` = "Mexicana" por omisión, `curp` 18, `rfc` 13, `nss` 11, `correo_personal`, `direccion_completa`), `activo` y auditoría. Únicos por empresa: `num_empleado`, `curp`, `rfc`, `nss` (los nulos no chocan).
- **`colaborador_sede`:** sedes adicionales. Nunca incluye la sede física: si la física cambia a una que era adicional, se quita de la lista.
- **`users.colaborador_id`:** vínculo opcional cuenta ↔ colaborador, único (un colaborador, una cuenta), `nullOnDelete`.

Modelo `App\Models\Colaborador` (`PerteneceAEmpresa`, `RegistraAutor`); scope `enSedes($ids)` (física o adicional). Reglas en `App\Services\Colaboradores\AdministradorColaboradores`, compartidas por la pantalla, el registro rápido y la búsqueda.

## Permisos

| Permiso | Para qué |
|---|---|
| `colaboradores.ver` | Lista |
| `colaboradores.crear` | Alta y registro rápido |
| `colaboradores.editar` | Edición y sedes adicionales |
| `colaboradores.eliminar` | Baja lógica y reingreso |
| `colaboradores.datos_personales` | Ver y capturar CURP, RFC, NSS, fecha y estado de nacimiento, nacionalidad, correo personal y dirección |
| `colaboradores.exportar` | (reservado para la exportación) |

- **Alcance:** empresa = todos; sede = colaboradores con sede física o adicional en sus sedes; propios = además, solo los que dio de alta. Fuera de alcance responde **404**.
- **`datos_personales`** se agregó al catálogo (`CatalogoSeeder`, idempotente). La migración `2026_10_06_000310` lo da de alta en bases existentes y lo otorga a cada rol **Administrador** que ya tenía `colaboradores.editar`, con el mismo alcance (mismo patrón que `usuarios.desbloquear`). En instalaciones nuevas la plantilla Administrador lo recibe sola; Director (solo consulta) no.
- Sin el permiso, los formularios no muestran la sección de datos legales y el servidor ignora esos campos. Al editar, un dato personal que no llegó en la petición **no se borra** (si la carga bajo demanda falla, los campos quedan bloqueados y no se envían).
- La plantilla Agente no tiene Colaboradores (es del área Organización): recibe 403.

## Rutas

| Método y ruta | Uso |
|---|---|
| `GET /colaboradores` | Lista con filtros (sede, departamento, texto) |
| `POST /colaboradores` | Alta |
| `PUT /colaboradores/{id}` | Edición |
| `PATCH /colaboradores/{id}/estado` | Baja (`activo=0`) o reingreso (`activo=1`) |
| `PUT /colaboradores/{id}/sedes` | Sedes adicionales (`sedes[]`) |
| `GET /colaboradores/{id}/datos-personales` | JSON con los datos personales (permiso `datos_personales`) |
| `POST /colaboradores/rapido` | Registro rápido (JSON) |
| `GET /colaboradores/buscar?q=` | Autocompletar (JSON) |

### Registro rápido — `POST /colaboradores/rapido`

Permiso `colaboradores.crear`. Campos: `num_empleado`, `nombre`, `apellido_paterno`, `apellido_materno`, `sede_id` (obligatoria), `departamento_id`, `puesto_id`, `telefono`. Nunca guarda datos personales.

- **201:** `{"ok": true, "colaborador": {"id", "num_empleado", "nombre_completo", "puesto", "departamento", "sede_id", "sede"}}`
- **422** (siempre JSON, aunque no se pida): `{"ok": false, "mensaje": "primer error", "errores": {"campo": ["..."]}}`

**Usarlo desde otro módulo** (Pases de salida, Accesos...):

```blade
@include('organizacion.colaboradores._registro-rapido', ['sedeSugerida' => $sedeId])
<button type="button" data-abrir-dialogo="dialogoRegistroRapidoColaborador">Registrar colaborador</button>
```

El parcial solo se dibuja si el usuario puede `colaboradores.crear` y hay empresa de trabajo. Al guardar, `plataforma.js` cierra el diálogo y lanza en `document` el evento **`colaborador:registrado`** con el colaborador en `detail`:

```js
document.addEventListener('colaborador:registrado', function (e) { /* e.detail.id, e.detail.nombre_completo... */ });
```

### Búsqueda — `GET /colaboradores/buscar`

Para quien tenga `colaboradores.ver`, `colaboradores.provisional`, `usuarios.crear` o `usuarios.editar` (si no, 403). Parámetros: `q` (mínimo 2 caracteres; por inicio del número de empleado o por palabras del nombre y apellidos), `sin_usuario=1` (excluye a quien ya tiene cuenta), `usuario={id}` (no excluye al vinculado con esa cuenta). Solo activos, de la empresa de trabajo y, con alcance de sede, de sus sedes (el más amplio entre los permisos que le dan acceso). Máximo 15.

Respuesta: `{"resultados": [{"id", "num_empleado", "nombre_completo", "puesto", "departamento", "sede_id", "sede"}], "todas_ya_tienen_usuario": bool}`. `todas_ya_tienen_usuario` es verdadero cuando hubo coincidencias pero todas ya tienen cuenta.

### Homónimos en el alta — `GET /colaboradores/homonimos` (QA 4)

Aviso en vivo del diálogo **Alta de Colaborador**: con `nombre`, `apellido_paterno` y (opcional) `apellido_materno` responde `{"parecidos": [resumen], "otros": n, "mensaje"}` usando `AdministradorColaboradores::parecidos()` (activos, mismo nombre y apellidos sin importar mayúsculas ni acentos; el mismo criterio del alta provisional). Permiso `colaboradores.crear` (si no, 403). Con alcance de sede, los de otras sedes solo se cuentan en `otros`. Es **solo un aviso** («¿Es la misma persona? Si lo es, no lo des de alta otra vez: búscalo en la lista»): Recursos Humanos decide; el alta no se bloquea.

## Altas provisionales (caseta → Recursos Humanos)

Cuando un guardia necesita registrar a alguien en un formulario (acceso, préstamo, pase…) y la persona **no aparece** en el directorio, la da de alta como **provisional** para no detener la operación. Recursos Humanos después la valida o la une con su registro correcto.

| Paso | Quién | Permiso | Qué pasa |
|---|---|---|---|
| Alta provisional | Caseta: Jefe de seguridad, Asistente, Supervisor, Agente | `colaboradores.provisional` (su sede) | `POST /colaboradores/rapido`. Sin `colaboradores.crear` el alta es provisional: el número de empleado es opcional y la sede es una de las suyas. Queda con `provisional = 1` y se puede usar de inmediato |
| Evitar duplicados | — | — | Si hay alguien activo con el mismo nombre y apellidos (sin importar mayúsculas ni acentos), responde **409** con `parecidos` y el diálogo ofrece usarlo. Para registrarla de todos modos se reenvía con `confirmar_nuevo=1` |
| Validar | Recursos Humanos, Administrador, Director | `colaboradores.aprobar` | `PUT /colaboradores/{id}/validar`: corrige los datos y asigna el **número de empleado** (obligatorio, único). Guarda `validado_por` y `validado_en` |
| Es un duplicado | Mismo permiso | `colaboradores.aprobar` | `PUT /colaboradores/{id}/fusionar` con `destino_id`: todo lo que se registró con el provisional pasa al colaborador correcto (`AdministradorColaboradores::REFERENCIAS`). El provisional queda de baja con `fusionado_en_id` |

- **Para los módulos que vienen** (Accesos, Préstamo de llaves, Pases, Responsivas): cada tabla que guarde un `colaborador_id` **debe agregarse** a `AdministradorColaboradores::REFERENCIAS` (una segunda columna en la misma tabla va en `REFERENCIAS_ADICIONALES`), para que al unir un duplicado sus registros apunten al colaborador correcto. `tests/Feature/SeguridadAuditoria/RevisionFuncionalTest.php` falla si una llave foránea a `colaboradores` no está registrada.
- La búsqueda devuelve `provisional: true` para que el formulario lo marque.
- Auditoría: `colaboradores.provisional`, `colaboradores.validado` y `colaboradores.fusionado`.
- En la pantalla de Colaboradores:
  - quien tiene `provisional` pero no `crear` ve la ficha **Alta provisional**;
  - quien tiene `aprobar` ve el aviso "Hay N altas provisionales por validar", el filtro **Por validar** y, en cada ficha pendiente, los botones **Validar** y **Es un duplicado**.

## Usuarios ↔ Colaborador

- En el diálogo de Usuarios, **Núm. Colaborador** autocompleta (número o nombre) con `GET /colaboradores/buscar?sin_usuario=1`. Al elegir, llena el nombre y el campo oculto `colaborador_id`. Si se vuelve a escribir en el número, el vínculo se quita (como en SEGCAT).
- El servidor valida que el colaborador sea de la misma empresa, que esté activo (si es un vínculo nuevo) y que no tenga otra cuenta; el número de colaborador de la cuenta se toma del colaborador. `colaborador_id` entra en la bitácora de `usuarios.*`.
- La ficha del usuario muestra "Vinculado a Colaborador (activo)" o "(¡inactivo! revisar)", como SEGCAT.
- **Homónimos (QA 4):** al escribir el nombre completo, si hay un colaborador con ese nombre sin cuenta, el diálogo ofrece «Vincular con colaborador #1008 …» (ver [usuarios.md](usuarios.md#homónimos-qa-4)).

## Componentes en `public/js/plataforma.js` (bloque "Colaboradores")

- Filtro de fichas por texto, sede (física o adicional, `data-sedes="1,2"`) y departamento; se recuerda en la pestaña y `?sede=X` manda sobre lo guardado.
- Combos dependientes en `[data-form-colaborador]`: `[data-colab-sede]` → `[data-colab-depto]` (`data-todas`, `data-sedes`) → `[data-colab-puesto]` (`data-deps`; sin departamentos = universal). Un valor guardado que ya no encaja se deja visible y el servidor avisa, nunca se borra en silencio.
- `[data-numeros-existentes]` + `[data-aviso-numero]`: aviso en vivo de número repetido (con los números que el usuario ve).
- `data-url-datos` en el botón de editar: carga los datos personales al abrir.
- `[data-registro-rapido-colaborador]`, `[data-buscar-colaborador]` (Usuarios).

## Datos demo

`plataforma:demo` crea, solo la primera vez, 13 colaboradores en las dos sedes (seguridad, recepción, ama de llaves, mantenimiento, alimentos y bebidas; uno corporativo y uno de baja), con CURP, RFC y NSS ficticios de formato válido. Roberto Hernández (Centro) tiene Playa como sede adicional. `admin.demo`, `supervisor.demo`, `agente.demo` y `agente2.demo` quedan vinculados a su colaborador.

`plataforma:demo` también crea dos altas provisionales hechas por `agente.demo` (Jorge Méndez Tun, persona nueva, y Beto Hernandez, duplicado de Roberto Hernández) y la cuenta `rh.demo` con el rol Recursos Humanos.

## Pruebas

`tests/Feature/Organizacion/ColaboradoresTest.php` y `tests/Feature/Organizacion/ColaboradoresProvisionalesTest.php`

## Ronda 6: aviso de duplicado en vivo

El **Núm. Empleado** (alta, edición y validación de provisionales) y el **nombre + apellidos** (alta y edición; `data-duplicado-con="apellido_paterno,apellido_materno"`) usan `GET /colaboradores/duplicado` (permiso `colaboradores.crear`, `.editar` o `.aprobar`). Se quitaron `data-numeros-existentes` y la caja de homónimos `data-homonimos` de esta pantalla (el endpoint `/colaboradores/homonimos` se conserva). De otras sedes solo se dice que existe, sin datos. Un colaborador dado de baja ofrece **Reactivar**.
