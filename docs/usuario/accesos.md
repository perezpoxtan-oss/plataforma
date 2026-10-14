---
titulo: Bitácora de accesos
modulos: [accesos]
seccion: Operación
orden: 10
resumen: Registrar quién entra y sale de la sede, dar salida con el gafete, ver quién sigue dentro y qué espera autorización.
---
# Bitácora de accesos

**¿Para qué sirve?** Es la pantalla principal de la caseta. Aquí registras a **todas las personas que entran** a la sede (colaboradores, huéspedes, visitas, proveedores, contratistas y servicios de emergencia), ves **quién sigue dentro** y registras su **salida**.

**Antes de empezar:** necesitas que tu rol pueda **ver** y **crear** en Accesos. Si no ves el botón rojo **Nuevo Ingreso**, pide a tu administrador que revise tu rol. Para autorizar proveedores y contratistas (o dejarlos pasar **por excepción**) se necesita además el permiso **Aprobar** (normalmente supervisores y jefes de seguridad).

## Cómo leer la pantalla

1. Entra a **Operación → Caseta → Bitácora de accesos** (en el celular, el botón **Accesos** de abajo).
2. Arriba están los botones **Nuevo Ingreso** y **Dar salida**, el campo **Escanea el gafete o escribe nombre, placas o habitación** (siempre a la vista, para dar salida sin abrir nada), el buscador y los filtros de **tipo** y **sede**.
3. Abajo hay tres pestañas:

| Pestaña | Qué muestra |
|---|---|
| **Pendientes de Autorización** | Visitas, proveedores y contratistas que esperan la autorización de su departamento, quien espera a Recursos Humanos y, si tu empresa no lo pide al departamento, proveedores y contratistas que esperan la confirmación de quien los citó. |
| **Gente en Sitio** | Todos los que están dentro ahora. |
| **Historial Finalizados** | Los que ya salieron, con filtro de fechas. |

![Gente en sitio](img/accesos/gente-en-sitio.png)

**Qué debes ver:** una tarjeta por persona con su tipo (color), nombre, sede, **gafete** (o **S/G** si no lleva), motivo, identificación que dejó, cómo llegó y a qué hora entró y quién la registró.

Cada tarjeta lleva arriba a la derecha una **pastilla con su estado** y, debajo, un renglón chico con el detalle:

| Pastilla | Qué significa | El renglón de abajo dice, por ejemplo |
|---|---|---|
| **PENDIENTE** (ámbar) | Todavía no puede pasar: espera a alguien. | «Espera a: Julieta Ramos (Mantenimiento) · 12 min», «Espera a: Recursos Humanos · 4 min», «RR. HH. pide que espere», «Sin responsable en Mantenimiento: se autoriza por excepción», «Confirma con el host: Carlos Pérez». |
| **EN SITIO** (rojo) | Está dentro. | «Autorizado por Mantenimiento», «RR. HH. dijo que pase», «Autorizado por excepción (Urgencia operativa, Sergio Díaz)». |
| **FUERA** (ámbar) | Salió un rato y va a regresar. | «Fuera en tour», «Fuera temporal». |
| **FINALIZADO** (gris) | Ya salió, o no ingresó. | «Regreso de tour», «Salida temporal», «No autorizado por Seguridad: no ingresó». |

## Cómo registrar un ingreso

