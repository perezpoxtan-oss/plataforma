# Criterios de experiencia de uso

Ocho reglas aprobadas por el dueño del proyecto el 2026-10-11. Son **obligatorias** para todo módulo nuevo y para todo cambio de flujo en un módulo existente. Quien abre un PR que toca una pantalla o un flujo indica en su descripción cómo cumple cada regla que le aplica; la revisión no se aprueba sin eso.

## 1. Todo lo que espera respuesta llega a la campana y a Mis pendientes, y quien lo pidió se entera del resultado

Si una acción queda esperando a alguien, esa persona la ve en la **campana** y en **Mis pendientes** (con su número y su enlace); nunca se descubre «de casualidad» entrando a una lista. Cuando se resuelve, quien lo pidió recibe el resultado por el mismo camino.

Ejemplos en la plataforma:
- Una **autorización de visita a departamento** llega a la campana del responsable (o de su delegado) con los botones Autorizar / Rechazar, y la caseta ve la respuesta en la Bitácora de accesos y en su campana.
- Un **pase de salida** que espera firma aparece en «Firmas de pases de salida» de Mis pendientes de quien debe firmar; el solicitante recibe «aprobado» o «rechazado (motivo)».
- Una **cuenta bloqueada** por intentos fallidos avisa por campana a quien administra usuarios de esa sede, y el afectado se entera por correo.

## 2. Un dato se captura una sola vez; donde se repita, se sugiere

Si la plataforma ya conoce un dato, no se vuelve a pedir: se llena solo o se ofrece como sugerencia que el usuario confirma. Nunca dos capturas del mismo dato en dos pantallas.

Ejemplos:
- Al vincular una cuenta de **Usuarios** con su colaborador, el nombre y el número de colaborador se toman de **Colaboradores**.
- La caseta captura una **placa** una vez: la siguiente vez que llega ese vehículo la Bitácora de accesos propone «¿Es alguno de estos?».
- Al **contratar** un candidato, sus datos oficiales se copian al colaborador nuevo en vez de recapturarse.

## 3. Todo lo que espera tiene reloj, color y suplente

Lo que está esperando a alguien muestra cuánto lleva esperando (reloj), cambia de color conforme se alarga (verde → ámbar → rojo) y tiene un camino de salida: **recordatorio** a los X minutos y **escalamiento** al suplente o al jefe a los Y minutos. Los tiempos X e Y se configuran por empresa (o por departamento), no se escriben en código.

Ejemplos:
- Una visita que espera autorización del departamento muestra los minutos en la Bitácora de accesos, cambia de color según el tiempo de espera configurado y, si nadie responde, pasa al **delegado** («No molestar») o al jefe.
- Los **pases de salida vencidos** se recuerdan cada mañana a sus responsables.
- Los **procedimientos** sin acuse «Leí y entendí» se recuerdan cada tres días a quien falta.

## 4. Un vocabulario de diez verbos para toda la plataforma

Los botones y las acciones usan solo estos verbos: **Guardar, Dar de baja, Reactivar, Imprimir, Dar salida, Recibir, Aprobar, Rechazar, Atender, Canalizar.** Mismo verbo, misma acción, en todas las pantallas. Sin jerga ni siglas: nada de «CXC», «falla de fletera», «indexar», «endpoint», «registrar movimiento».

Ejemplos:
- El voucher con cobro dice «con cobro a la persona responsable», no «CXC».
- En Pases de salida la caseta **Da salida** y en el destino se **Recibe**; no «registra movimiento de salida».
- En Candidatos, Recursos Humanos **Atiende** al candidato y lo **Canaliza** al departamento; el jefe **Aprueba** o **Rechaza**.

## 5. El mensaje de éxito ofrece el siguiente paso natural

Después de guardar, el aviso verde no solo dice «Guardado»: propone lo que la persona hará a continuación, con su botón o enlace, para que no tenga que buscarlo.

Ejemplos:
- Al dar de alta un usuario con enlace de bienvenida: «Usuario creado; le enviamos el correo para que cree su contraseña. ¿Quieres dar de alta otro?».
- Al cerrar un préstamo de llave: «Llave recibida. Prestar otra llave».
- Al cambiar tu contraseña desde Mi cuenta: «Tu contraseña cambió y cerramos tus otras sesiones. Volver al inicio».

## 6. La cuenta es de su dueño

Cada persona **recupera y cambia su contraseña sin depender de nadie**, y nadie más la conoce: ni quien la dio de alta ni quien la restablece. La plataforma solo guarda la contraseña cifrada y nunca la muestra.

Ejemplos:
- «**Olvidé mi contraseña**» en la pantalla de acceso manda un enlace al correo de la cuenta; el administrador no interviene.
- Al dar de alta un usuario, la opción por omisión es «**Enviar enlace de bienvenida por correo**»: la persona crea su contraseña; el administrador nunca la ve.
- Si el administrador escribe una contraseña inicial, la cuenta queda marcada y la persona la **cambia en su primer acceso** antes de usar cualquier pantalla.

## 7. Lo complejo queda en «avanzado»

La pantalla muestra lo que el 80 % de las veces se necesita; el resto va en un apartado **Avanzado** plegado o en un asistente de pocas preguntas con plantillas. Nadie tiene que entender todas las opciones para hacer lo común.

Ejemplos:
- Al imprimir **etiquetas QR** se elige una plantilla (rollo, hoja carta…); las medidas en milímetros están en Avanzado.
- El **circuito de aprobación** de Pases de salida trae una cadena predeterminada; cambiar pasos, roles y motivos es configuración avanzada.
- El **registro inteligente de ingreso** pregunta solo lo que ese tipo de visita necesita; el resto queda plegado.

## 8. Alta corta con lo indispensable y «Completar después» en la ficha

Dar de alta pide **solo lo indispensable** para empezar a operar. Todo lo demás se completa después desde la ficha, donde la pantalla señala lo que falta.

Ejemplos:
- El **alta provisional** de un colaborador desde la caseta pide nombre, apellido y sede; Recursos Humanos completa el número de empleado y lo valida después.
- El **registro rápido** de vehículos, personas y empresas externas nace «pendiente de verificar» con los datos mínimos; el padrón lo completa quien lo administra.
- La **Solicitud de empleo** se guarda por secciones; el candidato o Recursos Humanos pueden completarla más tarde.

## Cómo se aplica en cada entrega

1. Antes de diseñar una pantalla o un flujo, se revisa esta lista y se anota en el PR qué reglas aplican y cómo se cumplen.
2. Un botón con un verbo fuera de la lista del punto 4 no se integra.
3. Un flujo que deja algo «esperando» sin campana, Mis pendientes, reloj y suplente (puntos 1 y 3) no se integra.
4. Las excepciones las aprueba el dueño del proyecto y quedan anotadas en el documento técnico del módulo.
