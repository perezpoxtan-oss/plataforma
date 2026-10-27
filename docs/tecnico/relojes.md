# Reloj y suplente (Etapa 4 · C-22 a C-26)

Todo lo que espera una respuesta cumple las reglas 1 y 3 de `docs/criterios-ux.md`: llega a la campana y a **Mis pendientes**, quien lo pidió se entera del resultado, y tiene **reloj, color y suplente**. Antes cada módulo resolvía sus avisos a su manera (T4 del plan); ahora un solo motor decide.

## Piezas

| Pieza | Qué hace |
|---|---|
| `App\Services\Relojes\MotorRelojes` | El motor. Corre los relojes, guarda lo que ya avisó, da el color (`nivel()`, `relojDe()`) y encadena los relojes que ya tenían su motor. `#[Scoped]`: memoriza empresa y ajustes por petición. |
| `App\Services\Relojes\Reloj` | Un reloj: qué espera (`esperas()`), a quién se le recuerda (`responsables()`), a quién pasa (`suplentes()`), su renglón de Mis pendientes (`renglonDef()`, `renglon()`), su frase de Inicio (`textosInicio()`). |
| `App\Services\Relojes\Espera` | Un asunto: referencia (la misma que usa el módulo en sus avisos), desde cuándo, título, dirección y sede. |
| `App\Services\Relojes\AjustesRelojes` | Horas por empresa (`preferencias.relojes`). Las generales son las del antiguo «Reloj del visto bueno» (`preferencias.caseta.vobo_horas_*`). |
| `App\Models\RelojEspera` / `relojes_esperas` | Memoria: una fila por asunto con `desde`, `recordado_en`, `escalado_en`, `escalado_a`. Fila especial `_arranque` por empresa. |
| `App\Http\Controllers\Administracion\RelojesController` | `PUT /configuracion/relojes` (`configuracion.editar` con alcance de empresa). |
| `plataforma:relojes --si-toca` | La misma corrida para el despliegue (`desplegar.sh`, en la lista de recordatorios que ya existía; no es un cron nuevo). |

## Cuándo corre

Siempre **en proceso** (sin `exec` ni `proc_open`; el hospedaje no tiene terminal):

1. Al consultar la campana (`GET /notificaciones/resumen`, cada 30 s con la pestaña visible): `MisPendientes::para(..., reciente: false)` llama `MotorRelojes::correrSiToca($empresaId)` y, para el Super Administrador, `correrSiToca(null)`. Candado `Cache::add('relojes:<empresa>', 60 s)`: a lo mucho una vez por minuto por empresa.
2. En la corrida del despliegue (`plataforma:relojes --si-toca`), con todas las empresas y la plataforma.

`correrSiToca` primero encadena los relojes con motor propio (se conservan tal cual): autorizaciones de la caseta y de Recepción (`Escalamiento`), reservas (`EsperaReservas`), pendiente de turno (`TurnosNovedades`), plazos de candidatos (`PlazosCandidatos`), firmas de pases (`EsperaPasesSalida`), llaves y equipos de turno, y plazos ARCO. Un error en uno no detiene a los demás (`report()`).

## Reglas del motor

