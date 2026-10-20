# Lección 21 — Pases de salida

**Para:** Agentes, Supervisores, Jefes de seguridad, Directores y Administradores · **Duración:** 30 minutos

## Idea clave

Un pase de salida es el permiso para que un equipo **salga** de la sede. Primero lo **aprueban** varias personas **en orden** (el circuito que configura tu empresa); luego la **caseta** verifica cada artículo y firma la salida, la llegada y el regreso. Cada paso queda en una **bitácora** que no se puede borrar.

## Práctica (en QA, con los datos demo)

1. **`jefe.demo`**: en **Inicio** ves «1 pase de salida espera tu aprobación». Toca **Ir a mi bandeja** y abre `PS-000001` (proyector). Revisa la pestaña **Artículos** y toca **Aprobar**: firma en el recuadro, marca **Guardar mi firma** y toca **Aprobar y firmar**. Mira cómo la línea de pasos dice que ahora le toca a **Contraloría**.
2. **`director.demo`** (Contraloría): abre `PS-000001` desde **Mi bandeja** y apruébalo. Como es un **Préstamo**, el circuito demo no pide Gerencia: el pase queda **Aprobado, listo para salir**.
3. **`admin.demo`**: abre `PS-000002` (venta de refrigeradores): te toca **Gerencia**. Pruébalo con **Usar mi firma guardada**. Luego abre **Configuración → Pases de salida → Configurar circuito** y lee cómo está armado (Jefe de Seguridad → Contraloría → Gerencia, esta última solo en Venta, Traspaso Definitivo y Consignación).
4. **`agente.demo`**: abre `PS-000001` y toca **Registrar Salida**. Marca el proyector (o escanéalo si es del padrón), escribe quién se lo lleva, que firme, y firma tú como Seguridad. Intenta guardar sin marcar el artículo: el sistema no te deja.
5. **`agente2.demo`** (Playa): en **Mi bandeja** aparece `PS-000001`: toca **Confirmar llegada a esta sede**. Después, **Autorizar salida de regreso**. (La píldora **Llegan a mi sede** le muestra lo que viene; ver la [lección 41](leccion-41-pases-firmas-valor-rechazo-y-campana.md).)
6. **`agente.demo`**: abre `PS-000010` (radios con regreso parcial) y toca **Registrar Regreso**: deja 1 de 2 y escribe un comentario. El pase sigue en **Regreso parcial**. Vuelve a registrar el que falta: queda **Regresado / Cerrado**.
7. **`admin.demo`**: abre `PS-000003` (rechazado por Contraloría). Lee el motivo, toca **Corregir y reenviar**, cambia la descripción y reenvía: las aprobaciones empiezan desde el paso 1 (ronda 2).
8. Toca **Imprimir Pase** en cualquier pase y escanea el **QR** con tu celular (con sesión iniciada): verás «Pase auténtico», su estado y su **Recorrido del pase**.
9. **`admin.demo`**: abre `PS-000013` (bocinas a Playa) y, en **Resumen**, lee el **Recorrido del pase**: **Sale de Hotel Demo Centro**, **Llega a Hotel Demo Playa**, **Sale de regreso de Hotel Demo Playa** y **Regresa a Hotel Demo Centro**, cada uno con su **Programado** y su **Real**. Compáralo con `PS-000012` (vencido: «Pendiente · Vencido (debía regresar el …)»), `PS-000011` (salió «1 día después de lo programado») y `PS-000006` (salida definitiva).
10. **`agente.demo`**: abre `PS-000014` (aprobado; su regreso programado ya pasó) y toca **Registrar Salida**: la ventana pide el **Nuevo regreso programado**. Intenta guardar sin él: no te deja. Escribe una fecha de la próxima semana, termina la salida y revisa en **Firmas y bitácora** «Cambió el regreso programado: … → …».

## Para recordar

- Los pasos van **en orden**; nadie aprueba su propio pase ni firma dos pasos del mismo pase.
- **Rechazar** pide un motivo y regresa el pase al solicitante; **cancelar** solo se puede antes de que se apruebe.
- **Aprobar** pide el permiso «Aprobar»; la caseta, «Firmar». La sede destino confirma la llegada y autoriza la salida de regreso.
- Para la **salida** se verifica **cada** artículo. En el **regreso** se puede registrar una parte.
- Al pedir el pase se escriben la **Salida programada** y el **Regreso programado** (no puede ser antes de la salida). El **Regreso programado** marca los **Vencidos**: escríbelo siempre que el equipo deba volver. Cada mañana se envía un recordatorio de los vencidos.
- En la ficha, el **Recorrido del pase** compara lo **Programado** con lo **Real** (lo que registró la caseta) en cada momento, con el nombre del lugar: **Sale de Hotel Centro**, **Llega a Hotel Playa** y **Sale de regreso de Hotel Playa** (solo si va a otra sede), **Regresa a Hotel Centro** (solo si debe regresar). En una salida definitiva solo hay «Sale de …» y la leyenda «Salida definitiva, no espera regreso».
- Si al dar salida el **Regreso programado** ya pasó, la caseta escribe un **Nuevo regreso programado** (hoy o después) antes de dejarlo salir; el cambio queda en la bitácora.
- Tu **firma guardada** es privada: solo tú la usas, y puedes borrarla desde la ficha de un pase.
