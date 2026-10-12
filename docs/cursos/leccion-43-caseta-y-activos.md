# Lección 43 · Caseta y activos: taxis, llaves, procedimientos y responsivas más simples

**Duración:** 35 minutos · **Para:** Administradores (configuración), agentes de caseta, supervisores, jefes de seguridad y directivos.

Aprenderás:

1. Que **sin configurar nada** todo funciona como siempre, y dónde se ajusta: **Estructura → Configuración → Caseta y activos**.
2. A registrar taxis con el texto nuevo **LA UNIDAD NO LLEGÓ** y a firmar el vale **una sola vez** si tu empresa lo decide.
3. A dar el **Vo.Bo.** desde la campana y **Mis pendientes**.
4. A **recibir una llave escaneándola** y a leer el **semáforo** de llaves fuera.
5. A enterarte por la campana de los procedimientos **por aprobar** y **por leer**, y a firmar «Leí y entendí» **con solo la casilla** cuando tu empresa lo elige.
6. A recibir equipo con **un solo botón Recibir** y a vigilar los equipos de **Turno**.
7. A usar la **acción rápida** al escanear un QR con la cámara del celular.

## Antes de empezar

Usa la empresa de práctica con `admin.demo` (Administrador), `agente.demo` (caseta de Centro), `agente2.demo` (caseta de Playa), `supervisor.demo`, `jefe.demo`, `director.demo` y `jefedepto.demo`. La empresa de práctica **no** tiene ajustes de caseta: todo empieza como siempre.

## Práctica 1 · Lo de siempre y dónde se ajusta (admin.demo)

1. Abre **Estructura → Configuración** y baja a **Caseta y activos**. Revisa que diga: guardia y taxista firman en pantalla, horas en **0** y acuse con firma dibujada.
2. No cambies nada todavía. Toca **Guardar ajustes de caseta**: «Ajustes de caseta guardados: todo funciona como el diseño original.»

## Práctica 2 · Taxis y Vo.Bo. (agente.demo y director.demo)

1. Con `agente.demo` abre **Operación → Caseta → Bitácora de transporte** → **Registrar Movimiento**. En **Estatus del Servicio** elige **LA UNIDAD NO LLEGÓ (Se usan taxis)**.
2. Captura un taxi (placas, conductor, monto, destino y un pasajero). Hay recuadro para el taxista y para ti. Guarda.
3. Con `director.demo` abre la **campana**: «Vale de taxi por autorizar: #…». Toca **Abrir vale** y luego **Autorizar (Vo.Bo.)**.
4. Abre **Mis pendientes**: **Vales de taxi por autorizar** bajó en uno y el aviso de la campana quedó leído.
5. Con `admin.demo`, en **Caseta y activos**, elige **Solo el guardia firma en pantalla** y guarda.
6. Con `agente.demo` registra otro taxi: ya no aparece el recuadro del taxista. Imprime el vale: arriba dice «El conductor y quien autoriza firman a mano.»
7. Con `admin.demo` regresa a **Guardia y taxista firman en pantalla (original)**.

## Práctica 3 · Recibir llaves escaneando y semáforo (agente.demo, admin.demo y jefe.demo)

1. Con `agente.demo` abre **Préstamo de llaves**. Arriba está **Recibir: escanea la llave que te devuelven**. Escribe `hdc-bod-01` y toca **Recibir**: «Llave «HDC-BOD-01» recibida de … Devuelve su garantía: …».
2. Toca **Prestar Llave**, deja la garantía en **Gafete interno** y escanea el gafete `1007`: el **Folio** se llena con «GAFETE NÚM. 1007».
3. Con `admin.demo`, en **Caseta y activos**, pon **1** y **2** horas en **Préstamo de llaves** y guarda.
4. Con `agente.demo` recarga: `HDC-MASTER-01` (prestada desde hace días) aparece en **rojo** con «Fuera hace … · pasó del límite». Las recién prestadas, en verde.
5. En unos minutos (cuando corra la revisión automática) llegan los avisos: a `agente.demo` el recordatorio y a `jefe.demo` el aviso rojo. **Mis pendientes → Llaves fuera de tiempo** muestra cuántas.
6. Recibe `HDC-MASTER-01` escaneándola: sus avisos se quitan solos.
7. Con `admin.demo` regresa las horas a **0**.

## Práctica 4 · Procedimientos por aprobar y por leer (jefe.demo, director.demo, agente.demo y admin.demo)