- **Umbrales:** `[recordatorio, suplente]` en minutos. De fábrica 24 h y 72 h (DD-10). Algunos relojes traen los suyos (`umbralesPropios()`): caseta en minutos (los de «Quién espera autorización»), candidatos (el plazo y el doble), tickets por categoría (Accidente 2 h, Robo 4 h, las demás 24 h; al triple el suplente), permanencia por tipo, salida temporal (2 h), Lost & Found (desde que vence). En «Avanzado» se puede poner horas propias a cualquier módulo.
- **Una sola vez por nivel:** `tomar()` hace `UPDATE … WHERE recordado_en IS NULL` (o `escalado_en`); solo quien cambia la fila avisa. Dos pestañas, la campana y el cron a la vez no duplican. Lost & Found repite su recordatorio cada 168 h (aviso semanal, D-08).
- **Reinicio:** si el asunto empieza a esperar de nuevo (otra persona canalizada, otro turno), su `desde` cambia y la fila vuelve a cero.
- **Arranque:** el día que el motor corre por primera vez en una empresa guarda `_arranque`. Lo que ya esperaba antes cuenta sus avisos desde ese momento, para no mandar de golpe todo lo viejo (la tarjeta sí dice cuánto lleva de verdad).
- **Limpieza:** lo que ya no aparece en `esperas()` (se resolvió) se borra de la memoria y sus `reloj_recordatorio` / `reloj_escalado` pierden el botón.
- **Suplente de fábrica:** quien tiene el mismo permiso en toda la empresa; si no hay otra persona, quien administra la configuración; si tampoco, los mismos responsables (con el aviso que ya no se silencia). Algunos relojes tienen el suyo: jefe de seguridad de la sede (caseta, permanencia, tickets, pases vencidos), suplentes del departamento y quien canalizó (entrevistas), todos los Super Administradores (plataforma). En Configuración se puede elegir un **rol**: sus personas reciben el aviso aunque ya tuvieran el recordatorio.
- **Delegación (C-12, Etapa 3):** antes de avisar, cada responsable y cada suplente pasa por `CadenaDelegaciones::destino()` (la misma regla de la caseta, los pases, las reservas y Mis pendientes): si delegó sus aprobaciones, el aviso le llega al último de su cadena que pueda atenderlo (`Reloj::puedeAtender()`, de fábrica `leToca()`); si nadie puede, se queda con él. Los de la plataforma no se delegan. Los renglones de Mis pendientes siguen el permiso de cada quien.
- **Avisos:** `reloj_recordatorio` (alerta, se puede silenciar porque el asunto está en Mis pendientes) y `reloj_escalado` (alerta, **no** se puede silenciar). Ambos con la referencia del asunto: cuando el módulo lo resuelve con `CentroNotificaciones::resolver()`, se apagan.
- **Auditoría:** `relojes.recordatorio` y `relojes.suplente` (sin usuario; `antes.reloj`, `despues.avisados`, `sin_a_quien`).
- **Apagado:** «Recordar y pasar al suplente» apagado (o un módulo apagado) deja el reloj y el color, sin avisos.

## Los 26 renglones del inventario (E §2) y su reloj

| # | Qué espera | Reloj | Renglón de Mis pendientes |
|---|---|---|---|
| 1 | Visita / proveedor espera al departamento | `Escalamiento` (minutos) | Autorizaciones por responder |
| 2 | Candidato espera el «Que pase» | `Escalamiento` | Esperando en Recepción |
| 3 | Solicitud del kiosco sin revisar | `SolicitudesSinRevisar` (suplente al doble del plazo) | Solicitudes por revisar |
| 4 | Candidato «Por atender» / «Con RR. HH.» | `CandidatosSinAtender` | **Candidatos sin atender** (nuevo; los de hoy siguen en Esperando en Recepción) y «Lleva N días» en la tarjeta |
| 5 | Entrevista por evaluar | `EntrevistasPorEvaluar` | Entrevistas por evaluar |
| 6 | Elegido por contratar | `ElegidosPorContratar` | Elegidos por contratar |
| 7 | Vacante propuesta por un jefe | `VacantesPropuestas` | **Vacantes propuestas por publicar**; el jefe recibe `vacante_resultado` |
| 8 | Vacante cubierta por cerrar | `VacantesCubiertas` | **Vacantes cubiertas por cerrar** |
| 9 | Firma de pase de salida | `EsperaPasesSalida` (vacío = 24 h / 72 h) | Firmas de pases de salida |
| 10 | Pase vencido sin regresar | `PasesVencidos` | **Pases vencidos sin regresar** (quien aprueba en la sede y el solicitante) |
| 11 | Ticket canalizado a mí | `TicketsCanalizados` (por categoría) | Tickets canalizados a mí (con color) |
| 12 | Pendiente de turno sin dueño | `TurnosNovedades` (sin turnos: 8 h fijas, D-05) | Pendientes de turno sin dueño |
| 13 | Vale de taxi por Vo.Bo. | `ValesDeTaxi` + **Aprobar / Rechazar** con aviso al guardia | Vales de taxi por autorizar |
| 14 | Procedimiento por aprobar | `ProcedimientosPorAprobar` | Procedimientos por aprobar (con color) |
| 15 | Procedimiento por leer | `ProcedimientosPorLeer` (suplente: quien aprueba procedimientos) | Procedimientos por leer |
| 16 | Llave fuera / equipo de turno | `CampanaActivos` (llaves 12 h / 24 h encendido de fábrica, DD-10) | Llaves fuera de tiempo |
| 17 | Altas por verificar | `AltasPorVerificar` | Altas por verificar (con color) |
| 18 | Reservas | `EsperaReservas` | Reservas |
| 19 | Solicitud ARCO | `SolicitudesArco::revisarPlazos` | Solicitudes ARCO |
| 20 | Cuentas por revisar | `CuentasPorRevisar` | Cuentas por revisar (con color) |
| 21 | Lost & Found vencido | `LostFoundVencidos` (semanal) | **Lost & Found vencidos** |
| 22 | La caseta pide al supervisor | `PedidoAlSupervisor` (minutos) | Esperan tu autorización en caseta |
| 23 | Perdí mi teléfono (dos pasos) | `CuentasPorRevisar` | Cuentas por revisar |
| 24 | Super Administrador | `SolicitudesDeCompra`, `PruebasPorAprobar`, `ClientesPorRevisar`, `SuscripcionEnGracia` | Solicitudes por atender, Pruebas por aprobar, Clientes por revisar, Clientes en gracia |
| 25 | Suscripción por vencer o en gracia | `SuscripcionDelCliente` (su suplente es el Super Administrador) | **Tu suscripción** |
| 26 | Gafete / llave / equipo que no regresó | `ActivosQueNoRegresaron` | Llaves, gafetes o equipos que no regresaron |

