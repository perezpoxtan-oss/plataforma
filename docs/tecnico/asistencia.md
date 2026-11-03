# Asistencia (módulo opcional · decisión 13 del dueño · ADR-0024)

Complemento de Recursos Humanos para todos los planes. Cruza las entradas y salidas de los colaboradores que ya registra la Bitácora de accesos con el turno de cada uno y da, por día: hora de entrada y de salida, retardo, salida anticipada, falta, horas trabajadas y turnos que cruzan la medianoche. **La plataforma no calcula nómina**: entrega el reporte en Excel (CSV) para la nómina externa.

Esencia: el agente de caseta no tiene **ningún paso nuevo**. Con el módulo apagado (de fábrica) nada cambia en ninguna pantalla.

## Piezas

| Pieza | Qué hace |
|---|---|
| Módulo `asistencia` (área Recursos Humanos; menú **Recursos Humanos → Asistencia**) | Acciones `ver`, `justificar` (acción nueva del catálogo), `exportar`, `configurar`. |
| `Modulo::APAGADOS_DE_FABRICA` | `['asistencia']`: `ProvisionarEmpresa::crear()` sin lista de módulos no lo enciende, ni la migración. Se enciende al contratar el complemento (suscripción) o en Empresas → Módulos (Super Administrador). |
| `DependenciasModulos::REQUIERE['asistencia']` | `colaboradores`, `turnos` (y, por Colaboradores, `departamentos`). |
| Catálogo comercial | Producto `asistencia` y paquete `asistencia` (suscripción, por sede, `CatalogoComercialSeeder::PRECIO_ASISTENCIA` = 190, **precio de ejemplo**) para Esencial, Profesional y Corporativo. En una base existente el paquete nace **sin publicar**. |
| `App\Services\Asistencia\MarcajesColaborador` | **Punto de extensión**: de dónde salen las entradas y salidas (abajo). |
| `App\Services\Asistencia\CalculoAsistencia` | El cálculo por colaborador y día (sin guardar nada) y `resumen()` de un periodo. |
| `App\Services\Asistencia\AlcanceAsistencia` | Quién ve a quién; `encendido()` y `disponible()` para los enlaces de otras pantallas. |
| `App\Services\Asistencia\AjustesAsistencia` | `empresas.preferencias['asistencia']`: `tolerancia_minutos` (10) y `aviso_faltas` (apagado). Solo se guarda lo distinto de la omisión. |
| `App\Services\Asistencia\JustificacionesAsistencia` | Guardar una justificación (auditoría `asistencia.justificada` / `asistencia.justificacion_cambiada`). |
| Tabla `asistencia_justificaciones` | Una por `empresa_id`, `colaborador_id`, `fecha`, `tipo` (falta, retardo, salida_anticipada): `motivo`, `nota`, `datos_eliminados_en`, `creado_por`/`actualizado_por`. |
| `turnos.dias_laborables` | Días ISO separados por coma (`1,2,3,4,5`); vacío = todos los días (como siempre). Solo se ve y se edita en Turnos con el módulo encendido (campo oculto `_con_dias`). |
| `App\Services\Relojes\Relojes\FaltasDelDia` | Aviso al jefe del departamento (opcional, apagado de fábrica). |
| `App\Http\Controllers\RecursosHumanos\AsistenciaController` | Las pantallas. |

## Rutas

| Método y ruta | Nombre | Permiso |
|---|---|---|
| `GET /asistencia` | `asistencia.index` (Asistencia del día; `?fecha`, `?sede`, `?departamento`, `?ver=faltan|retardos|llegaron`) | `asistencia.ver` |
| `GET /asistencia/periodo` | `asistencia.periodo` (`?desde`, `?hasta` hasta 62 días, `?colaborador` para el detalle) | `asistencia.ver` |
| `GET /asistencia/periodo/exportar` | `asistencia.exportar` (CSV con BOM) | `asistencia.exportar` |
| `POST /asistencia/justificar/{colaborador}` | `asistencia.justificar` (`?fecha=` en la dirección que arma el botón, o en el cuerpo; `tipo`, `motivo`, `nota`, `_volver` solo hacia `/asistencia…`) | `asistencia.justificar` y el colaborador en su alcance |
| `PUT /asistencia/ajustes` | `asistencia.ajustes` | `asistencia.configurar` con alcance de empresa |

Todas responden 403 «no viene en tu plan» si la empresa de trabajo no tiene el módulo encendido (también para el Super Administrador). Un colaborador de otra empresa responde 404 antes de revisar el permiso.