1. Toca **Nuevo Ingreso**. Se abre **Registro Inteligente de Ingreso**.
2. Revisa la **Sede**. Si solo trabajas en una, ya viene elegida.
3. Toca el **Tipo de Persona**: Colaborador, Huésped, Personal Externo / Visita, Proveedor, Contratista (Obra) o Servicio de Emergencia. La ventana solo pide lo que hace falta para ese tipo.
4. Llena los datos (ver abajo por tipo).
5. Elige la **Forma de Llegada**: **A Pie** o **Vehículo Propio / Moto**. Con vehículo, escribe o escanea las **placas** y, si aplica, elige la zona de estacionamiento.
6. Si llega con más personas, usa **+** en **Acompañantes** y llena una fila por persona.
7. Toca el botón grande de abajo. **Dice lo que va a pasar** según el tipo y el motivo que elegiste:
   - **Registrar y dejar pasar**: la persona entra de inmediato y aparece en **Gente en Sitio**.
   - **Registrar · queda pendiente de autorización**: la persona espera en caseta (proveedores y contratistas, visitas a un departamento con responsable, o quien viene a Recursos Humanos si tu empresa pide que RR. HH. dé el paso) y aparece en **Pendientes de Autorización**.
   Si viene más gente detrás, toca **Guardar y capturar siguiente**: guarda y deja la ventana lista con la misma sede y tipo.

![Registro Inteligente de Ingreso](img/accesos/nuevo-ingreso.png)
![El botón dice que el registro queda pendiente](img/accesos/nuevo-ingreso-pendiente.png)

**Qué debes ver:** la ventana se cierra (o se limpia, si elegiste capturar siguiente) y arriba sale el mensaje verde con el **siguiente paso**: «Registrado y en sitio: … ¿Otro ingreso? Toca Nuevo Ingreso», «Registrado: … espera la autorización de Julieta Ramos (Mantenimiento). Ya se avisó; esta tarjeta cambia sola con su respuesta», «… espera a Recursos Humanos», «… queda pendiente. Mantenimiento no tiene responsable en esta sede: confirma con el host y toca Autorizar por excepción» o, si tu empresa no pide la autorización del departamento para ese tipo, «… queda pendiente de autorización. Confirma con el host (…) y toca Confirmar Autorización».

![Mensaje con el siguiente paso](img/accesos/mensaje-registrado-pendiente.png)

Lo que pide cada tipo:

- **Colaborador:** escanea su gafete de empleado o escribe su nombre o número y elígelo. No lleva gafete de visita. Si no aparece, toca **¿No aparece? Darlo de alta provisional**: se registra y Recursos Humanos lo valida después.
- **Personal Externo / Visita:** gafete (escanéalo o elígelo; solo salen gafetes **libres**), nombre (elígelo del Padrón de personas o escribe uno nuevo), **ID Custodiada** (la identificación que deja) y **Motivo de la Visita**. Si el motivo es **Recursos Humanos**, elige además **¿A qué viene?** (ver abajo «Cómo registrar a quien viene a Recursos Humanos»).
- **Proveedor y Contratista (Obra):** además, la **Empresa / Procedencia**, **Host — ¿Quién lo citó?** (obligatorio) y **¿A qué departamento viene?** (obligatorio si tu empresa pide la autorización del departamento; el asterisco te lo indica). Quedan en **Pendientes de Autorización**: el responsable de ese departamento recibe el aviso y autoriza o rechaza (ver abajo «Cómo autorizar a un proveedor o contratista»).
- **Huésped:** nombre, número de habitación y si tiene reserva. No lleva gafete.
- **Servicio de Emergencia:** solo lo mínimo para no hacer esperar: unidad, tipo de emergencia y observaciones.

![Ingreso de un proveedor](img/accesos/nuevo-ingreso-proveedor.png)

Si escribes a mano un nombre que ya existe en el Padrón de personas, al guardar sale el aviso rojo «Ya existe una persona registrada con este nombre…»: toca **Sí, es la misma** para usar su registro, o **No, es alguien distinto** para crear otra persona.

## Cómo registrar a quien viene a Recursos Humanos

La caseta solo anota **a qué viene** la persona y avisa. Recursos Humanos decide si pasa y se encarga de lo demás (su solicitud, su puesto y su departamento).

1. En **Nuevo Ingreso** toca **Personal Externo / Visita** y llena su nombre, gafete e identificación como siempre.
2. En **Motivo de la Visita** toca **Recursos Humanos**.
3. En **¿A qué viene?** elige una opción:

