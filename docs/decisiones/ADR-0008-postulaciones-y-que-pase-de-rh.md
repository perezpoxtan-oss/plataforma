# ADR-0008 — Postulaciones, una ficha por persona y el «Que pase» de RR. HH.

**Fecha:** 2026-10-10 · **Estado:** aceptada (fase 1 de 4 del proceso de candidatos). Las etapas, el «Aprobar y enviar al departamento» y la decisión de quitar el espejo se revisaron en [ADR-0009](ADR-0009-entrevistas-canalizar-y-elegir.md) (fase 2): el espejo se conserva.

## Contexto

El dueño del proyecto definió el proceso de candidatos: la caseta solo registra a qué viene la persona y avisa; si Recursos Humanos autoriza, pasa a su oficina; RR. HH. recibe la solicitud (kiosco) y hace una entrevista de filtro; canaliza al jefe del departamento, que entrevista, evalúa y elige; después documentos, contrato y contratación. Una vacante tiene muchos candidatos y una persona vuelve varias veces (segunda entrevista, documentos, firma, otra vacante).

Hasta ahora cada llegada de caseta creaba una ficha nueva (`candidatos`) y la etapa vivía en la ficha: la misma persona quedaba repetida y no había historia de sus intentos.

## Decisión

1. **Postulación** (`postulaciones`): cada vez que una persona aplica. Lleva vacante, departamento, puesto, etapa, origen, motivo de descarte y las fechas por etapa. La **ficha** (`candidatos`) conserva datos personales, solicitud, CV, documentos y firma. En la fase 1 la postulación usa las **mismas** etapas y transiciones de siempre (`Candidato::TRANSICIONES`); la fase 2 las cambiará (evaluaciones, canalización con cita y «Elegir» del jefe).
2. **Espejo temporal en la ficha.** `candidatos.etapa` (y vacante, departamento, puesto, sede, motivo y fechas) se conserva como espejo de la **postulación activa** (la más reciente). **Manda la postulación**: todo cambio se escribe en ella (condicionado a la etapa esperada) y se refleja en la ficha (`Postulaciones::reflejar()`). Se eligió el espejo porque listas, filtros, contadores, panel de Recepción, métricas, exportación, kiosco y pruebas leían `candidatos.etapa`: mover todo de una vez tocaba más de veinte lugares y el riesgo de dejar una pantalla incoherente era alto. La fase 2 evaluó quitar el espejo y decidió conservarlo (ADR-0009).
3. **Una ficha por persona.** Antes de crear una ficha se busca en la empresa por persona del padrón, teléfono normalizado o CURP (y, si viene a una cita, por nombre exacto). Si existe, no se crea otra: con postulación abierta se usa esa; si no, se abre una nueva. En «Nuevo candidato» el aviso en vivo usa el mecanismo genérico (`data-duplicado` + `AvisoDuplicado`) con el botón «Abrir su ficha».
4. **«¿A qué viene?» en la caseta** (`accesos.viene_a`): Busca empleo (con vacante publicada o «No sabe / otra»), Entrevista, Entrega de documentos, Firma de contrato, Informes / ver vacantes y Otro trámite. La caseta ya no captura puesto, departamento ni vacante libre. La caseta nunca ve etapas ni datos del CV.
5. **«Que pase» de RR. HH.** con el mismo mecanismo de las visitas a departamento: `autorizaciones.tipo = recepcion` (sin departamento), destinatarios con `candidatos.editar` o `recepcion_rh.ver` en esa sede, respuestas Que pase / Que espere / No puede pasar por campana, Mis pendientes, Recepción y correo firmado. «Que espere» no cierra la solicitud: el acceso queda `autorizacion = espera`.
6. **Ajuste por empresa** `recepcion.rh_autoriza_paso`: encendido por omisión (empresas nuevas). En las empresas que ya existían la migración lo deja **apagado** para no cambiarle a la caseta su forma de trabajar sin aviso (cambio visible que requiere aprobación); cada empresa lo enciende en Recepción → Ajustes.

## Consecuencias

- Mientras exista el espejo, nadie debe escribir a mano las columnas de etapa de `candidatos`: se usa `Postulaciones` (las pruebas y el demo ya lo hacen).
- Una ficha puede cambiar de sede al abrir una postulación en otra sede (la ficha refleja la sede de su postulación activa).
- La bandeja «Autorizaciones» no muestra las de recepción: se atienden en Recepción.
