# Lección 41 · Pases de salida: firmas en caseta, aprobación por valor, rechazo, campana y «Llegan a mi sede»

**Duración:** 30 minutos · **Para:** Administradores (configuración), aprobadores, caseta de origen y de destino.

Aprenderás:

1. Que **sin configurar nada** los pases funcionan como siempre.
2. A elegir **qué firmas pide la caseta** en cada movimiento, para todos los motivos o con excepciones.
3. A pedir un paso de aprobación **solo según el valor** del equipo.
4. A decidir qué pasa **al corregir un pase rechazado**: empezar desde el primer paso o retomar.
5. A leer los **avisos de la campana** de pases y cómo se resuelven solos.
6. A usar **Llegan a mi sede** en la caseta destino.

## Antes de empezar

Usa la empresa de práctica con `admin.demo` (Administrador), `jefedepto.demo` (jefa de Recepción), `jefe.demo` y `director.demo` (aprobadores), `agente.demo` (caseta de Centro), `agente2.demo` (caseta de Playa), `supervisor.demo` y `solicitante.demo`.

La empresa de práctica conserva el circuito de ejemplo con **dos firmas en cada movimiento** de caseta. Solo el motivo **Devolución** (ningún pase de ejemplo lo usa) tiene una excepción para practicar: **Registrar Salida** pide solo la firma de Seguridad y **Registrar Regreso** no pide firmas.

## Práctica 1 · Lo de siempre (agente.demo y agente2.demo)

1. Con `agente2.demo` abre **Operación → Caseta → Pases de salida** y toca la píldora **Llegan a mi sede**: aparece `PS-000007` «En camino a Playa… Cuando llegue, toca «Confirmar llegada a esta sede»».
2. Abre el pase y toca **Confirmar llegada a esta sede**: pide el nombre y la firma de quien entrega y tu firma. Guarda.
3. Vuelve a **Llegan a mi sede**: el pase ya no está.

Comprueba: en la campana de `agente2.demo` el aviso «Un pase viene a tu sede» quedó leído.

## Práctica 2 · Firmas en caseta (admin.demo y agente.demo)

1. Con `admin.demo` abre **Circuito** y baja a **Firmas en caseta**. Abre **Excepciones por motivo (avanzado)** y mira el renglón **Devolución**.
2. Con `solicitante.demo` crea un **Nuevo Pase** con motivo **Devolución** hacia un proveedor. Apruébalo con `jefedepto.demo` (jefa de su departamento), `jefe.demo` y `director.demo`.
3. Con `agente.demo` toca **Registrar Salida**: la ventana dice «En este pase, este movimiento solo pide la firma de Seguridad.» Marca los artículos y guarda.
4. Con `admin.demo` cambia **Registrar Salida** de **Devolución** a **Dos firmas** y guarda. Vuelve al pase de la práctica: **conserva** lo que tenía al enviarse.
5. Registra el regreso: «Este movimiento no pide firmas». Imprime el pase: la hoja dice «Sin firma: se registra en la plataforma» y quién lo registró.

## Práctica 3 · Aprobación por valor (admin.demo y solicitante.demo)

1. Con `admin.demo`, en **Circuito**, abre **Condición de valor (avanzado)** del paso **Gerencia**, quita sus motivos (para que aplique a todos) y escribe **50000** en «mayor o igual a». Guarda.
2. Con `solicitante.demo` crea un préstamo de una laptop de **$60,000** (en **Valor por pieza $**): el circuito incluye Gerencia.
3. Crea otro de **$8,000**: Gerencia no aparece. Crea un tercero sin valor: Gerencia **sí** aparece (si no se sabe el valor, se pide).

## Práctica 4 · Rechazo que retoma (admin.demo, jefedepto.demo, jefe.demo y director.demo)

1. Con `admin.demo`, en **Al corregir y reenviar un pase rechazado**, elige **Retomar desde el paso que rechazó** y guarda.
2. Con `solicitante.demo` crea un pase. `jefedepto.demo` y `jefe.demo` lo aprueban; `director.demo` lo **rechaza** con un motivo.
3. `solicitante.demo` ve en la campana «Tu pase … fue rechazado» con el motivo. Corrige y reenvía.
4. El pase vuelve a **Contraloría**; los pasos del jefe del departamento y del Jefe de Seguridad dicen «firma conservada del envío anterior».
5. Regresa la opción a **Empezar desde el primer paso** (la de siempre).

## Práctica 5 · Campana y vencidos (supervisor.demo)

1. Crea un pase con `solicitante.demo`. Con `jefedepto.demo`, abre la campana: «Te toca firmar el pase …» con el botón **Abrir pase**; el pase también está en **Mis pendientes → Firmas de pases de salida**. Cuando otra persona firma ese paso, el aviso queda leído y sin botón.
2. `PS-000012` está vencido: cada mañana llega «Pase PS-000012 vencido — debió regresar el …» a quien lo pidió y a quien aprueba en Centro (también a `supervisor.demo` si tiene «Aprobar»). Al registrar el regreso, el aviso queda leído.

## Para recordar

- Sin configurar, **todo es como siempre**: tres aprobaciones, dos firmas por movimiento y el rechazo desde el paso 1.
- La revisión de los artículos en caseta **no se quita** nunca.
- Cada pase **conserva** las firmas de caseta que tenía al enviarse.
- Sin valor conocido, los pasos por valor **se piden**.
- **Restablecer** vuelve a todo lo de siempre.