## Punto de extensión: de dónde salen las entradas y salidas

`MarcajesColaborador::marcajes()` lee la **misma fuente que la Bitácora de accesos**: la tabla `accesos` con `tipo = 'colaborador'`. Todo lo que registra ahí una entrada o salida de colaborador entra solo a Asistencia: la caseta (Registro Inteligente de Ingreso y «Dar salida»), el lector y la credencial (acción probable al escanear) y, cuando existan, los **equipos de acceso** (conectores ZKTeco y Hikvision, trabajo en paralelo).

Contrato para cualquier fuente nueva:

1. Una entrada es una fila de `accesos` con `tipo = 'colaborador'`, `colaborador_id`, `sede_id` y `entrada_at` (UTC).
2. La salida **cierra esa misma fila** (`salida_at`, `estado = finalizado`), igual que «Dar salida».
3. Un marcaje repetido no debe crear otra fila (idempotencia del conector); si llegara a crearla, Asistencia toma la primera entrada y la última salida del turno, así que el resultado del día no cambia.
4. Si una fuente futura no pudiera escribir en `accesos`, se suma en `MarcajesColaborador::marcajes()` y nada más cambia.

Asistencia no toca ningún archivo de equipos de acceso ni de la Bitácora.

## Reglas del cálculo

- **El día de un turno es el día en que empieza.** El nocturno 22:00–06:00 del lunes cuenta el lunes; la salida del martes se ve con su fecha.
- **Ventana del turno:** una entrada cuenta para el turno si ocurre desde 4 h antes de su inicio (o menos, si el turno dura más de 20 h, para no pisar el anterior) hasta su fin. Las ventanas de días seguidos no se encimán. La primera entrada es la hora de entrada; la salida es la de la última fila (si la última sigue abierta: «Sin salida registrada» / «En sitio»).
- **Retardo:** minutos desde el inicio cuando la entrada pasó la tolerancia (`tolerancia_minutos`, 10 de fábrica, 0 a 120). **Salida anticipada:** minutos antes del fin cuando salió antes del fin menos la tolerancia.
- **Estados:** `asistio`; `por_llegar` (antes del inicio + tolerancia); `sin_llegar` («No ha llegado», el turno corre); `falta` (terminó el turno sin entrada); `descanso` (el turno no trabaja ese día de la semana); `sin_turno`; `antes_de_alta` (días antes de `colaboradores.created_at`, no cuentan).
- **Horas trabajadas:** suma de cada fila cerrada (salida − entrada). Salir a comer y volver no cuenta ese rato; una fila abierta no suma.
- Solo el **personal** (`colaboradores.tipo = personal`): los residentes no tienen asistencia. Un turno dado de baja se trata como sin turno.
- Zona horaria: la de la sede del colaborador, si no la de la empresa, si no la de la plataforma.
- **Resumen** del periodo: días programados (asistió + falta + no ha llegado), asistencias, faltas sin justificar y justificadas, retardos sin justificar y justificados, minutos de retardo, salidas anticipadas, descansos y minutos trabajados.

## Alcance (quién ve a quién)

| Alcance del permiso | Ve |
|---|---|
| Toda la empresa | Todo el personal (también el corporativo, sin sede). |
| Su sede | El personal con sede en una de las suyas. |
| Solo los propios | La gente de los departamentos donde es responsable (titular o suplente en Autorizaciones; en todas sus sedes o en la asignada) y él mismo. |

Plantillas (`RolesPlantillaSeeder::ASISTENCIA`, misma tabla en la migración): Administrador y Recursos Humanos `ver`, `justificar`, `exportar`, `configurar` en la empresa; Director `ver` y `exportar` en la empresa; Jefe de departamento `ver` con «Solo los propios». La caseta (Agente, Supervisor, Asistente y Jefe de seguridad) y el Solicitante no reciben nada.

## Aviso de faltas (reloj y suplente)

`FaltasDelDia` (`asistencia_faltas`) solo corre con el módulo encendido **y** `aviso_faltas` encendido (Asistencia → Avanzado). Espera por colaborador con estado «No ha llegado» hoy (referencia `asistencia_falta`, id del colaborador), desde el inicio del turno más la tolerancia. Le toca al jefe del departamento (`departamento_responsables` del departamento, en todas las sedes o en la del colaborador): renglón **Faltas de hoy** en Mis pendientes e Inicio; recordatorio a la campana a los 30 min y al suplente (quien tiene `asistencia.justificar` en toda la empresa: Recursos Humanos) a los 120 min (`umbralesPropios`; se pueden cambiar en «Reloj y suplente → Avanzado»). Deja de esperar cuando la persona entra, cuando se justifica la falta (Justificar resuelve sus avisos con `CentroNotificaciones::resolver`) o cuando termina el turno (queda como falta en el reporte). Corre en proceso con el motor (campana y `plataforma:relojes --si-toca`), sin cron nuevo.

