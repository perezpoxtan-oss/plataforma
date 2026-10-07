# Rutas de transporte

Réplica de `modules/transporte/ruta_*` de SEGCAT (`ruta_lista.php`, `sede_gestion_modal.php`, `ruta_modal_editar.php`, `ruta_proceso.php`, `paradero_proceso.php`, `paradero_buscar_ajax.php`, `ruta_imprimir_dia.php`, `ruta_itinerario_pdf.php`; inventario §4.28). Pantalla `/rutas` (menú Padrones → Logística). La **Bitácora de transporte** (§4.29) queda fuera de este módulo.

- Controlador: `app/Http/Controllers/Padrones/RutaController.php`
- Reglas: `app/Services/Rutas/AdministradorRutas.php` (validación, alcance, clonado, paraderos, próximas salidas, auditoría)
- Modelos: `Ruta`, `RutaHorario`, `RutaParada`, `Paradero` (todos con `PerteneceAEmpresa` y `RegistraAutor`)
- Migración: `2026_10_09_000500_crear_rutas_de_transporte`
- Vistas: `resources/views/padrones/rutas/{index,sede,_horario,_paradero,dia,itinerario}.blade.php`
- JS: bloque "Rutas de transporte" al final de `public/js/plataforma.js` (horarios y paraderos dinámicos, editar)
- CSS: bloque "Rutas de transporte" al final de `public/css/plataforma.css` y `public/css/modos-pantalla.css`
- Pruebas: `tests/Feature/Seguridad/RutasTest.php` (21 pruebas)

Las rutas **no son Identificables** (no llevan QR ni NFC): no se registran en `config/lector.php` ni tienen baja con voucher.

## Tablas

| Tabla | Columnas | Notas |
|---|---|---|
| `paraderos` | `empresa_id`, `sede_id`, `nombre` (150, mayúsculas), `activo`, autoría | Único `(sede_id, nombre)`. Catálogo **por sede**. |
| `rutas` | `empresa_id`, `sede_id`, `sentido` (`llegada`/`salida`), `nombre` (150), `turno_id`, `proveedor_id`, `costo_maximo_taxi` (decimal 10,2, nulo = sin tope), `hora_inicio`, `hora_fin`, `dias`, `activo`, autoría | Único `(sede_id, sentido, nombre)`. `hora_inicio`/`hora_fin`/`dias` son un **resumen** de los horarios (el más temprano y la unión de días). |
| `ruta_horarios` | `empresa_id`, `ruta_id`, `nombre` (60), `dias` (`LU,MA,MI,JU,VI,SA,DO`, siempre explícitos), `hora_inicio`, `hora_fin`, `orden`, autoría | 1..n por ruta. `hora_fin < hora_inicio` = llega al día siguiente. Al editar, los horarios que siguen conservan su id (la Bitácora de transporte podrá ligarse a ellos). |
| `ruta_paradas` | `empresa_id`, `ruta_horario_id`, `paradero_id`, `hora` (nula), `orden`, autoría | Paraderos de cada horario, en orden. Se reescriben al guardar. |

## Rutas (endpoints) y permisos

| Método | Ruta | Nombre | Permiso |
|---|---|---|---|
| GET | `/rutas` | `rutas.index` | `rutas.ver` |
| GET | `/rutas/sede/{sede}?tab=llegadas\|salidas\|paraderos` | `rutas.sede` | `rutas.ver` |
| POST | `/rutas` (`sede_id` en el cuerpo) | `rutas.store` | `rutas.crear` en esa sede |
| PUT | `/rutas/{ruta}` | `rutas.update` | `rutas.editar` sobre la ruta |
| PATCH | `/rutas/{ruta}/estado` (`activo=0/1`) | `rutas.estado` | `rutas.eliminar` sobre la ruta |
| POST | `/rutas/{ruta}/clonar` | `rutas.clonar` | `rutas.crear` en su sede |
| GET | `/rutas/{ruta}/itinerario` | `rutas.itinerario` | `rutas.imprimir` en su sede |
| GET | `/rutas/sede/{sede}/dia?fecha=AAAA-MM-DD` | `rutas.dia` | `rutas.imprimir` en la sede |
| POST | `/rutas/sede/{sede}/paraderos` | `rutas.paraderos.store` | `rutas.crear` en la sede |
| PUT | `/rutas/paraderos/{paradero}` | `rutas.paraderos.update` | `rutas.editar` sobre el paradero |
| PATCH | `/rutas/paraderos/{paradero}/estado` | `rutas.paraderos.estado` | `rutas.eliminar` sobre el paradero |

