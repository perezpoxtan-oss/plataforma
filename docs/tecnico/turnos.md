# Turnos

Réplica de `modules/turnos` de SEGCAT (`turno_lista.php`, `turno_modal_editar.php`, `turno_modal_asignar_hoteles.php`, `turno_proceso.php`). Se conservan las pantallas, los textos y el color índigo (`#6366f1`): fichas con horario, botón "N sedes" y el diálogo **Sedes que usan "…"**.

## Qué se corrigió respecto a SEGCAT

| SEGCAT | Ahora |
|---|---|
| Un turno nuevo quedaba en **cero sedes**: el alta no tocaba `turnos_hoteles`. Además, el usuario de sede solo veía los turnos que su hotel ya tenía activos, así que el turno que creaba desaparecía de su lista y no podía activarlo | En el alta se elige **Todas las sedes**, que es la opción por defecto e incluye las sedes futuras, o solo algunas. Quien puede asignar sedes ve todos los turnos de la empresa, incluso los que su sede todavía no usa |
| `turnos_hoteles` no tenía llave primaria, así que podía repetir filas. Se **borraba y reconstruía** en cada guardado | Se usa `sede_turno` con llave primaria `(turno_id, sede_id)` y `sync()` dentro de una transacción |
| El diálogo de sedes con alcance de empresa solo listaba hoteles activos. Al guardar, el `DELETE` + `INSERT` **borraba en silencio** las asignaciones de sedes desactivadas | Las ligas con sedes desactivadas se conservan |
| Las horas no se validaban: se aceptaba cualquier texto y un turno que empezaba y terminaba a la misma hora | Las horas se validan como `HH:MM` en formato de 24 horas y se rechaza que inicio y fin sean iguales. Un turno que cruza la medianoche, como 22:00 a 06:00, es válido. La ficha muestra la duración y el aviso "Termina al día siguiente" |
| El nombre repetido se comparaba de forma exacta, sin normalizar espacios. Un error genérico `23000` se mostraba como "duplicado" | Se quitan los espacios dobles y se compara sin importar mayúsculas (`CatalogoDeEmpresa::nombreLibre`). Hay aviso en vivo y el error de validación aparece en el mismo diálogo, que se reabre con lo que se escribió |
| El Super Administrador podía **mover** un turno a otra empresa desde Editar con el campo `id_empresa` | La empresa sale de la *Empresa de trabajo*: no viaja en el formulario y un turno no cambia de empresa |
| Los diálogos de editar y de sedes se cargaban por AJAX con HTML aparte, y la pantalla usaba `onclick`/`onkeyup` en línea | Los diálogos se pintan desde el servidor. Editar usa el llenado genérico `data-valores` y no hay JS en línea |
| El usuario de sede con `turnos.crear` daba de alta turnos para **toda** la empresa | El catálogo (alta, edición y estado) exige alcance de empresa. Con alcance de sede solo se marcan o desmarcan **sus** sedes en "Sedes que usan…", como ya pedía SEGCAT |
| Para reactivar había que ir a Editar → Estatus, y se desactivaba con un botón aparte | Un solo botón ⊘ / ↺, igual que en todas las pantallas. Es una corrección de consistencia |
| Sin auditoría | Eventos `turnos.creado`, `turnos.actualizado`, `turnos.sedes_actualizadas`, `turnos.desactivado` y `turnos.reactivado`, con antes y después (incluye la lista de sedes) |

## Modelo de datos (migración `2026_10_06_000200`)

- **`turnos`:** `empresa_id`, `nombre` (50), `hora_inicio` y `hora_fin` (`time`), `todas_las_sedes`, `activo` y auditoría. Índice único `(empresa_id, nombre)`. Las horas se guardan como `HH:MM:00`.
- **`sede_turno`:** sedes que usan el turno, solo cuando `todas_las_sedes = 0`. Tiene llave primaria compuesta e índice en `sede_id`.
- **`Turno`:** expone `inicio()`, `fin()`, `cruzaMedianoche()`, `minutos()` (suma 24 h cuando el fin es menor que el inicio), `duracion()` ("8 h", "11 h 30 min") y el scope `aplicanEn($sedeIds)`.

## Permisos

- **`turnos.ver`:** entra a la pantalla.
  - Con alcance de empresa ve todos los turnos.
  - Con alcance de sede y solo consulta ve los que se usan en su sede.
- **`turnos.crear`, `turnos.editar` y `turnos.eliminar`:** este último desactiva o reactiva. Sobre el catálogo exigen alcance de **empresa** (`CatalogoDeEmpresa`); con alcance de sede la ruta responde 403.
- **"Sedes que usan este turno"** (`PUT /turnos/{id}/sedes`) requiere `turnos.editar`:
  - **Con alcance de empresa:** se elige *Todas las sedes* o la lista. Se permite dejar el turno sin sedes, como en SEGCAT. En el alta, en cambio, se exige al menos una.
  - **Con alcance de sede:** el diálogo solo muestra sus sedes, y lo que llegue para otras sedes se ignora. Si el turno era de *Todas las sedes* y quita la suya, pasa a lista explícita con todas las demás.
- Un turno de otra empresa responde 404.

## Rutas

`GET|POST /turnos`, `PUT /turnos/{id}`, `PUT /turnos/{id}/sedes`, `PATCH /turnos/{id}/estado`

## Filtro por sede (cambio en `public/js/plataforma.js`)

Un turno puede usarse en varias sedes. Por eso el filtro genérico de fichas acepta en `data-sede` **una lista de ids separados por espacio** (`data-sede="3 7"`). Un valor único sigue funcionando igual, así que Zonas y otras pantallas no cambian. Si el turno es de *Todas las sedes*, la ficha lleva los ids de todas las sedes activas.

## Estilos

Al final de `public/css/plataforma.css` se agregó el bloque "Turnos (índigo)" con `tema-indigo`, `.turno-horario`, `.btn-sedes-turno` y `.opcion-sede-turno`. El botón `btn-indigo` ya existía y se reutiliza. Las variantes Sol y Noche están en `public/css/modos-pantalla.css`.

El campo `type="time"` muestra la hora con el formato del equipo; en Chrome con español de México sale "10:00 p.m.". Al servidor siempre llega en 24 h y las fichas la muestran en 24 h, como SEGCAT.

## Datos demo

La primera vez, `plataforma:demo` crea cuatro turnos:

| Turno | Horario | Sedes |
|---|---|---|
| Matutino | 07:00–15:00 | Todas |
| Vespertino | 15:00–23:00 | Todas |
| Nocturno | 23:00–07:00 | Todas |
| Mixto Playa | 10:00–18:00 | Solo Hotel Demo Playa |

## Rutas de transporte (resuelto)

En SEGCAT, Rutas usaba los turnos de la empresa sin mirar `turnos_hoteles`. Rutas ya ofrece y valida solo los turnos activos que `aplicanEn` la sede de la ruta (ver [rutas.md](rutas.md)).

## Pruebas

`tests/Feature/Organizacion/TurnosTest.php`