| Opción | Cuándo usarla | Qué pasa |
|---|---|---|
| **Busca empleo** | Viene a pedir trabajo o a dejar su solicitud. | Si hay vacantes publicadas aparece **¿A qué vacante?**: elige la vacante o **-- No sabe / otra --**. |
| **Ya tiene cita** | Ya vino antes (o se postuló por internet) y hoy tiene cita: entrevista, entrega de papeles o firma de contrato. | La plataforma busca su ficha y anota sola a qué viene; no tienes que preguntarle. |
| **Otro trámite de RR. HH.** | Cualquier otro asunto con Recursos Humanos. | No se le abre ficha. |

Si solo pregunta qué vacantes hay, no registres nada: toca **Ver las vacantes publicadas** (aparece debajo de la lista cuando hay vacantes y tu rol puede verlas) y enséñaselas.

4. Toca **Registrar · queda pendiente de autorización** (o **Registrar y dejar pasar**, si tu empresa no pide que Recursos Humanos dé el paso).

![Ingreso de quien viene a Recursos Humanos](img/recepcion-y-candidatos/caseta-ingreso-candidato.png)

**Qué debes ver:** si tu empresa pide que Recursos Humanos dé el paso, el mensaje «Registrado: … espera a Recursos Humanos. Ya se avisó; aquí verás cuándo puede pasar» y la tarjeta en **Pendientes de Autorización** con **PENDIENTE** y «Espera a: Recursos Humanos · 0 min». Si tu empresa no lo pide, la persona pasa directo a **Gente en Sitio**.

La tarjeta **cambia sola** cuando Recursos Humanos contesta:

| La tarjeta dice | Qué hacer |
|---|---|
| **PENDIENTE** · «Espera a: Recursos Humanos · 5 min» | Pide a la persona que espere en caseta. |
| **PENDIENTE** · «RR. HH. pide que espere» | Recursos Humanos ya sabe que llegó, pero aún no puede recibirla. Que siga esperando. |
| **EN SITIO** · «RR. HH. dijo que pase» | Indícale cómo llegar a la oficina de Recursos Humanos. |
| **FINALIZADO** · «No autorizado por Recursos Humanos: no ingresó» (en el historial) | No puede pasar. Devuélvele su identificación. |

![Caseta esperando a Recursos Humanos](img/recepcion-y-candidatos/caseta-pendientes-esperando.png)

En la tarjeta verás, por ejemplo, **Candidato · Entrevista** o **Candidato · Firma de contrato** (lo que la plataforma dedujo de su cita). La caseta nunca ve la solicitud de empleo ni en qué paso va el proceso: eso solo lo ve Recursos Humanos.

> Si Recursos Humanos avisa por teléfono que puede pasar, el supervisor usa **Confirmar Autorización** en la tarjeta, como con cualquier visita.

## Qué pasa mientras alguien espera: reloj, color y escalamiento

Todo lo que espera tiene **reloj, color y suplente**. No tienes que vigilar nada: la plataforma avisa sola.

1. En **Pendientes de Autorización**, el renglón bajo la pastilla dice **a quién espera** y **cuántos minutos lleva**; el reloj avanza solo.
2. El color cambia con el tiempo de espera que configuró tu empresa (de fábrica, 10 y 20 minutos): **verde** al principio, **ámbar** cuando ya tardó y **rojo** cuando nadie ha contestado.
3. En ámbar, la plataforma **vuelve a avisar** a quien debe responder (campana y correo).
4. En rojo, **escala**: avisa a los suplentes del departamento (o a quien los cubre por «No molestar») y al Jefe de seguridad de la sede; si la persona espera a Recursos Humanos, al resto del equipo de RR. HH. y al Jefe de seguridad. La tarjeta agrega **«Se escaló a: …»** y a ti te llega el aviso a la campana.
5. Cada aviso se manda **una sola vez**; todo queda en la Bitácora de auditoría y en el historial de la autorización.

![Pendientes con reloj y color](img/accesos/pendientes.png)
![Tarjeta en rojo, ya escalada](img/accesos/tarjeta-espera-escalada.png)

