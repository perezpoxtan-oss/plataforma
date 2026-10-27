---
titulo: Préstamo de llaves
modulos: [prestamo_llaves]
seccion: Operación
orden: 20
resumen: Prestar una llave a un colaborador, a una persona externa o a una empresa, recibirla cuando la regresa y marcar las que no regresaron.
---
# Préstamo de llaves

**¿Para qué sirve?** Aquí la caseta anota **quién se lleva una llave** (un colaborador, el técnico de un proveedor o una empresa externa), qué identificación deja **en garantía** y **cuándo la regresa**. Así siempre sabes dónde está cada llave, y la que no regresa queda marcada para cobrarla a quien la tenía.

**Antes de empezar:** necesitas que tu rol pueda **ver** y **crear** en Préstamo de llaves. Las llaves se dan de alta antes en el [Catálogo de llaves](llaves.md); aquí solo se prestan y se reciben. Si no ves el botón **Prestar Llave**, pide a tu administrador que revise tu rol.

## Cómo leer la pantalla

1. Entra a **Operación → Caseta → Préstamo de llaves**.
2. Tienes dos pestañas:

| Pestaña | Qué ves |
|---|---|
| **Llaves en Uso** | Las llaves que están **fuera** ahora (borde ámbar, **LLAVE FUERA**). |
| **Historial de Entregas** | Las que ya regresaron (**EN CASETA**, borde verde) y los préstamos **ANULADOS** (gris). |

![Llaves en uso](img/prestamo-llaves/llaves-en-uso.png)

**Qué debes ver:** en cada ficha, el colaborador y su número de nómina, el nombre de la llave en rojo, lo que abre, la garantía que dejó y a qué hora salió y quién se la entregó.

## Cómo prestar una llave

1. Toca **Prestar Llave**. El cursor ya está en **Llave a Prestar**.
2. Revisa la **Sede**; si solo tienes una, ya viene elegida.
3. **Escanea la llave**: el QR del llavero con la cámara, su etiqueta NFC, el lector USB, o escribe su nombre (por ejemplo `HDC-101`) y elígela.
4. En **Solicitante (¿a quién se le presta?)** deja **Colaborador** y **escanea su gafete**, o escribe su número de nómina o su nombre y elígelo. Si es alguien de fuera, toca **Persona externa** o **Empresa externa** (ver «Cómo prestar a alguien de fuera»).
5. Elige la **ID Dejada en Garantía**: Gafete interno, INE, Licencia, Pasaporte o **Ninguna (Riesgo)**. Con **Gafete interno**, el **Folio** se llena solo con el número del gafete que escaneaste; con las demás, si quieres, escribe el folio o un detalle.
6. Toca **Registrar y Capturar Siguiente**.

![Prestar una llave](img/prestamo-llaves/prestar.png)

**Qué debes ver:** el aviso verde «Llave … entregada a …». La ventana **no se cierra**: queda limpia para la siguiente persona. Cuando termines, toca **Cerrar**. La llave aparece en **Llaves en Uso**.

Reglas: solo se prestan llaves **activas**, de la **sede elegida**, que **no estén fuera** y que **no estén marcadas «No regresó»**. El colaborador debe estar activo y trabajar en esa sede (un colaborador provisional dado de alta en caseta también puede llevarse una llave).

## Cómo prestar a alguien de fuera

Para el técnico del elevador, un contratista o una empresa que se hace responsable:

1. En **Solicitante (¿a quién se le presta?)** toca **Persona externa** (alguien del [Padrón de personas](personas.md), por ejemplo el personal de un proveedor) o **Empresa externa** (ver [Empresas externas](proveedores.md)).
2. Escribe al menos dos letras de su nombre y toca su renglón.
3. Sigue con la garantía y toca **Registrar y Capturar Siguiente**.

**Qué debes ver:** la ficha dice su nombre y su empresa, por ejemplo «Juan Pérez (Elevadores del Sur)». Si no aparece en la lista, regístralo primero en la caseta o en el padrón. Quien tiene el **acceso restringido** aparece en gris y no se puede elegir.

## Si la llave no cumple sus reglas

En el [Catálogo de llaves](llaves.md) una llave puede tener **fecha de caducidad**, **horarios**, un **departamento o puesto** al que va dirigida o un **responsable permanente**. Si al prestarla no se cumple alguna, aparece un aviso amarillo, por ejemplo «La llave HDC-101 no cumple sus reglas: está VENCIDA desde el 10/10/2026».

