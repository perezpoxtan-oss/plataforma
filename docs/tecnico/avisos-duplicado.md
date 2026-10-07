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

Los padrones que ya tenían un aviso propio lo conservan: Llaves, Proveedores, Departamentos, Puestos y Turnos (`data-nombres-existentes`), Vehículos (`data-placas-existentes`), Colaboradores (`data-numeros-existentes`) y Usuarios/Colaboradores (homónimos, `data-homonimos`). Para un padrón nuevo, usa `data-duplicado`.

## Agregar el aviso a otro módulo

1. Agrega `duplicado(Request $request): JsonResponse` al controlador: `Gate`/`abort_unless` con el permiso de crear o editar, la empresa de trabajo y el alcance de sedes. Responde con `AvisoDuplicado::existe()`, `parecido()`, `libre()` o `nada()`.
2. Agrega la ruta GET con `throttle:120,1` (el recorrido de `tests/Feature/SeguridadAuditoria` la visita sola).
3. Pon `data-duplicado="{{ route('…duplicado') }}"` en el campo.
4. Prueba: existe, parecido, libre, edición (`excluir`), otra empresa, otra sede y un usuario sin permiso (403).

## Pruebas

`tests/Feature/Seguridad/AjustesRonda5bTest.php`.