**Alcance.** Todo es por sede. Solo se ven sedes **activas** dentro de `sedesPermitidas('rutas.ver')`; una sede, ruta o paradero de otra empresa o de una sede que el usuario no ve responde **404**. Dentro de una sede visible, si falta el permiso para esa sede o ese registro responde **403**. "Sobre la ruta / el paradero" usa `Autorizador::puede($usuario, $permiso, $registro)` (tienen `sede_id` y `creado_por`), así que el alcance **propios** limita a lo que el usuario dio de alta. El Supervisor (plantilla) crea y edita en su sede pero no suspende; el Agente solo consulta (ni imprime). El Super Administrador trabaja sobre la empresa elegida en "Empresa de trabajo".

La sede de una ruta se fija al crearla (el diálogo vive en la pantalla de la sede): no se mueve una ruta entre sedes porque turno, transportista y paraderos dependen de la sede. Para otra sede se captura de nuevo.

## Reglas

- **Al menos un horario válido** (hora de inicio y de llegada, distintas). Un bloque totalmente vacío se ignora; uno a medias da un mensaje por horario ("Horario 2: falta la hora de inicio del recorrido."). Ningún día marcado = todos los días (se guardan los 7 códigos). Máximo 20 horarios y 40 paraderos por horario.
- **Turno**: activo y que se use en la sede (`Turno::aplicanEn`). **Transportista**: proveedor activo que opere en la sede (`Proveedor::scopeOperanEn`); en la lista van primero los de transporte. Al editar se conserva el que ya tenía aunque haya dejado de cumplir (se muestra "(ya no disponible)"), pero no se puede elegir otro que no cumpla.
- **Nombre** en mayúsculas sin espacios dobles, único por sede y sentido (puede existir "RUTA 1" de llegada y de salida).
- **Paraderos**: se escriben con sugerencias del catálogo de la sede (`<datalist>`, sin AJAX). Al guardar se busca por nombre (mayúsculas) en la sede; si no existe se crea, y si estaba desactivado se reactiva. Un paradero repetido en el mismo horario o con hora pero sin nombre es error.
- **Clonar**: copia la ruta con sus horarios y paraderos como «NOMBRE (COPIA)», y si ya existe «(COPIA) 2», «(COPIA) 3»…; queda activa y a nombre de quien la clona.
- **Suspender / reactivar** con el mismo botón; nunca se borra. Desactivar un paradero no cambia las rutas que lo usan: solo deja de sugerirse.
- **Próximas llegadas / salidas** de cada ficha: las 2 siguientes salidas de rutas activas desde la hora actual **de la sede** (`Sede::zonaHoraria()`), buscando hasta 14 días y respetando los días de cada horario.
- **Hoja del día**: rutas activas cuyos horarios salen ese día de la semana, por hora; una hoja para Llegadas y otra para Salidas (salto de página al imprimir, carta horizontal), una columna por paradero con su hora, ✓ si para sin hora fija o — si no para. Fecha inválida = hoy.
- **Itinerario**: datos de la ruta y, por cada horario, sus días, horas y paraderos.

Auditoría: `rutas.creado`, `rutas.actualizado`, `rutas.desactivado`, `rutas.reactivado` (sobre `Ruta` o `Paradero`) y `rutas.clonado`. La foto de la ruta incluye un renglón por horario (`Lunes a viernes · L-V · 05:45-06:40 · REGIÓN 94 05:45, …`). `LectorAuditoria` las muestra como "Ruta de transporte" y "Paradero".

## Qué se corrigió respecto a SEGCAT

