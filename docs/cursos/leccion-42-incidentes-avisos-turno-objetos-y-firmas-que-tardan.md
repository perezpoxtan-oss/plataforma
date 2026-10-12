# Lección 42 · Incidentes: avisos de la bitácora, pendiente de turno, objeto encontrado, robo en una sola ficha y firmas de pases que tardan

**Duración:** 35 minutos · **Para:** Caseta (agentes), supervisores y jefes de seguridad, administradores.

Aprenderás:

1. Que **canalizar** un ticket avisa a esa persona en la campana y en **Mis pendientes**.
2. Que el expediente **ya trae el área** del ticket.
3. A dejar un ticket **Pendiente de turno** con dueño, y qué avisos salen solos.
4. A registrar un **objeto encontrado** en un paso y a completar los tickets sin artículos.
5. Que un **robo** se atiende en una sola ficha.
6. Qué avisa un **Recorrido de Protección Civil** con fallas y cómo cambiar su categoría.
7. A cambiar el **nombre del menú** de la bitácora.
8. A leer el **reloj** de un pase que espera firma y a configurar **Firmas que tardan**.

## Antes de empezar

Usa la empresa de práctica con `admin.demo` (Administrador), `jefe.demo` (Jefe de seguridad), `supervisor.demo`, `agente.demo` (caseta de Centro), `agente2.demo` (caseta de Playa), `director.demo` y `jefedepto.demo`. La empresa de práctica tiene turnos **Matutino**, **Vespertino** y **Nocturno**.

Sin configurar nada todo funciona como siempre; lo único nuevo que se ve es la campana, el reloj de los pases y los atajos.

## Práctica 1 · Canalizar avisa (supervisor.demo y agente.demo)

1. Con `supervisor.demo` abre **Operación → Incidentes → Novedades** y toca **Nuevo Ticket**. Llena lo mínimo y en **¿A quién se canaliza?** elige al agente de `agente.demo`. Toca **Despachar Ticket**: el mensaje dice «Le avisamos a …».
2. Con `agente.demo` abre la **campana**: «Te canalizaron el ticket #…» con el botón **Atender**. Abre **Mis pendientes**: aparece **Tickets canalizados a mí** y te lleva a la lista solo con los tuyos.
3. Con `agente.demo` cierra el ticket (Resuelto y Cerrado con su resolución). Con `supervisor.demo` mira la campana: «Ticket #… resuelto» con la resolución.

## Práctica 2 · El área ya viene (agente.demo)

1. Abre el expediente de un ticket que tenga Área General: en **Preguntas Base** se lee el edificio y el piso con «— del reporte inicial».
2. Solo si está mal, toca **Cambiar edificio o piso**.

## Práctica 3 · Pendiente de turno (agente.demo, agente2.demo y jefe.demo)

1. Con `agente.demo` abre un ticket abierto de Centro. En **¿A quién se canaliza?** déjalo **Sin asignar** y en **Estatus** elige **Pendiente de Turno**. Lee el recuadro amarillo y toca **Guardar Expediente**.
2. En la lista, la ficha dice **PENDIENTE DE TURNO**, desde hace cuánto, **Lo recibe el turno …** y **Sin dueño**.
3. Cuando empiece ese turno, el personal de Seguridad de Centro recibe «Pendiente de turno: ticket #…» en la campana. Si el turno termina y nadie lo atiende, `jefe.demo` recibe «Nadie atendió el ticket #…».
4. Repite con otro ticket eligiendo un dueño en **¿A quién se canaliza?**: solo él recibe los avisos del turno.

Comprueba: en **Mis pendientes** de los agentes aparece **Pendientes de turno sin dueño** mientras haya alguno.

## Práctica 4 · Objeto encontrado (agente.demo)

