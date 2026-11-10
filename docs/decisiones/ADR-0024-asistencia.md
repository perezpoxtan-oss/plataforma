# ADR-0024 — Asistencia: módulo opcional que se calcula de la Bitácora de accesos

**Fecha:** 2026-11-03 · **Estado:** aceptada · **Detalle técnico:** [asistencia.md](../tecnico/asistencia.md)

## Contexto

Decisión 13 del dueño (3 nov 2026): un módulo «Asistencia» opcional para todos los planes, apagado de fábrica, que dé por día la entrada, la salida, el retardo, la salida anticipada, la falta y las horas trabajadas de los colaboradores según su turno, con reporte por periodo para la nómina externa (la plataforma no calcula nómina). Reglas: el agente de caseta no tiene ningún paso nuevo; con el módulo apagado nada cambia; Accidentes no se toca. En paralelo se construyen los conectores de equipos de acceso (ZKTeco y Hikvision), que también registran entradas y salidas.

## Decisión

1. **No se guarda la asistencia: se calcula al consultar** a partir de `accesos` (tipo colaborador) y del turno. Lo único que guarda el módulo son las justificaciones. Así no hay dos verdades (la Bitácora y una tabla de asistencia) ni procesos nocturnos que mantener al día, y lo que corrija la caseta en la Bitácora se refleja solo.
2. **Una sola fuente con punto de extensión** (`MarcajesColaborador`): todo lo que escriba una entrada de colaborador en `accesos` entra solo (caseta, lector, credencial, equipos de acceso). El contrato está en asistencia.md; si una fuente futura no pudiera escribir en `accesos`, se suma ahí.
3. **El día de un turno es el día en que empieza**, con una ventana desde 4 h antes del inicio hasta el fin, sin encimarse con el día anterior.
4. **Días de descanso en el turno** (`turnos.dias_laborables`, vacío = todos), visibles solo con el módulo encendido, plegados en «Avanzado».
5. **Apagado de fábrica** con `Modulo::APAGADOS_DE_FABRICA`: no se enciende al dar de alta una empresa sin lista de módulos ni en la migración. Se vende como complemento (producto y paquete) para los tres planes.
6. **Alcance del jefe de departamento** con «Solo los propios» = los departamentos donde es responsable (Autorizaciones), sin un alcance nuevo en el motor de permisos.
7. **Aviso de faltas opcional** con el motor «Reloj y suplente» (`FaltasDelDia`), apagado de fábrica.
8. **Privacidad:** tipo ARCO `asistencia` (buscar, exportar; «Eliminar» quita la nota de las justificaciones de quien ya causó baja) y plazo `asistencia_dias` (5 años) para las notas. Las entradas y salidas siguen el plazo de la Bitácora de accesos.
9. Se agrega la acción **`configurar`** (además de ver, justificar y exportar) para la tolerancia y el aviso, igual que en Reservas, Candidatos y Pases de salida.

## Alternativas descartadas

- **Tabla diaria de asistencia llenada por una tarea:** el hospedaje no tiene cron confiable ni terminal; además habría que recalcular cada vez que la caseta corrige una entrada.
- **Registrar la asistencia aparte en la caseta** (un botón «Checar»): agregaba un paso al agente, contra la esencia del proyecto.
- **Alcance nuevo «Su departamento» en el motor de permisos:** cambiaba la matriz de permisos de toda la plataforma por un solo módulo.
- **Borrar las justificaciones por ARCO:** cambiaría el reporte laboral de un periodo ya pagado; se conserva el hecho (día y motivo) y se quita el texto libre.

## Consecuencias

- Las consultas de un periodo largo con mucho personal hacen el cálculo en memoria (límite de 62 días y 2000 colaboradores por pantalla).
- Los turnos rotativos no existen; se resuelven cambiando el turno o justificando «Día de descanso».
- Un colaborador dado de baja aparece en el reporte del periodo en que causó baja (se toma la fecha de su última actualización).