**Qué debes ver:** el renglón pasa de verde a ámbar y a rojo, y, cuando se escaló, la caja de abajo dice «Se escaló a: Sergio Díaz y Julia Torres».

> Si te confirman por teléfono o en persona que puede pasar, tu supervisor (o el Jefe de seguridad) usa **Confirmar Autorización** en la tarjeta de la visita; en la de un proveedor o contratista, **Autorizar por excepción**. Los minutos se cambian en **Recursos Humanos → Recepción → Ajustes** (ver [Recepción y autorizaciones](recepcion-y-autorizaciones.md)).

## Cómo autorizar a un proveedor o contratista

Si tu empresa lo pide (de fábrica, sí), el proveedor o contratista **espera la autorización del responsable del departamento** al que viene, igual que una visita. La caseta no tiene que llamar a nadie.

1. Al registrarlo, elige **¿A qué departamento viene?** (el Host se sigue anotando).
2. Si el departamento tiene responsable en tu sede, le llega el aviso a su **campana** y a su **correo** con **Autorizar ingreso** y **Rechazar**. La tarjeta dice «Espera a: … (departamento)» con su reloj.
3. Cuando responde, **la tarjeta cambia sola**: pasa a **Gente en Sitio** («Autorizado por …») o al historial («No autorizado por …: no ingresó»; devuélvele su identificación). También te llega el aviso a la campana.

**Qué debes ver:** la tarjeta en **Pendientes de Autorización** con «Espera a: …» y, al responder el departamento, la persona en **Gente en Sitio**.

### Si no puede esperar: Autorizar por excepción

Solo quien tiene el permiso **Aprobar** (supervisor o Jefe de seguridad). Siempre se pide el motivo y queda anotado con tu nombre.

1. En la tarjeta, toca **Autorizar por excepción**.
2. Elige el **Motivo**. La lista solo trae los que aplican:
   - **El departamento no tiene responsable en esta sede** (no se pudo avisar a nadie).
   - **El responsable no contestó a tiempo** (ya se escaló o pasó el tiempo de espera de tu empresa).
   - **El responsable autorizó por teléfono**, **Urgencia operativa** u **Otro** (siempre disponibles).
3. Escribe el **Detalle** si ayuda (por ejemplo, «lo autorizó Juan Pérez a las 10:15»). Con **Otro** es obligatorio.
4. Toca **Autorizar por excepción** y confirma.

**Qué debes ver:** el mensaje verde «Autorizado por excepción — … ya puede ingresar (motivo)». La tarjeta pasa a **Gente en Sitio** con «Autorizado por excepción (motivo, tu nombre)». El responsable del departamento recibe el aviso en su campana y la solicitud se cierra para él.

### Si tu empresa no pide la autorización del departamento

Si en tu empresa la casilla **Proveedor** o **Contratista** no está marcada (ver [Recepción y autorizaciones](recepcion-y-autorizaciones.md)), funciona como antes:

1. Abre la pestaña **Pendientes de Autorización**.
2. Confirma con quien lo citó (el Host) que lo espera.
3. Toca **Confirmar Autorización** en su tarjeta.

![Pendientes de autorización](img/accesos/pendientes.png)

**Qué debes ver:** la tarjeta pasa a **Gente en Sitio** con la hora de autorización. Si no tienes el permiso, la tarjeta dice que tu supervisor confirma la autorización.

## Cómo dar salida

La forma más rápida es con el campo que está **siempre a la vista**, arriba de las tarjetas:

1. Cuando te devuelvan el gafete, **escanéalo** con la pistola, con la cámara (botón del código QR) o acércalo si es etiqueta NFC; o escribe el **nombre, las placas, el gafete o la habitación**.
2. Aparece la tarjeta de la persona con el botón **Dar salida**. Tócalo y confirma. Si la persona está fuera un rato (tour o salida temporal), el botón dice **Registrar regreso**.
3. El botón **Dar salida** de arriba (junto a **Nuevo Ingreso**) solo pone el cursor en ese campo; en el celular funciona igual.

