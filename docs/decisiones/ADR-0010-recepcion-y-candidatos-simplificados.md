# ADR-0010 — Recepción y candidatos simplificados

**Fecha:** 2026-10-11 · **Estado:** aceptada (sigue a [ADR-0009](ADR-0009-entrevistas-canalizar-y-elegir.md); aplica la crítica de uso de Recepción, Candidatos, Autorizaciones, Entrevistar y Vacantes aprobada por el dueño)

## Decisiones

1. **Caseta: tres opciones en «¿A qué viene?»** (`Acceso::OPCIONES_CASETA`): Busca empleo, Ya tiene cita y Otro trámite. Con «Ya tiene cita» la plataforma deduce entrevista / documentos / firma (`AdministradorCandidatos::deducirCita`) y lo guarda en `accesos.viene_a`; sin ficha queda «Busca empleo» con nota; con ficha pero sin entrevista ni elección, `cita` («Cita con RR. HH.»). **Los accesos existentes no se migran**: `Acceso::VIENE_A` conserva las claves de antes con su etiqueta (son historia y se siguen leyendo); «Informes» deja de ofrecerse porque el enlace a las vacantes se muestra siempre. «Documentos completos» = identificación + comprobante (provisional hasta la fase 3).
2. **Etapas**: `revision` y `entrevista_rh` se funden en `con_rh` («Con RR. HH.»); `registrado` solo cambia de etiqueta («Por atender»). Migración idempotente `2026_10_22_000100` con `down()` que usa `entrevista_rh_en` para distinguir. La evaluación de RR. HH. se registra desde `con_rh` sin cambiar de etapa: «Entrevistar» abre el diálogo y no obliga.
3. **Segunda entrevista vs. Considerar**: la «Segunda entrevista» del jefe deja la postulación `canalizado` con el mismo entrevistador, número + 1 y sin cita; RR. HH. solo le pone fecha («Ponle fecha (reprogramar)»). «Considerar» = cartera (evaluado → RR. HH. decide). La «Segunda entrevista» de RR. HH. desde `evaluado` se conserva.
4. **«Que pase y lo atiendo»**: desde el panel de Recepción hay dos botones (`atender=1` / `0`). La campana y el correo conservan solo «Que pase»: atender es una decisión de quien recibe, y no se quiere que un clic en el celular cambie la etapa sin querer.
5. **Corregir la evaluación**: 15 minutos (`Entrevistas::MINUTOS_CORRECCION`) o hasta que RR. HH. actúe; una elección solo corrige estrellas y comentario (deshacerla es de RR. HH.). Con cita «Ahora, está en sala», el entrevistador marca «No se presentó».
6. **Menú**: «Mis entrevistas» y «Autorizaciones» se agregan en `ConstructorMenu` como pantallas personales (no son módulos con `menu_id`), para no tocar la migración de menús, `MenuSeeder` ni la regla «un módulo = un renglón». Autorizaciones solo para quien es responsable, suplente o delegado (con `autorizaciones.responder`): RR. HH. responde su «Que pase» en Recepción.
7. **Una sola pantalla de responsables y esperas**: Responsables por departamento reúne los dos interruptores y los minutos; se llega desde Departamentos, Recepción → Ajustes y Autorizaciones. Los criterios pasan a Candidatos → Criterios de entrevista. Se reutiliza `PUT /rh/recepcion/ajustes` por partes y **no se crean permisos**.
8. **Vacantes**: campana «Vacante propuesta por …» y botón «Cerrar como cubierta» en el aviso (los avisos de una vacante se resuelven al publicarla o cerrarla); alta corta con lo demás plegado y título sugerido desde el puesto.
9. **Postulación corta por internet**: nombre, teléfono, correo, sede y CV. La ficha queda `solicitud_corta` («Solicitud incompleta»); se le manda por correo un enlace del kiosco de 72 horas (`Kiosco::HORAS_ENLACE_CORREO`), reenviable desde la ficha. El correo es obligatorio en la pantalla corta porque es el camino para completar. El kiosco sigue pidiendo la solicitud completa con **1** referencia personal (`AdministradorCandidatos::REFERENCIAS_MINIMAS`): no existe un ajuste natural por empresa y agregar uno solo para esto no aporta.

## Consecuencias

- Columna nueva `candidatos.solicitud_corta`; ninguna tabla nueva ni permiso nuevo.
- Revertir el código deja `con_rh` en la base: la versión anterior no lo conoce (lo muestra como texto). Para regresar las etiquetas viejas hay que correr el `down()` de `2026_10_22_000100` o restaurar el respaldo.
