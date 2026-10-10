# Lección 39 · Entrevistar, canalizar al departamento y elegir

**Duración:** 30 minutos · **Para:** Recursos Humanos y jefes de departamento (y quien los configura).

Aprenderás:

1. Las **etapas nuevas** de una postulación y quién mueve cada una.
2. A hacer la **entrevista de RR. HH.** y calificarla con estrellas.
3. A **canalizar** al candidato con cita al jefe del departamento (y a reprogramar).
4. A **entrevistar, evaluar y elegir** como jefe en la pantalla **Entrevistar**.
5. Qué pasa con las **plazas**, la **segunda entrevista** y el **No se presentó**.

## Antes de empezar

Usa el demo (`php artisan plataforma:demo`), contraseña `Demo1234!`. Usuarios: **rh.demo** (Recursos Humanos), **jefedepto.demo** (Jefa de Recepción, Centro), **jefe.demo** (Jefa de Seguridad), **director.demo** (delegó en **admin.demo**), **agente.demo** (caseta).

En el demo ya hay, en la vacante **Recepcionista** (1 plaza, «El jefe puede ver el CV»):

- **Juan Pérez Chan**: canalizado a jefedepto.demo con cita hoy (tiene su CV en PDF).
- **Laura Gómez Poot**: la jefa la evaluó y pidió una **segunda entrevista**.
- **Miguel Ángel Cauich Pech**: en revisión, falta su entrevista de RR. HH.

Además, **Fernanda Ruiz Kú** está canalizada a Alimentos y Bebidas «Ahora, está en sala» (su aviso le llegó a admin.demo, que cubre a director.demo) y **Jorge Tun Pech** tiene entrevista con Seguridad y ya llegó a la caseta.

## Práctica 1 · Entrevista de RR. HH. (rh.demo)

1. En **Mis pendientes** toca **Por entrevistar (RR. HH.)**: aparece Miguel.
2. Abre su ficha y toca **Entrevistar**: pasa a **Entrevista RR. HH.** y se abre **Evaluación de RR. HH.**
3. Califica los cinco criterios con estrellas, elige **Canalizar al departamento**, escribe un comentario y toca **Guardar evaluación**.
4. Se abre **Canalizar al departamento**: deja a **Julieta** (responsable de Recepción) en **¿Quién lo entrevista?**, elige **Con cita**, mañana a las 10:00, lugar «Oficina de Recepción», y toca **Canalizar y avisar**.

Comprueba: la ficha dice **Entrevista con el departamento** y en **Entrevistas y evaluaciones** está tu evaluación (estrellas y promedio) y la cita.

## Práctica 2 · Considerar o rechazar sin molestar al jefe (rh.demo)

1. Crea un candidato con **Nuevo candidato**, tócale **Atender** y **Entrevistar**.
2. En la evaluación elige **Rechazar** sin comentario: verás «Escribe un comentario: es obligatorio…».
3. Escribe el motivo y guarda: queda **Rechazado**. La jefa no recibió ningún aviso.

## Práctica 3 · El jefe entrevista y elige (jefedepto.demo)

1. Mira la **campana** y **Mis pendientes → Entrevistas por evaluar** (dice la próxima cita).
2. Abre a **Juan Pérez Chan**: ves su resumen (sin CURP ni domicilio), la evaluación de RR. HH. y **Ver su CV (PDF)**.
3. En **Tu evaluación** califica, elige **Elegir** y toca **Guardar evaluación** (confirma con **Aceptar**).
4. Abre **Vacantes → Recepcionista → Candidatos de esta vacante**.

Comprueba: Juan está **Elegido**. Como la vacante tiene 1 plaza, **Laura** y **Miguel** (que seguían con el departamento) pasaron solos a **Considerar** con «Se eligió a otra persona para esta vacante», y a Julieta ya no le queda la entrevista de Miguel. Como **rh.demo**, la campana dice «Elegido por el departamento: Juan Pérez Chan» y «Vacante cubierta».

## Práctica 4 · Segunda entrevista y No se presentó (rh.demo y jefe.demo)

1. Como **rh.demo**, abre a **Fernanda Ruiz Kú** (con Alimentos y Bebidas, «Ahora, está en sala») y toca **Reprogramar**: cambia a **Julia** (Jefa de Seguridad) y guarda. A quien cubría a Diego le llega «Ya no entrevistas a…» y a Julia la entrevista.
2. Como **jefe.demo**, evalúala con **Segunda entrevista** y un comentario.
3. Como **rh.demo**, la ficha dice **Evaluado por el departamento**: toca **Segunda entrevista** y canalízala otra vez «Ahora, está en sala» («2.ª entrevista»).
4. Como su cita es «ahora», ya aparece **No se presentó**: tócalo; luego **Reprogramar** la regresa a **Entrevista con el departamento**.

## Práctica 5 · Llegada a la entrevista (agente.demo y jefe.demo)

1. Como **agente.demo** da salida a Jorge Tun Pech y regístralo otra vez con **¿A qué viene? → Entrevista**.
2. Como **jefe.demo** mira la campana: «Jorge Tun Pech ya está en recepción para su entrevista de las …».

## Práctica 6 · Ajustes (rh.demo o admin.demo)

1. En **Recepción → Ajustes**, en **Criterios para calificar las entrevistas**, agrega «Inglés» y guarda.
2. Abre la evaluación de cualquier candidato: ahora aparece el criterio nuevo; las evaluaciones anteriores conservan sus criterios.
3. En **Vacantes → Editar** marca o desmarca **El jefe puede ver el CV** y revisa la pantalla **Entrevistar** del jefe.

## Para recordar

- RR. HH. **siempre** entrevista primero; **Considerar** y **Rechazar** no avisan al jefe.
- La decisión de **elegir** es del jefe; RR. HH. cierra el contacto con el candidato y contrata.
- Solo la persona asignada (o quien la cubre con «No molestar») evalúa una entrevista.
- El jefe ve un **resumen**, nunca los datos oficiales; el CV solo si la vacante lo permite.
- Al candidato solo le llega su **cita** por correo (si se marca la casilla); nunca resultados.
- En empresas que ya usaban la plataforma, el ajuste «Que pase» de la fase 1 viene **apagado**.
