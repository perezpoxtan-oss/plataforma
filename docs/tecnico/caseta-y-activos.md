# Caseta y activos: mejoras de Transporte, Llaves, Procedimientos, Responsivas y Lector (ADR-0014)

Atiende la «Crítica de uso» de Bitácora de transporte, Préstamo de llaves, Procedimientos, Responsivas y Lector universal. Decisión: [ADR-0014](../decisiones/ADR-0014-caseta-y-activos.md). Regla del dueño: **sin configurar, todo funciona como el diseño original**; lo que una empresa puede querer distinto vive en **Configuración → Caseta y activos**.

## Piezas

| Pieza | Archivo |
|---|---|
| Ajustes por empresa (`empresas.preferencias['caseta']`) | `app/Support/AjustesCaseta.php` |
| Pantalla y guardado | `resources/views/administracion/configuracion/_caseta.blade.php`, `app/Http/Controllers/Administracion/AjustesCasetaController.php` |
| Avisos en la campana | `app/Services/Activos/CampanaActivos.php` (tipos nuevos en `CentroNotificaciones::TIPOS`) |
| Renglones de Mis pendientes | `app/Services/Activos/PendientesActivos.php` (llamado desde `MisPendientes::calcular`; «Procedimientos por aprobar» lo pinta `MisPendientes` en su lugar con `procedimientosPorAprobar()`, ADR-0016) |
| Acción probable al escanear fuera de un formulario | `app/Services/Lector/AccionesLector.php`, `LectorController::ir`, `resources/views/lector/acciones.blade.php` |
| Recordatorios (cron, en proceso) | `app/Console/Commands/RecordarActivosPendientes.php` → `plataforma:activos-pendientes --si-toca` en `despliegue/desplegar.sh` |
| Migración | `2026_10_25_000100_acuse_de_procedimientos_con_casilla` (`procedimiento_acuses.firma_ruta` admite nulo) |
| JS / CSS | bloques «Mejoras activos» al final de `public/js/plataforma.js`, `public/css/plataforma.css` y `public/css/modos-pantalla.css` |
| Pruebas | `tests/Feature/Seguridad/MejorasActivosTest.php` |

## Ajustes (`AjustesCaseta`)

Solo se guarda lo distinto de la omisión; con todo en la omisión se borra la clave `caseta`. `AjustesCaseta::de($empresa)` siempre devuelve las seis claves (con la omisión si falta o es inválida).

| Clave | Valores | Omisión (original) |
|---|---|---|
| `transporte_firmas` | `ambas`, `guardia`, `taxista`, `ninguna` | `ambas` |
| `llaves_horas_aviso` / `llaves_horas_escalar` | 0–72 / 0–168 h (escalar > aviso si ambos > 0) | 0 / 0 (sin semáforo) |
| `responsivas_horas_turno` / `responsivas_horas_escalar` | 0–24 / 0–48 h | 0 / 0 (sin hora de regreso) |
| `procedimientos_acuse` | `firma`, `casilla`, `casilla_criticos` | `firma` |

`PUT /configuracion/caseta` (`configuracion.caseta`): `configuracion.editar` **con alcance de empresa** (403 si no); valida con `AjustesCaseta::validar()`; audita `configuracion.caseta` (antes y después completos). Lectura: quien ve Configuración.

## Bitácora de transporte