## Privacidad

- **ARCO** (`DatosDePersona`, tipo `asistencia`): la búsqueda por nombre encuentra al colaborador con cuántas entradas y justificaciones tiene; la exportación entrega `asistencia_entradas_salidas` (de `accesos`) y `asistencia_justificaciones`. **Eliminar** solo actúa sobre quien ya **causó baja**: quita la `nota` de sus justificaciones y marca `datos_eliminados_en` (el día, el tipo y el motivo quedan como constancia laboral); con relación laboral vigente se omite. Las entradas y salidas son de la Bitácora de accesos (evidencia de seguridad) y siguen su propio plazo.
- **Conservación** (`ConservacionDatos`, `asistencia_dias`, 1825 días, mínimo 365): la revisión de cada noche quita la nota de las justificaciones más viejas. Está en `AMPLIADOS`: en una empresa que aún no confirma los plazos ampliados no se aplica. En Configuración → Privacidad el campo solo se ve con el módulo encendido.
- Inventario (`InventarioDatosPersonales`): `asistencia_justificaciones` (trato `arco`, tipo `asistencia`, columna `nota`).

## Vínculos con otros módulos

| Módulo | Qué cambia (solo con Asistencia encendida) |
|---|---|
| Turnos | «Avanzado · Días que se trabaja (para Asistencia)» en el alta y la edición; la ficha muestra «Lun a Vie» / «Todos los días». |
| Colaboradores | Ícono de calendario **Asistencia** en la ficha (reporte de la persona). El aviso «Hay N colaboradores sin turno» en Asistencia lleva a Colaboradores. |
| Bitácora de accesos, lector y credencial | Ninguno: son la fuente. |
| Catálogo comercial y dependencias | Producto, paquete y `DependenciasModulos`. |
| ARCO y conservación | Tipo `asistencia` y plazo `asistencia_dias`. |
| Mis pendientes y campana | Renglón **Faltas de hoy** (solo con el aviso encendido). |
| Configuración | Tarjeta «Asistencia: tolerancia y aviso de faltas» en Recepción y RR. HH. con «Configurar en Asistencia → Avanzado». |
| Accidentes | Nada. |

## Criterios de experiencia de uso

1. Lo que espera (la falta de hoy, con el aviso encendido) llega a la campana y a Mis pendientes; al justificar se apaga.
2. Un dato se captura una vez: la entrada y salida son las de la caseta; el turno es el de Colaboradores.
3. El aviso de faltas tiene reloj, color y suplente (motor «Reloj y suplente»).
4. Verbos: **Guardar** (justificar y ajustes). «Exportar a Excel» y «Justificar» son enlaces o abren un diálogo, no botones de enviar.
5. Éxito con siguiente paso: «Justificación guardada para … Sigue con el reporte del periodo o justifica a otra persona.»
6. No aplica (no toca cuentas).
7. Tolerancia, aviso de faltas y días de descanso, plegados en «Avanzado».
8. No hay alta nueva: el módulo usa los datos que ya existen.

## Pruebas

`tests/Feature/Asistencia`: `AsistenciaCatalogoTest` (apagado de fábrica, menú, plantillas, dependencias, catálogo comercial, migración con `down()`), `AsistenciaCalculoTest` (retardo y tolerancia, falta, salida anticipada, comida, descanso, alta, nocturno, sin turno, totales, lo que registra la caseta), `AsistenciaPantallasTest` (día, alcance del jefe, justificar, reporte, Excel, ajustes, Turnos, Colaboradores, otra empresa) y `AsistenciaAvisosYPrivacidadTest` (aviso apagado y encendido con suplente, ARCO, conservación, Configuración).

## Pendiente de probar con datos reales

- Volumen: el cálculo se hace al consultar (hasta 2000 colaboradores por pantalla y 62 días). Con miles de colaboradores conviene medir en QA.
- Turnos rotativos (cada semana distinto) no existen en Turnos: se resuelven cambiando el turno del colaborador o justificando «Día de descanso».