1. Si de verdad se debe prestar, escribe el motivo en **Prestar de todos modos: motivo** (por ejemplo, quién lo autorizó).
2. Toca **Registrar y Capturar Siguiente** otra vez.

**Qué debes ver:** la ficha dice **Prestada por excepción: …** y el motivo queda en el historial de cambios.

## Si la llave no regresó

1. En su ficha de **Llaves en Uso** toca **No regresó** y confirma.
2. Si tu rol puede dar de baja llaves, se abre el [Catálogo de llaves](llaves.md) con su baja lista: el voucher ya propone a quien la tenía. Si no, tu supervisor recibe el aviso en la **campana** con el botón **Dar de baja**.

![Llave que no regresó](img/prestamo-llaves/no-regreso.png)

**Qué debes ver:** la ficha queda con la etiqueta naranja **NO REGRESÓ** y la llave ya no se puede prestar. Si después aparece, recíbela como siempre (escaneándola o con **Recibir Llave a Caseta**): vuelve a estar disponible y el aviso se quita. Si escaneas su QR o su etiqueta NFC desde cualquier pantalla, la tarjeta dice **No regresó** y te ofrece **Recibir** (nunca «Prestar»).

## Cómo recibir una llave

**Lo más rápido: escanéala.**

1. Arriba de las pestañas está **Recibir: escanea la llave que te devuelven**.
2. **Escanea la llave**: el QR del llavero con la cámara (botón **QR**), su etiqueta NFC (botón de la **antena**), el lector USB, o escribe su nombre y toca **Recibir**.
3. **Devuelve la identificación** que dejó en garantía: el aviso verde te dice cuál.

![Recibir escaneando la llave](img/prestamo-llaves/recibir-escaneando.png)

**Desde la ficha:**

1. Busca su ficha en **Llaves en Uso** (puedes escribir el nombre, la nómina o la llave en **Buscar**).
2. Toca **Recibir Llave a Caseta** y confirma.
3. **Devuelve la identificación** que dejó en garantía.

**Qué debes ver:** el aviso «Llave … recibida de … Devuelve su garantía: …» y la ficha pasa a **Historial de Entregas** con la hora de regreso y quién la recibió.

También puedes escanear el QR del llavero **con la cámara del celular, fuera de la plataforma**: si la llave está prestada te ofrece **Recibir**, y si está en caseta, **Prestar** (ver [Etiquetas QR](etiquetas-qr.md)).

## Llaves que llevan mucho tiempo fuera

Cada ficha de **Llaves en Uso** dice **Fuera hace …** y cambia de color. Viene encendido: ámbar a las **12 horas** y rojo a las **24** (tu empresa lo cambia o lo apaga en **Estructura → Configuración → Activos**):

| Color | Qué significa | Quién recibe el aviso |
|---|---|---|
| Verde | Está en tiempo. | Nadie. |
| **Ámbar** | Pasó las horas del recordatorio: «pide que la regresen». | La caseta de esa sede, en la **campana** y en **Mis pendientes → Llaves fuera de tiempo**. |
| **Rojo** | Pasó las horas límite: «pasó del límite». | Además, el jefe de seguridad de la sede. Si la llave ya estaba en rojo la primera vez que se revisa, llegan los dos avisos: a la caseta y al jefe. |

![Llave fuera de tiempo](img/prestamo-llaves/semaforo.png)

Al recibir la llave, los avisos se quitan solos. Si tu empresa lo apagó (0 horas), las fichas se ven sin color.

![Historial de entregas](img/prestamo-llaves/historial-entregas.png)

## Cómo anular un préstamo capturado por error

1. En la ficha, toca el botón gris **⊘** (Anular préstamo) y confirma.
2. Si después necesitas deshacerlo, en el historial toca **Reactivar**.

**Qué debes ver:** la llave queda disponible de inmediato y el préstamo aparece como **ANULADO**. Solo se anula una llave **en uso**; esta opción la tienen el Jefe de seguridad y el Administrador.

## Cómo ver el historial de una llave

1. Toca el **reloj** junto al nombre de la llave.
2. Revisa sus últimos movimientos: quién la usó, quién la entregó y quién la recibió.