![Dar salida con el campo a la vista](img/accesos/dar-salida.png)

También puedes dar salida desde la tarjeta: toca **Registrar Salida** (en huéspedes, **Salida Final**) y confirma. Y sigue existiendo el camino anterior: dentro de **Nuevo Ingreso** toca **Dar Salida** y busca a la persona.

**Qué debes ver:** la persona pasa a **Historial Finalizados** con la pastilla **FINALIZADO**. Sus acompañantes salen con ella, su gafete queda libre y su lugar de estacionamiento se libera. **Recoge el gafete y devuelve la identificación.**

Un acompañante puede salir antes con el botón **Salida** de su renglón.

## Cómo registrar una salida temporal o a tour

1. En huéspedes toca **Salida a Tour**; en proveedores y contratistas autorizados, **Salida Temporal**.
2. Si quieres, anota placas, marca y conductor. Guarda.
3. Cuando regrese, toca **Registrar Regreso**.

**Qué debes ver:** mientras está fuera, la pastilla dice **FUERA** con el detalle «Fuera en tour» o «Fuera temporal», y su gafete sigue reservado. En el historial, la pastilla **FINALIZADO** lleva el detalle «Salida a tour», «Salida temporal», «Regreso de tour» o «Regreso».

## Cómo cambiar la zona de estacionamiento

1. En una persona con vehículo toca **Cambiar Zona**.
2. Elige otra zona de la sede, o **Sin asignar (liberar)**. Guarda.

**Qué debes ver:** la tarjeta muestra la zona nueva en **Enviado a**.

## Cómo buscar y exportar el historial

1. Abre **Historial Finalizados**.
2. Escribe en el buscador (nombre, gafete, empresa, placas, host o el nombre de un **acompañante**) y elige tipo, sede y fechas **Desde / Hasta**.
3. Si tienes permiso, toca **Exportar a Excel** para bajar exactamente lo filtrado.

![Historial](img/accesos/historial.png)

**Qué debes ver:** solo los registros que coinciden. Si buscas a un acompañante, la tarjeta dice «Coincide con …, acompañante de …».

## En el celular y en los modos Sol y Noche

Todo funciona igual en el celular: el botón **Nuevo Ingreso** ocupa todo el ancho y los botones de la ventana quedan abajo, a la mano.

![Accesos en el celular](img/accesos/celular-agente.png)
![Pendientes en el celular](img/accesos/celular-pendientes.png)
![Nuevo ingreso en el celular](img/accesos/celular-nuevo-ingreso.png)
![Modo Noche](img/accesos/noche.png)
![Modo Sol](img/accesos/sol.png)

## Si algo sale mal

