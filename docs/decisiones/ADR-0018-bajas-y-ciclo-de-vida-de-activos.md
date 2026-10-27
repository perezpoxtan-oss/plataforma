# ADR-0018 — Bajas y ciclo de vida de los activos

**Fecha:** 2026-10-26 · **Estado:** aceptada · **Detalle técnico:** [docs/tecnico/bajas-y-activos.md](../tecnico/bajas-y-activos.md)

## Contexto

La revisión de vínculos entre módulos (grupos B y C del plan de correcciones) encontró que una llave, un gafete o un equipo podía darse de baja mientras alguien lo tenía; que cada módulo usaba sus propios estados (ASIGNADO significaba cosas distintas en Responsivas y en Pases de salida); que solo un colaborador podía recibir una llave o un equipo; que la salida de la caseta no preguntaba por el gafete; que las reglas de la llave (caducidad, horario, puesto) no se revisaban; que «dar de baja» una empresa servía a la vez para «ya no trabajamos con ella» y para «tiene prohibido entrar», y que el veto se brincaba escribiendo el nombre distinto. Decisiones del dueño DD-06 a DD-09.

## Decisión

1. **Una sola máquina de estados** (`EstadoActivo`): DISPONIBLE, EN USO, ASIGNADO, FUERA, EN MANTENIMIENTO, NO REGRESÓ y DE BAJA. Cada estado lo pone el módulo dueño del movimiento; el catálogo solo lo muestra.
2. **No regresó en lugar de bloquear** (DD-06): la baja de algo que alguien tiene pasa por la pantalla común **Antes de dar de baja**. Solo bloquean una sede con gente en sitio y una zona con autos dentro. Lo pendiente pasa a NO REGRESÓ, avisa al supervisor y la baja con voucher propone a quien lo tenía. El único responsable de un departamento exige reemplazo.
3. **Receptor general** (DD-08): colaborador, persona externa o empresa externa, con tipo e id; `colaborador_id` se conserva cuando aplica para no romper informes.
4. **Gafete e identificación en la salida** con casillas ya marcadas: la salida normal sigue siendo un toque más confirmar.
5. **Reglas de la llave avisan y piden motivo** (DD-09) en lugar de impedir el préstamo; el motivo queda en el préstamo y en la auditoría.
6. **«Restringir acceso» separado de «Dar de baja»** (DD-07), con motivo y vigencia, y revisión de nombres parecidos en la caseta («¿Es la misma?»). Las empresas «de baja» de antes pasan a restringidas.
7. **Liberar lugar** en Estacionamientos no da salida a la persona; la salida vive solo en la Bitácora.

## Consecuencias

- Migraciones `2026_11_11_000100` a `000500`, todas con `down()` probado (la del receptor se niega a revertir si ya hay receptores externos: se perderían datos).
- Avisos de campana nuevos: `activo_no_regreso` y `baja_con_pendientes`; renglón nuevo en Mis pendientes.
- Verbos fuera de los diez aprobados que el plan nombra explícitamente: «No regresó», «Restringir acceso», «Quitar restricción», «Liberar lugar». Se mantienen por claridad; pendientes de confirmación del dueño.
- Pases con destino «colaborador» ya no se crean (se entrega con Responsivas); los existentes se pueden terminar.