- Texto: el estatus `no_llego` se muestra como **LA UNIDAD NO LLEGÓ (Se usan taxis)**; filtros «La unidad no llegó (taxis)»; «Proveedor (Fletera)» → «Transportista». La clave y la etiqueta del modelo no cambian (CSV y auditoría siguen igual).
- Firmas: `TransporteController::permisos()` calcula `firma_guardia` y `firma_taxista` = `transporte.firmar` ∧ `AjustesCaseta::firmaEnPantalla()`. `BitacoraTransporte::registrar()` acepta `bool|array{guardia, taxista}` (un bool aplica a las dos, como antes) y solo exige y guarda la firma que se pide en pantalla. El vale impreso ya dejaba la línea vacía para la firma que falte; ahora el encabezado lista solo a quien debe firmar a mano.
- Vo.Bo.: al registrar cada vale, `CampanaActivos::valePorAutorizar()` avisa a quien tiene `transporte.aprobar` en la sede (menos quien lo registró), con el botón «Abrir vale»; se resuelve al autorizar o anular y se vuelve a avisar al reactivar un vale sin Vo.Bo. Mis pendientes: «Vales de taxi por autorizar» (alcance de `transporte.aprobar`) enlaza a la lista con `estado=por_autorizar` desde la fecha del más antiguo. El correo del vale (`vale_taxi`) sigue igual.
- Reloj y color del Vo.Bo. pendiente (decisión del dueño, 2026-10-12; criterio 3): `App\Support\RelojVoBo` con `HORAS_AMARILLO = 24` y `HORAS_ROJO = 72` (normal < 24 h, amarillo 24–72 h, rojo > 72 h). La vista `componentes/reloj-vobo.blade.php` pinta «Hace 3 h · a tiempo» / «· más de 1 día» / «· más de 3 días» (texto además del color; `data-reloj-vobo=normal|ambar|roja`). Se usa en la tarjeta del vale (`transporte/_tarjeta`, desde `created_at`) y en la ficha de procedimientos con versión en revisión (`procedimientos/_ficha`, desde `procedimiento_versiones.enviado_en`). El renglón de Mis pendientes «Vales de taxi por autorizar» toma el color del vale más antiguo (`MIN(created_at)`; normal = sin color). Para cambiar los umbrales se cambian las dos constantes. Sin migraciones. Prueba: `MejorasActivosTest::test_vobo_pendiente_muestra_reloj_y_color_con_texto_en_vales_y_procedimientos`.

## Préstamo de llaves

- `POST /prestamo-llaves/recibir` (`prestamo_llaves.recibir-escaneada`, `prestamo_llaves.editar`, 60/min): `lectura` (QR, NFC, USB o nomenclatura) → `Lector::resolver(tipos=llave)` → préstamo vigente dentro del alcance de `editar` → `AdministradorPrestamosLlaves::recibir()`. Mensajes: no encontrada, no está prestada, de otra sede, varias coincidencias. El éxito dice qué garantía devolver.
- Campo en la pantalla: `componentes.lector` en modo capturar con cámara, dentro de `form[data-recibir-llave-escaneo]`; el JS envía el formulario con `lector:capturado` (Enter, NFC, cámara o pegar).
- Garantía: con «Gafete interno» el JS llena `folio_garantia` con «GAFETE NÚM. ####» del colaborador escaneado, solo si el campo está vacío o lo llenó él (`data-autollenado`).
- Semáforo: `AdministradorPrestamosLlaves::semaforo()` (horas fuera y `null|ambar|rojo` con `AjustesCaseta::nivel()`); la ficha muestra «Fuera hace …» y la clase `semaforo-ambar|rojo` **solo si la empresa lo configuró**. `recordatorios()` avisa una vez por nivel: ámbar a `prestamo_llaves.editar` de la sede, rojo a `prestamo_llaves.eliminar` (si no hay nadie, a la caseta). Se resuelven al recibir o anular. Mis pendientes: «Llaves fuera de tiempo» con color.
- «Prestar» desde el lector: `GET /prestamo-llaves?prestar={llave}` abre el diálogo con la llave elegida (solo si está activa, en caseta y en una sede donde presta).

## Procedimientos

- `enviar()` → `avisarRevision()`: aprobadores = usuarios activos (no superadmin) con `motivoNoAprueba() === null` (nunca autor ni quien envía); campana `procedimiento_revision` (botón «Abrir procedimiento»), correo `AvisoProcedimiento('revision')` con el aviso de empresa nuevo `procedimiento_revision` (encendido por omisión, se apaga en Avisos por correo) y Mis pendientes «Procedimientos por aprobar» (`idsPorAprobar`).
- `aprobar()` / `rechazar()` → `procedimientoRevisado()`: resuelve los avisos de revisión y avisa al autor y a quien envió (`procedimiento_aprobado` / `procedimiento_rechazado` con el comentario).
- Publicación: además del correo, campana `procedimiento_publicado` a los obligados (botón «Leer ahora»); al publicar otra versión, los avisos pendientes de la anterior quedan leídos; al firmar el acuse, el aviso de esa persona queda leído.
- Acuse: `pideFirmaAcuse()` según `procedimientos_acuse` (`casilla_criticos` = firma si algún paso tiene `critico`). Sin firma, `procedimiento_acuses.firma_ruta` queda nulo; la vista de lectura cambia el botón a «Guardar «Leí y entendí»»; la pestaña Acuses muestra «Casilla»; `procedimientos.acuses.firma` responde 404. Auditoría `procedimientos.acuse_firmado` agrega `con_firma`.

## Responsivas