1. Con `jefe.demo` crea un procedimiento nuevo para **Hotel Demo Centro** y toca **Guardar y enviar a revisión**.
2. Con `director.demo` (o `admin.demo`) abre la **campana**: «Procedimiento por aprobar: …». También está en **Mis pendientes → Procedimientos por aprobar**.
3. Toca **Pedir cambios** con un comentario. `jefe.demo` recibe «Tu procedimiento … necesita cambios» con el comentario.
4. `jefe.demo` lo envía otra vez; `director.demo` lo aprueba y firma. `jefe.demo` recibe «Tu procedimiento … se publicó».
5. Con `agente.demo` abre la campana: «Procedimiento por leer: …». Toca **Leer ahora**, marca la casilla y firma.
6. Con `admin.demo`, en **Caseta y activos**, elige **Solo la casilla, salvo procedimientos con puntos críticos**. Publica otro procedimiento **sin** puntos críticos: `agente.demo` solo marca la casilla y toca **Guardar «Leí y entendí»**. En la pestaña **Acuses** su renglón dice **Casilla**.
7. Regresa a **Casilla y firma dibujada (original)**.

## Práctica 5 · Un solo «Recibir» y equipos de Turno (agente.demo, supervisor.demo y admin.demo)

1. Con `admin.demo`, en **Caseta y activos**, pon **1** hora en **Deben regresar después de** y **1** en **Avisar al supervisor si pasan otras**. Guarda.
2. Con `agente.demo` abre **Operación → Activos → Responsivas**: `CENRES-000002` dice **Debía regresar a las …** en ámbar o rojo (se entregó hace tiempo).
3. Toca **Nueva responsiva**, entrega dos equipos en **Turno** a `1005` y guarda: la ficha dice **Debe regresar a las …**.
4. Toca **Recibir** en esa responsiva: los dos vienen marcados. Desmarca uno y toca **Recibir marcados (OK)**: «… 1 equipo vuelve a DISPONIBLE. Falta 1 equipo por regresar.»
5. Toca **Recibir** otra vez y, en el que queda, **Dañado o falta** → **Dañado** → escribe el daño → **Recibir Equipo**. La responsiva pasa a **Historial Devueltos**.
6. Con `supervisor.demo` revisa la campana y **Mis pendientes → Equipos de turno sin regresar**.
7. Regresa las horas a **0**.

## Práctica 6 · Escanear con la cámara del celular (agente.demo)

1. Imprime la etiqueta de la llave `HDC-MASTER-01` (o abre su **Código e identificación**) y escanea el QR con la **cámara del celular**, fuera de la plataforma.
2. Si la llave está prestada, aparece **Llave prestada** con **Recibir** y la garantía que devolver. Si está en caseta, **Prestar** abre la ventana con la llave ya elegida.
3. Escanea el QR de un radio que esté en una responsiva: **Recibir (OK)**, o **Viene dañado o falta: abrir la responsiva**.
4. En un **iPhone** no aparece el botón de la antena (NFC): usa la cámara o un lector USB/Bluetooth.

## Para recordar

- Sin configurar, **todo es como siempre**; lo distinto se decide en **Caseta y activos**.
- Lo que espera a alguien (Vo.Bo., procedimientos por aprobar o por leer) **llega a la campana y a Mis pendientes** y se quita solo al resolverse.
- Las llaves y los equipos de Turno **solo** tienen semáforo si la empresa puso horas.
- **Recibir** con casillas cubre «regresó todo» y «regresaron 4 de 5»; lo dañado o faltante va por **Dañado o falta**.
- Un mismo término: **responsiva**.

## Autoevaluación

1. ¿Dónde se decide quién firma en pantalla el vale de taxi? *(Estructura → Configuración → Caseta y activos)*
2. ¿Qué pasa con el aviso de la campana cuando alguien da el Vo.Bo.? *(Queda leído y sin botón para todos)*
3. ¿Cómo recibes una llave sin buscar su ficha? *(Escaneándola en «Recibir: escanea la llave que te devuelven»)*
4. ¿Quién recibe el aviso rojo de una llave que pasó del límite? *(Quien puede anular préstamos en esa sede, normalmente el jefe de seguridad)*
5. ¿Qué procedimientos piden firma con la opción «Solo la casilla, salvo procedimientos con puntos críticos»? *(Los que tienen al menos un paso marcado como Punto crítico)*
6. Regresaron 4 de 5 radios bien: ¿qué haces? *(Recibir, desmarcar el que falta y Recibir marcados (OK))*