| SEGCAT | Plataforma |
|---|---|
| `ruta_paraderos` copiaba el **nombre** del paradero: al renombrarlo en el catálogo, las rutas seguían con el nombre viejo. | `ruta_paradas.paradero_id` liga al catálogo; renombrar se refleja en todas las rutas. |
| El itinerario (`ruta_itinerario_pdf.php`) mezclaba en una sola tabla los paraderos de **todos** los horarios y mostraba solo la hora resumen de la ruta. | Un bloque por horario con sus días, horas y paraderos. |
| La "hoja del día" mostraba toda la semana sin poder elegir día. | Hoja de un día concreto (`?fecha=`), por defecto hoy en la zona de la sede; solo rutas activas que salen ese día. |
| Gestión de sede en un modal cargado por AJAX con `innerHTML` (y el script de cascada duplicado porque el `<script>` inyectado no corre). | Página propia `/rutas/sede/{sede}` con pestañas `?tab=`, enlazable y que funciona sin JS. |
| Turno y transportista solo se validaban contra la empresa (un turno o proveedor que no se usa en esa sede pasaba). | Se validan contra la **sede** (turnos que aplican y proveedores que operan ahí) y activos. |
| Un horario a medias se descartaba en silencio; el error decía "todos los campos son obligatorios" sin decir cuál. | Mensajes por horario y por campo, dentro del diálogo, sin perder lo capturado. |
| `dias_operacion` NULL = todos los días (ambiguo en consultas). | Días siempre explícitos. |
| Editar borraba y recreaba todos los horarios (ids nuevos cada vez). | Los horarios que siguen conservan su id. |
| El editar permitía cambiar empresa y hotel con listas filtradas en el navegador. | La sede se fija al crear; todo id se revalida en el servidor. |
| Nombre único solo al clonar (el alta permitía duplicados). | Único por sede y sentido en alta, edición y clonado. |
| Paradero duplicado por mayúsculas/espacios ("Chedraui Portillo" y "CHEDREAUI PORTILLO"). | Nombre normalizado (mayúsculas, sin espacios dobles) y único por sede. |
| Botón "Suspender" con permiso eliminar, reactivar desde Editar con un campo Estatus. | Un solo botón Suspender/Reactivar con `rutas.eliminar`; la edición no toca el estado. |
| Sin bitácora de cambios; mensajes en la URL (`?msg=`); `onclick` en línea; tipografías e íconos de CDN en las hojas. | Auditoría; mensajes en sesión; JS en `plataforma.js` con `data-*`; recursos locales. |
| Botón "Guardar" ámbar con texto blanco (contraste 2:1). | Botón `#b45309` (contraste AA). Modos Sol y Noche. |
| "Hotel / Sede", "Llegada al Hotel". | "Sede", "Llegada a la Sede" en todos los textos. |

## Ronda 6

- **RT-02 (a)**: el diálogo «Configurar Ruta y Horarios» tiene una sola barra de desplazamiento (el cuerpo): `.rutas-dialogo[open]` es una columna flexible de 92 vh con `overflow: hidden` y el cuerpo es el único que se desplaza; los combos largos (transportista) ya no ensanchan el diálogo.
- **RT-02 (b)**: «Agregar otro Horario» copia los paraderos (y sus horas) del horario anterior, editables. Antes fallaba en silencio: las horas se convierten a texto de 24 h («Ajustes de captura») y el JS las buscaba como `input[type="time"]`; ahora se buscan por su nombre (`[name$="[hora]"]`). También se acotó el manejador de horarios de Llaves, que reaccionaba al botón de Rutas.
- **RT-01**: la demo deja 6 paraderos **activos** en Hotel Demo Centro (REGIÓN 94 (CRUCERO), SUPERMANZANA 63 (MERCADO 28), AV. KABAH CON LEONA VICARIO, CHEDRAUI PORTILLO, PLAZA LAS AMÉRICAS y AV. TALLERES). El séptimo era «PARADERO DE PRUEBA», que crea `borradoDemo()` para practicar Eliminar definitivamente (lección 26); `ronda6Demo()` lo deja **desactivado**, así que la tarjeta de la sede dice 6 y la pestaña Paraderos lo muestra como desactivado.