1. **Nuevo Ticket** con categoría **Lost & Found**: aparece **Objeto encontrado**. Escribe «Paraguas negro» y despacha: el mensaje dice el folio **LF-…** y el objeto ya está en el archivo.
2. En **Operación → Incidentes → Lost & Found** toca **Registrar objeto encontrado**: qué, dónde, quién (**Fui yo**) y **Guardar**. Usa el botón **Imprimir etiqueta de la bolsa** del mensaje.
3. Si hay tickets en **Sin artículos capturados aún**, toca **Agregar el objeto aquí**, escribe el objeto y **Guardar objeto**: el ticket sale de la lista.

## Práctica 5 · Robo en una sola ficha (supervisor.demo)

1. Abre **Operación → Incidentes → Robo** y toca **Abrir Expediente** en un caso: se abre el expediente de la bitácora con **Volver a Seguimiento de robos**.
2. Anota algo en el Minuto a Minuto y toca **Guardar Expediente**: regresas a la lista de robos.

## Práctica 6 · Recorridos con fallas (agente.demo, jefe.demo y admin.demo)

1. Con `agente.demo` haz un recorrido en Centro y marca un extintor con una pieza en rojo.
2. Con `jefe.demo` mira la campana: «Recorrido #… encontró una falla» con el número del ticket. Al finalizar el recorrido llega «terminó con N hallazgos».
3. Con `admin.demo` entra a **Estructura → Configuración → Bitácora de novedades** y elige **Mantenimiento de equipo PC**. Repite el paso 1: el ticket nuevo se llama así y muestra los equipos con falla. Regresa la opción a **Siniestro Protección Civil**.

## Práctica 7 · Nombre del módulo: Novedades o Incidentes (admin.demo y agente.demo)

**Novedades** es el nombre de siempre: ahí va todo lo que pasa en el día, sin importar su tipo. Cada empresa puede elegir **Incidentes**.

1. En la misma sección, en **Nombre del módulo**, elige **Incidentes** y toca **Guardar ajustes de la bitácora**. El aviso dice «Ajustes de la Bitácora de incidentes guardados.»
2. Con `agente.demo` el menú, el botón de abajo del celular y el título de la pantalla dicen **Incidentes**. En **Inicio**, el renglón de tickets canalizados dice «…en la Bitácora de incidentes». Los permisos no cambiaron.
3. Con `supervisor.demo` canaliza un ticket a `agente.demo`: en su campana llega «Te canalizaron el ticket #… · Incidentes».
4. Abre **Informes → Tablero**: la ficha dice **Incidentes: tickets abiertos**. Abre el **Manual → Bitácora de novedades**: arriba avisa que en tu empresa se llama **Incidentes**.
5. Si tu empresa usa otro nombre, elige **Otro nombre** y escríbelo en **Avanzado: otro nombre**.
6. Vuelve a elegir **Novedades (como siempre)** y guarda.

## Práctica 8 · Firmas de pases que tardan (admin.demo, jefedepto.demo y director.demo)

1. Abre **Operación → Caseta → Pases de salida**: cada pase que espera firma dice «Esperando firma desde hace …».
2. Con `admin.demo` abre **Circuito**, baja a **Firmas que tardan**, escribe **4 horas** en «Recordar a quien debe firmar» y **1 día** en «Avisar al siguiente nivel». Guarda.
3. Ahora el reloj se pinta en verde, ámbar desde las 4 horas (le llega a quien firma «Recordatorio: el pase … espera tu firma») y rojo desde el día (a quien firma el siguiente paso le llega «Firma atrasada: pase …»; si era el último, a quien configura los pases). En el reloj se lee «Se avisó a: …».
4. Deja los dos campos vacíos para volver a lo de siempre.

## Para recordar

- La **campana** y **Mis pendientes** avisan solos; el correo de estos avisos se enciende en **Configuración → Avisos por correo**.
- Un ticket pendiente de turno debe tener **dueño** si ya sabes quién lo atiende; sin dueño se avisa a todo el turno.
- Un objeto encontrado ya **no deja tickets a medias**.
- El nombre del menú y la categoría de los hallazgos son **por empresa**; vacío o «Siniestro» es lo de siempre.
- Sin tiempos en **Firmas que tardan**, los pases solo muestran el reloj.