Además (C-24 y C-25): `ExcepcionesPorRevisar`, `Permanencia`, `SalidaTemporal`, `EquiposEnMantenimiento`, `VouchersPendientes`, `FaltantesPorRecibir` y `HallazgosDeRecorrido`.

## Silenciar en Mi cuenta (DD-10)

`PreferenciasAvisos::noSilenciable($tipo)`: no se silencian los de la propia cuenta, `reloj_escalado`, `excepcion_agente` y cualquier aviso de nivel «alerta» sin renglón en Mis pendientes (`RENGLON_DE_TIPO`). `SOLO_INFORMAN` lista las alertas que solo informan. Una prueba (`EtapaCuatroFlujosTest`) revisa que todo aviso de alerta tenga su renglón o esté en una de esas listas: un aviso nuevo que espera una acción tiene que traer su renglón.

## La caseta de noche (C-25, DD-13)

`App\Services\Accesos\NocheSinSupervisor`:

- `POST /accesos/{id}/pedir-supervisor` (`accesos.crear`): marca `supervisor_pedido_en` y avisa `excepcion_pedida` al supervisor de la sede. Lo toma `PedidoAlSupervisor`.
- `PATCH /accesos/{id}/excepcion-agente` (`accesos.crear`, multipart): solo si `preferencias.caseta.agente_excepcion = si` y ya pasaron los minutos de escalamiento de la caseta (o el motor ya lo escaló). Motivo obligatorio y foto (o la del registro). Deja el acceso EN SITIO con `autorizacion = excepcion`, `excepcion_por_revisar = 1`, auditoría `accesos.autorizado_excepcion_agente` y aviso `excepcion_agente` (no silenciable) al supervisor y al jefe.
- `PATCH /accesos/{id}/revisar-excepcion` (`accesos.aprobar`): «Atender».
- Permanencia (`preferencias.caseta.permanencia_*`, `daypass_cierre_hora`) y salida temporal (`salida_temporal_horas`): reloj en la tarjeta, aviso al supervisor, al doble al jefe, filtro `?rebasaron=1` («Rebasaron su tiempo») y `?excepciones=1`.

## Base de datos

Migración `2026_11_13_000100_reloj_y_suplente`: `relojes_esperas`; `vacantes.propuesta_en/propuesta_por/cubierta_en`; `novedades.canalizado_en` (lo pone `Novedad::saving` al cambiar `asignado_a`); `movimientos_transporte.rechazado_en/rechazado_por/motivo_rechazo`; `accesos.supervisor_pedido_en/excepcion_por_revisar/excepcion_revisada_en/excepcion_revisada_por`; `equipos_responsiva.faltante_reportado_en/faltante_reportado_por/faltante_nota`. `down()` quita todo. Al migrar se llenan `propuesta_en` de los borradores y `canalizado_en` de los tickets abiertos con dueño.

## Consultas

Mis pendientes suma una consulta por reloj que aplica a la persona (memorizada por petición) y nunca una por renglón. `MenusReorganizadosTest` fija el límite.