- Un solo término en pantalla, hoja y mensajes: **responsiva** (se retiraron «Resguardo» y «Lote»). Los nombres internos (rutas, clases CSS, `dialogoNuevoResguardo`) no cambian.
- Un solo «Recibir»: `<details class="recibir-responsiva">` en cada ficha con casillas (todas marcadas) de los equipos en campo y, por equipo, «Dañado o falta» (el diálogo de Ronda 8). `PATCH /responsivas/{id}/recibir` acepta `equipos[]` (ids de `equipos_responsiva`): recibe solo esos en OK; sin el campo, todos (compatibilidad). Vacío → «Marca al menos un equipo que regresa.»; alguno ya recibido → error. Se cierra cuando ya no queda ninguno (audita `responsivas.recibido`); si quedan, audita `responsivas.equipo_recibido` con la lista. Con casillas, cada equipo guarda `recibido_por` (como «Recibir» por equipo); sin `equipos[]` se comporta como antes.
- Equipos «Turno»: `AdministradorResponsivas::regresoTurno()` = entrega + `responsivas_horas_turno`; nivel ámbar al pasar la hora y rojo al pasar además `responsivas_horas_escalar`. La ficha muestra «Debe/Debía regresar a las …» solo si está configurado. `recordatoriosTurno()` avisa una vez por nivel: ámbar a `responsivas.editar` de la sede; rojo a quienes además tienen `equipos.eliminar` (si no hay, a la caseta). `sincronizarTurno()` resuelve los avisos cuando ya no quedan equipos de turno en campo. Mis pendientes: «Equipos de turno sin regresar».

## Lector universal

- `GET /e/{codigo}`: si `AccionesLector::para()` encuentra una acción probable y el usuario puede hacerla, muestra `lector.acciones` con el botón principal y «Solo ver la ficha»; si no, redirige a `urlLector()` como antes.
  - Llave prestada → «Recibir» (`PATCH prestamo_llaves.recibir`, alcance de `editar`).
  - Llave en caseta → «Prestar» (`prestamo_llaves.index?prestar=`), si presta en su sede.
  - Equipo en una responsiva abierta → «Recibir (OK)» (`PATCH responsivas.recibir` con `equipos[]`) y «Viene dañado o falta: abrir la responsiva».
  - Gafete en uso: sin cambio (ya llevaba a «Dar salida»).
- iPhone: no lee NFC desde el navegador (el botón de la antena solo aparece donde funciona); se documentó en el manual de Etiquetas QR qué comprar para la caseta.

## Comando `plataforma:activos-pendientes`

Corre en cada pasada del cron (desplegar.sh, cada 5 minutos) en proceso. Recorre las empresas activas con horas configuradas y avisa una sola vez por nivel (la existencia del aviso con su `referencia_tipo` y `referencia_id` evita repetir). Salida: «Llaves fuera de tiempo avisadas: N. Responsivas con equipos de turno sin regresar avisadas: M.»; con `--si-toca` solo escribe cuando hubo avisos.

## Avisos (referencias para resolver)

| Tipo | `referencia_tipo` / id | Se resuelve |
|---|---|---|
| `vale_taxi_por_autorizar` | `transporte_vale` / movimiento | Vo.Bo. o anulación |
| `procedimiento_revision` | `procedimiento_revision` / versión | Aprobar o pedir cambios |
| `procedimiento_aprobado`, `procedimiento_rechazado` | `procedimiento_resultado` / versión | — (informativo) |
| `procedimiento_publicado` | `procedimiento_lectura` / versión | Al firmar (por persona) o al publicarse otra versión |
| `llave_fuera` | `prestamo_llave_aviso` / `prestamo_llave_escalada` / préstamo | Recibir o anular |
| `equipo_turno` | `responsiva_turno_aviso` / `responsiva_turno_escalada` / responsiva | Ya no quedan equipos de turno en campo |

## Archivos compartidos que se tocaron

Mis pendientes se calcula en cada pantalla: `PendientesActivos` suma 1 consulta (vales), 1 o 2 (procedimientos por aprobar; las aplicaciones solo si «Aprobar» es por sede) y la de los ajustes solo si la empresa no venía cargada con el usuario; las de llaves y equipos de turno solo con horas configuradas.


`routes/web.php` (bloque «Mejoras activos»), `CentroNotificaciones::TIPOS`, `MisPendientes` (renglones y conservar el color propio cuando hay colores de Candidatos), `LectorAuditoria::ACCIONES` (`caseta`), `Empresa::AVISOS` (`procedimiento_revision`), `configuracion/index.blade.php` (include), `despliegue/desplegar.sh`, `plataforma.js` (un mensaje de Responsivas), CSS al final.
