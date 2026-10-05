# Lección 17 — Rutas de transporte

**Para:** Jefe de seguridad, Supervisor, Administrador y Agentes · **Duración:** 20 minutos

## Qué vas a aprender

- Leer las fichas de sede: llegadas, salidas, paraderos y próximas salidas.
- Dar de alta una ruta con dos horarios (entre semana y fin de semana) y sus paraderos.
- Clonar, suspender y reactivar una ruta; administrar los paraderos de la sede.
- Imprimir la hoja del día para la caseta y el itinerario de una ruta.

## Práctica (en QA)

1. **`admin.demo`:** abre Padrones → Rutas de transporte.
   - En la ficha de **Hotel Demo Centro** lee las próximas llegadas y salidas. Fíjate en el aviso «Con 1 ruta suspendida».
   - Toca **Ver Detalles**.
2. En **Llegadas**, toca **Nueva Llegada** y captura:
   - Nombre `Ruta 4 - Av. Talleres`, turno **Matutino**, transportista **Transportes Kin-Ha**.
   - Horario 1: «Lunes a viernes», 05:30 a 06:20, marca Lun a Vie. Paraderos: `AV. TALLERES` 05:30 y `CHEDRAUI PORTILLO` 05:50 (este último te lo sugiere la lista).
   - Toca **Agregar otro Horario**: se copian los paraderos. Pon 06:30 a 07:10 y marca Sáb y Dom.
   - Guarda. Revisa que la ruta muestra **L-V** y **S-D**.
3. Intenta crear otra llegada llamada `ruta 4 - av. talleres`: el sistema avisa que ya existe en esta sede.
4. **Clona** la RUTA 2 - KABAH: aparece «RUTA 2 - KABAH (COPIA)». Edítala y cambia la hora.
5. En **Salidas**, mira la **RUTA 3 - NOCTURNA KABAH**: sale 23:20 y llega 00:30 **+1** (día siguiente).
6. **Suspende** la copia y luego **reactívala**.
7. En **Paraderos**, crea `Plaza Hollywood`; renombra `AV. TALLERES` a `AV. TALLERES (OXXO)` y abre el itinerario de la Ruta 4: ya sale con el nombre nuevo.
8. Toca **Hoja del día**, elige el próximo domingo y compara con hoy: solo salen los horarios de ese día.
9. **`agente.demo`:** abre Rutas de transporte: ve las rutas de su sede, pero no puede crear, editar ni imprimir.
10. **`agente2.demo`** (Playa): solo ve **Hotel Demo Playa**.

## Para recordar

- Todo es **por sede**: turnos, transportistas y paraderos se eligen de los que aplican a esa sede.
- Ningún día marcado = la ruta sale **todos los días**.
- Un paradero nuevo se crea solo al guardar la ruta; desactivarlo no cambia las rutas que ya lo usan.
- Suspender no borra: la ruta se puede reactivar cuando quieras.

## Autoevaluación

1. ¿Puedo tener «RUTA 1» de llegada y «RUTA 1» de salida en la misma sede? *(Sí: el nombre es único por sede y sentido)*
2. Un horario de 23:20 a 00:30, ¿es un error? *(No: llega al día siguiente)*
3. ¿Qué pasa si escribo en una ruta un paradero que no existe? *(Se crea en el catálogo de la sede al guardar)*
4. ¿Qué rutas salen en la hoja del día? *(Las activas que operan ese día de la semana, ordenadas por hora)*
5. ¿Qué puede hacer un Agente en Rutas? *(Solo consultar las de su sede)*
