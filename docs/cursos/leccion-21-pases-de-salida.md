# Lección 21 — Pases de salida

**Para:** Agentes, Supervisores, Jefes de seguridad, Directores y Administradores · **Duración:** 30 minutos

## Idea clave

Un pase de salida es el permiso para que un equipo **salga** de la sede. Primero lo **aprueban** varias personas **en orden** (el circuito que configura tu empresa); luego la **caseta** verifica cada artículo y firma la salida, la llegada y el regreso. Cada paso queda en una **bitácora** que no se puede borrar.

## Práctica (en QA, con los datos demo)

1. **`jefe.demo`**: en **Inicio** ves «1 pase de salida espera tu aprobación». Toca **Ir a mi bandeja** y abre `PS-000001` (proyector). Revisa la pestaña **Artículos** y toca **Aprobar**: firma en el recuadro, marca **Guardar mi firma** y toca **Aprobar y firmar**. Mira cómo la línea de pasos dice que ahora le toca a **Contraloría**.
2. **`director.demo`** (Contraloría): abre `PS-000001` desde **Mi bandeja** y apruébalo. Como es un **Préstamo**, el circuito demo no pide Gerencia: el pase queda **Aprobado, listo para salir**.
3. **`admin.demo`**: abre `PS-000002` (venta de refrigeradores): te toca **Gerencia**. Pruébalo con **Usar mi firma guardada**. Luego abre **Configuración → Pases de salida → Configurar circuito** y lee cómo está armado (Jefe de Seguridad → Contraloría → Gerencia, esta última solo en Venta, Traspaso Definitivo y Consignación).
4. **`agente.demo`**: abre `PS-000001` y toca **Registrar Salida**. Marca el proyector (o escanéalo si es del padrón), escribe quién se lo lleva, que firme, y firma tú como Seguridad. Intenta guardar sin marcar el artículo: el sistema no te deja.
5. **`agente2.demo`** (Playa): en **Mi bandeja** aparece `PS-000001`: toca **Confirmar Llegada**. Después, **Autorizar Salida de Regreso**.
6. **`agente.demo`**: abre `PS-000010` (radios con regreso parcial) y toca **Registrar Regreso**: deja 1 de 2 y escribe un comentario. El pase sigue en **Regreso parcial**. Vuelve a registrar el que falta: queda **Regresado / Cerrado**.
7. **`admin.demo`**: abre `PS-000003` (rechazado por Contraloría). Lee el motivo, toca **Corregir y reenviar**, cambia la descripción y reenvía: las aprobaciones empiezan desde el paso 1 (ronda 2).
8. Toca **Imprimir Pase** en cualquier pase y escanea el **QR** con tu celular (con sesión iniciada): verás «Pase auténtico» y su estado.

## Para recordar

- Los pasos van **en orden**; nadie aprueba su propio pase ni firma dos pasos del mismo pase.
- **Rechazar** pide un motivo y regresa el pase al solicitante; **cancelar** solo se puede antes de que se apruebe.
- **Aprobar** pide el permiso «Aprobar»; la caseta, «Firmar». La sede destino confirma la llegada y autoriza la salida de regreso.
- Para la **salida** se verifica **cada** artículo. En el **regreso** se puede registrar una parte.
- La **Fecha Tentativa de Regreso** marca los **Vencidos**: escríbela siempre que el equipo deba volver. Cada mañana se envía un recordatorio por correo de los vencidos.
- Tu **firma guardada** es privada: solo tú la usas, y puedes borrarla desde la ficha de un pase.