| Mensaje o síntoma | Qué significa | Qué hacer |
|---|---|---|
| «El gafete … ya está en uso (EN SITIO). Pide que lo devuelvan o elige otro.» | Ese gafete lo tiene alguien que sigue dentro. | Usa otro gafete o da salida a quien lo tiene. |
| «El gafete … está dado de baja.» | El gafete ya no se usa. | Elige otro. |
| «Indica quién citó al proveedor/contratista (Host).» | Falta el Host. | Escribe y elige al colaborador que lo espera. |
| «Indica a qué departamento viene.» | Tu empresa pide la autorización del departamento y no elegiste uno. | Elige el departamento en **¿A qué departamento viene?**. |
| «Este acceso espera la autorización del departamento. Si no puede esperar, toca «Autorizar por excepción» y elige el motivo.» | El proveedor o contratista espera a su departamento. | Espera la respuesta o usa **Autorizar por excepción**. |
| «Elige el motivo de la excepción.» / «Con «Otro» escribe el motivo de la excepción.» | Falta el motivo o su detalle. | Elige un motivo; con **Otro**, escribe cuál. |
| «Ese motivo no aplica a este acceso: elige uno de la lista.» | Por ejemplo, el departamento sí tiene responsable o aún no pasa el tiempo de espera. | Elige otro motivo de la lista. |
| «La empresa «…» está dada de baja (vetada) en Proveedores: no puede ingresar.» | La empresa externa tiene prohibido el acceso. | No lo dejes pasar; avisa a tu supervisor. |
| «El colaborador elegido no existe, está dado de baja o no pertenece a esta sede.» | El colaborador no trabaja en esa sede. | Revisa la sede o usa el alta provisional. |
| «Las placas solo llevan letras y números (de 2 a 20).» | Las placas tienen símbolos o están incompletas. | Escríbelas sin guiones ni espacios. |
| «Este acceso ya no está pendiente de autorización (alguien más lo atendió).» | Otro compañero ya lo autorizó. | Revisa **Gente en Sitio**. |
| No sale el botón **Confirmar Autorización** | Tu rol no puede aprobar. | Pide a tu supervisor que lo autorice. |
| «Elige a qué viene de la lista.» | La opción de **¿A qué viene?** no es válida. | Vuelve a elegirla de la lista. |
| «Elige una vacante publicada de esta sede.» | Esa vacante ya no está publicada o es de otra sede. | Elige otra o **-- No sabe / otra --**. |
| La tarjeta **PENDIENTE** se pone en rojo | Nadie ha contestado en el tiempo que configuró tu empresa. | La plataforma ya escaló a los suplentes y al Jefe de seguridad (la tarjeta dice «Se escaló a: …»). Si te confirman por teléfono, tu supervisor usa **Confirmar Autorización**. |
| Escaneo el gafete para dar salida y no sale nadie | Ese gafete no lo tiene nadie en sitio, o la persona está en otra sede. | Busca por nombre o placas; revisa el filtro de sede. |
| Al dar salida el botón dice **Registrar regreso** | La persona está fuera en tour o en salida temporal. | Tócalo para registrar su regreso; después podrás dar la salida final. |

## Preguntas frecuentes

- **¿Por qué el proveedor no aparece en Gente en Sitio?** Está en **Pendientes de Autorización** hasta que lo autorice su departamento (o tu supervisor por excepción).
- **El jefe del departamento me autorizó por teléfono, ¿qué hago?** Pide a tu supervisor que toque **Autorizar por excepción** con el motivo «El responsable autorizó por teléfono». Así queda anotado quién y por qué.
- **Alguien dice que tiene cita, pero es su primera vez.** Elige **Ya tiene cita** de todos modos: si no se encuentra su ficha, Recursos Humanos lo recibe como «Busca empleo» y queda anotado.
- **¿Y si viene a entregar papeles o a firmar?** También es **Ya tiene cita**: la plataforma sabe en qué paso va y le dice a Recursos Humanos si viene a su entrevista, a entregar documentos o a firmar.
- **¿Tengo que preguntarle el puesto o el departamento?** No. Solo a qué viene y, si busca empleo, a qué vacante (si la sabe). Lo demás lo pone Recursos Humanos.
- **El colaborador no está en la lista.** Usa **¿No aparece? Darlo de alta provisional**; Recursos Humanos lo valida después.
- **Me equivoqué de zona.** Usa **Cambiar Zona**.
- **¿Cómo sé quién registró a alguien?** En la tarjeta, junto a la hora de ingreso, dice «por …».
- **¿Puedo usar la cámara del celular para el gafete?** Sí: toca el botón del código QR junto al campo, tanto al registrar como al dar salida.
- **¿Por qué el botón de registrar cambia de texto?** Para que sepas qué va a pasar antes de tocarlo: «dejar pasar» o «queda pendiente de autorización».
- **¿Quién decide los minutos del ámbar y el rojo?** Quien configura Recepción o Autorizaciones en tu empresa, en **Recursos Humanos → Recepción → Ajustes**.

## Relacionado

- [Préstamo de llaves](prestamo-llaves.md)
- [Pases de salida](pases-salida.md)
- [Bitácora de novedades](novedades.md)
- [Gafetes](gafetes.md)
- [Padrón de personas](personas.md)
- [Recepción y autorizaciones](recepcion-y-autorizaciones.md)