![Historial de una llave](img/prestamo-llaves/historial-llave.png)

**Qué debes ver:** si la llave sigue fuera, dice **AÚN EN USO**.

## Cómo descargar el reporte

1. Toca **Excel (Auditoría)**.
2. Si elegiste una sede en el filtro, solo baja esa sede.

**Qué debes ver:** un archivo de Excel (CSV) con folio, sede, llave, colaborador, garantía, salida, regreso, guardias y estado.

## En el celular

La pantalla se acomoda al celular: una ficha por renglón y la ventana de préstamo a pantalla completa.

![Préstamo de llaves en el celular](img/prestamo-llaves/celular.png)

## Si algo sale mal

| Mensaje o síntoma | Qué significa | Qué hacer |
|---|---|---|
| «Esa llave ya está fuera — la tiene … en este momento.» | Alguien no la ha regresado. | Pide que la regresen y recíbela antes de prestarla otra vez. |
| «Esa llave no está prestada: ya está en caseta.» | Escaneaste para recibir una llave que nadie tiene. | Revisa que sea la llave correcta. |
| «No encontré ninguna llave con «…».» | La etiqueta no se leyó bien o la llave no está en el catálogo. | Vuelve a escanear o escribe su nombre completo. |
| «Esa llave está prestada en una sede donde no puedes recibir llaves.» | Es de otra caseta. | Que la reciba la caseta de su sede. |
| «La llave … es de otra sede. Elige la sede correcta o escanea otra llave.» | La llave no es de la sede elegida. | Cambia la sede o usa otra llave. |
| «La llave … está dada de baja: no se puede prestar.» | La llave ya no se usa. | Avisa a tu supervisor. |
| «La llave … está marcada «No regresó»…» | Se marcó que no regresó. | Si ya apareció, recíbela; si no, usa otra. |
| «La llave … no cumple sus reglas: …» (aviso amarillo) | Está vencida, fuera de horario, es para otro puesto o tiene responsable permanente. | Préstala solo si se autorizó: escribe el motivo. |
| ««…» tiene el acceso restringido: no se le puede entregar nada.» | Esa persona o empresa está restringida. | Llama a tu supervisor. |
| «Escanea el gafete o busca al colaborador que se lleva la llave.» | Falta el colaborador. | Escanea su gafete o búscalo. |
| «Elige la identificación que deja en garantía (o «Ninguna»).» | Falta la garantía. | Elige una opción de la lista. |
| «No se puede reactivar — esa llave ya se volvió a prestar…» | La llave ya tiene otro préstamo. | Deja el préstamo anulado. |
| «No se puede reactivar — la llave … ahora está NO REGRESÓ (o DE BAJA)…» | Mientras tanto la llave no regresó o se dio de baja. | Si ya regresó, recíbela primero; si no, deja el préstamo anulado. |
| No veo el botón **⊘** | Tu rol no puede anular. | Pide a tu jefe de seguridad que lo anule. |

## Preguntas frecuentes

- **¿Puedo prestar varias llaves seguidas?** Sí: la ventana se queda abierta con **Registrar y Capturar Siguiente**.
- **¿Y recibir varias seguidas?** Sí: escanea una tras otra en **Recibir: escanea la llave que te devuelven**.
- **¿Quién decide cuántas horas puede estar fuera una llave?** El administrador, en **Configuración → Caseta y activos** (ver [Configuración](configuracion.md)).
- **¿Qué pasa si el colaborador no deja identificación?** Elige **Ninguna (Riesgo)**; queda anotado.
- **¿Dónde veo quién tiene una llave desde el catálogo?** En el [Catálogo de llaves](llaves.md) la llave dice **EN USO por …**.
- **¿Se le puede prestar a un proveedor?** Sí: a una persona del padrón o a una empresa externa (ver «Cómo prestar a alguien de fuera»).
- **¿Qué hago con una llave que se perdió?** Márcala **No regresó**; después se da de baja con su voucher a nombre de quien la tenía.
- **¿Puedo borrar un préstamo?** No; se anula para que quede constancia.

## Relacionado

- [Catálogo de llaves](llaves.md)
- [Vouchers de reposición](vouchers.md)
- [Bitácora de accesos](accesos.md)
- [Configuración](configuracion.md)
