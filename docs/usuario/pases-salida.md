---
titulo: Pases de salida
modulos: [pases_salida]
seccion: Operación
orden: 30
resumen: Pedir, aprobar y registrar en caseta la salida de equipo de la sede, y su regreso.
---
# Pases de salida

**¿Para qué sirve?** Para autorizar y seguir todo equipo que **sale de la sede**: un préstamo, una venta, una reparación, un traspaso a otra sede. Cada pase pasa por **aprobaciones** (en orden) y por la **caseta**; si el equipo debe volver, el pase no se cierra hasta que regresa.

**Antes de empezar:** según lo que hagas necesitas un permiso distinto en Pases de salida: **crear** (pedir pases), **aprobar** (firmar aprobaciones), **firmar** (caseta: salida, llegada y regreso) e **imprimir**. Si no ves un botón, tu rol no tiene ese permiso: pide ayuda a tu administrador.

## Cómo leer la lista

1. Entra a **Operación → Caseta → Pases de salida**.
2. Cada ficha tiene el **folio** (por ejemplo `PS-000123`), una **etiqueta de color** con el estado, el solicitante, el motivo y la **línea de pasos**: **Solicitud → Aprobaciones → Salida → En destino / Fuera → Regreso → Cerrado**.
3. El recuadro azul dice **quién debe actuar ahora** (por ejemplo «Espera la firma de: Contraloría (paso 2 de 3)»).
4. Usa las **píldoras** para filtrar: **Pendientes de mi firma**, **Vencidos**, **Fuera de la propiedad**, **Llegan a mi sede**, etc. En **Buscar** escribe el folio, el solicitante, el equipo o su serie, o escanea el QR de la hoja impresa.

![Lista de pases](img/pases-salida/lista.png)

**Qué debes ver:** los pases que te tocan llevan la etiqueta azul **Espera tu firma** y borde azul. Debajo del recuadro azul, el **reloj** dice cuánto lleva esperando la firma del paso actual (por ejemplo «Esperando firma desde hace 7 h»). Si tu empresa puso tiempos en **Firmas que tardan**, el reloj se pinta en **verde**, **ámbar** (ya se recordó a quien firma) o **rojo** (ya se avisó al siguiente nivel).

![Reloj de la firma que espera](img/pases-salida/reloj-firma.png)

| Etiqueta | Qué quiere decir |
|---|---|
| **Pendiente de Aprobación** | Le faltan firmas de aprobación. |
| **Rechazado — devuelto al solicitante** | Hay que corregirlo y reenviarlo, o cancelarlo. |
| **Aprobado, listo para salir** | La caseta ya puede registrar la salida. |
| **Salió — Espera Regreso** | Ya salió y debe volver. |
| **Regreso parcial — faltan artículos** | Regresó una parte; falta lo demás. |
| **Vencido — Debió Regresar** | Pasó su fecha de regreso y no ha vuelto. ¡Dale seguimiento! |
| **Regresado / Cerrado** | Terminó. |
| **Cancelado** | Se canceló antes de aprobarse. |

## Cómo pedir un pase nuevo

1. Toca **Nuevo Pase**. Si trabajas en una sola sede, la **Sede de Origen** ya viene elegida. Si tu rol es **Solicitante** o **Jefe de departamento**, tu nombre ya viene como **Solicitante** (solo puedes pedir pases a tu nombre).
2. En **1. Motivo y Solicitante**: elige el **motivo** (la ventana te dice si el equipo debe regresar). Escanea el gafete del solicitante o escribe su nombre y elígelo. Si no aparece, toca **Nuevo Colaborador**.
3. En **2. Enviar A**: elige otra sede, un proveedor (si no está, **Nuevo Proveedor**) o un colaborador que se lo lleva. Si el equipo regresa, escribe la **Fecha Tentativa de Regreso**.
4. En **3. Artículos que Salen**: escanea la etiqueta del equipo del padrón (se llena solo) o escribe cantidad, equipo, marca, modelo, serie y descripción. Si tu empresa pide aprobaciones según el valor, verás también **Valor por pieza $ (opcional)**: escríbelo si lo conoces (los equipos del padrón con costo registrado ya traen su valor). Si lo dejas vacío, el pase pedirá todas las aprobaciones.
5. Toca **Guardar y Enviar a Aprobación** (o **Registrar y capturar siguiente** si harás otro).

![Nuevo pase de salida](img/pases-salida/nuevo-pase.png)

**Qué debes ver:** el pase aparece como **Pendiente de Aprobación** y quien firma el primer paso recibe un aviso en la **campana** (y por correo). Cada apartado (1, 2, 3) se puede cerrar tocando su título; si falta algo, se abre solo.

## Cómo aprobar o rechazar un pase

1. Toca el aviso **Te toca firmar el pase PS-…** de la **campana** (botón **Abrir pase**), o **Mis pendientes** (arriba), o la píldora **Pendientes de mi firma**, y abre el pase.
2. Revisa el **Resumen** y la pestaña **Artículos**.
3. Toca **Aprobar**. Firma con **Usar mi firma guardada** o **Firmar ahora** en el recuadro (marca «Guardar mi firma» para la próxima vez). Si quieres, escribe un comentario.
4. Toca **Aprobar y firmar**.
5. Si no estás de acuerdo, toca **Rechazar** y escribe el **motivo** (obligatorio).

![Ficha del pase](img/pases-salida/ficha.png)
![Aprobar con firma](img/pases-salida/aprobar.png)

**Qué debes ver:** el pase pasa al siguiente paso y la siguiente persona recibe su aviso. El aviso de la campana de los demás que podían firmar ese paso queda leído y sin botón. Si rechazaste, regresa al solicitante con tu motivo en rojo.

Reglas: los pasos van **en orden**; no puedes aprobar tu propio pase ni firmar dos pasos del mismo pase. Un paso **opcional** que no aplica se puede saltar con **Omitir paso**. Si un paso dice **solo con valor de $50,000.00 o más** (u otra cantidad), se pide solo a los pases de ese valor.

## Cómo corregir un pase rechazado

1. En la **campana** te llega **Tu pase PS-… fue rechazado** con el motivo. Ábrelo: el motivo del rechazo sale en rojo.
2. Toca **Corregir y reenviar**, cambia lo que te pidieron y escribe qué corregiste.
3. Toca **Guardar y Reenviar a Aprobación**. Si ya no se necesita, toca **Cancelar pase**.

**Qué debes ver:** según lo que configuró tu empresa:

- **Empezar desde el primer paso** (lo normal): las aprobaciones empiezan otra vez desde el paso 1.
- **Retomar desde el paso que rechazó**: las firmas que ya tenías se conservan (en la lista dicen «firma conservada del envío anterior») y el pase vuelve al paso que lo rechazó. Si al corregir cambiaste el motivo o el valor y ahora aplica otro paso, también se pide. Si ya no falta ninguna firma, queda **Aprobado, listo para salir**.

Cuando se aprueba el último paso te llega **Tu pase PS-… fue aprobado**.

## Cómo registrar en caseta la salida, la llegada y el regreso

1. Abre el pase (aparece en **Mis pendientes**) y toca el botón de la caseta: **Registrar Salida** (sede de origen), **Confirmar llegada a esta sede** (sede destino, cuando llega el equipo), **Autorizar salida de regreso** (sede destino, cuando el equipo vuelve a su sede) o **Registrar Regreso** (sede de origen).
2. **Verifica los artículos**: escanea cada equipo del padrón o márcalo con el dedo. Para la salida deben estar **todos**. Esto se pide siempre.
3. Escribe el **nombre** de quien se lleva o entrega el equipo y pídele que **firme** en el recuadro.
4. Firma tú como Seguridad (con tu firma guardada es un solo toque) y guarda.
5. En el **regreso**, escribe cuántos regresan de cada artículo. Si lo que falta ya no volverá, marca **Cerrar el pase aunque falten artículos**.

Tu empresa puede pedir menos firmas en algún movimiento. La ventana te lo dice:

- «**En este pase, este movimiento solo pide la firma de Seguridad.**»: no hay recuadro para la otra persona; su nombre es opcional.
- «**Este movimiento no pide firmas**…»: solo revisas los artículos y guardas. Queda registrado que lo hiciste tú, la hora y lo que revisaste.

![Caseta: registrar la salida](img/pases-salida/caseta-salida.png)

**Qué debes ver:** la línea de pasos avanza. Si faltan artículos en el regreso, queda **Regreso parcial** y sigue esperando lo demás.

## Cómo saber qué pases llegan a tu sede (caseta destino)

1. En **Pases de salida** toca la píldora **Llegan a mi sede**.
2. Verás los pases aprobados o en camino hacia tu sede. Cada uno dice qué harás: «**Viene a Hotel Playa: aún no sale de Hotel Centro.**» o «**En camino a Hotel Playa.**» y «**Cuando llegue, toca «Confirmar llegada a esta sede».**».
3. Cuando el pase sale hacia tu sede, te llega a la **campana** el aviso **Un pase viene a tu sede: PS-…**.
4. Cuando llegue el equipo, abre el pase y toca **Confirmar llegada a esta sede**.

![Llegan a mi sede](img/pases-salida/llegan-a-mi-sede.png)

**Qué debes ver:** al confirmar la llegada, el pase sale de **Llegan a mi sede** y el aviso de la campana queda leído. La píldora solo la ve quien registra la caseta.

## Cómo imprimir y verificar un pase

1. En la ficha toca **Imprimir Pase**. Sale una hoja carta con el folio, un **código QR**, los artículos y las firmas.
2. En la caseta, escanea el QR con el celular: se abre una página que dice si el **pase es auténtico** y su estado en ese momento.

![Hoja impresa del pase](img/pases-salida/imprimir.png)

**Qué debes ver:** las firmas que faltan quedan con línea para firmar a mano. Si un movimiento de caseta no pide alguna firma, la hoja no trae ese renglón; si no pide ninguna, dice «**Sin firma: se registra en la plataforma.**» y, ya hecho, quién lo registró y cuándo.

## Cómo configurar quién aprueba (administrador)

1. Toca **Circuito** en la lista (o **Estructura → Configuración → Pases de salida**).
2. Para cada paso escribe su **nombre** (por ejemplo «Contraloría») y elige **quién firma**: cualquiera con permiso Aprobar, los usuarios de un **rol**, o **usuarios específicos**.
3. Indica si es **obligatorio** y a qué **motivos** aplica. Usa las flechas para el orden.
4. Toca **Guardar circuito**.

**Qué debes ver:** los pases nuevos siguen el circuito nuevo; los que ya están en curso conservan sus pasos.

Si no cambias nada, todo funciona como siempre: Jefe de Departamento, Contraloría y Gerencia; dos firmas en cada movimiento de caseta; y el rechazo empieza desde el primer paso.

**Para regresar a lo original:** toca **Restablecer la configuración original** al final de la pantalla y confirma. Verás «Se restableció la configuración original…»: vuelven los tres pasos, las dos firmas en caseta y el rechazo desde el primer paso. Los pases que ya están en curso no cambian.

## Cómo pedir un paso solo según el valor del equipo (administrador)

1. En **Circuito**, en el paso que quieres (por ejemplo «Gerencia»), abre **Condición de valor (avanzado)**.
2. En **Aplica si el valor es mayor o igual a $** escribe la cantidad en pesos, por ejemplo `50000`. Si quieres un tope, escríbelo en **y menor a $**.
3. Toca **Guardar circuito**.

![Condición de valor de un paso](img/pases-salida/circuito-valor.png)

**Qué debes ver:** el paso dice «solo con valor de $50,000.00 o más». Al pedir un pase aparece **Valor por pieza $** en cada artículo. El valor total del pase es la suma de cantidad × valor. Si algún artículo no tiene valor, el paso **sí** se pide (por seguridad). Cada motivo necesita al menos un paso obligatorio sin condición de valor.

## Cómo elegir qué pasa al corregir un rechazado (administrador)

1. En **Circuito**, en **Al corregir y reenviar un pase rechazado**, elige **Empezar desde el primer paso** (lo normal) o **Retomar desde el paso que rechazó**.
2. Toca **Guardar circuito**.

**Qué debes ver:** aplica a los pases que se reenvíen desde ese momento.

## Cómo elegir las firmas de la caseta (administrador)

1. En **Circuito**, baja a **Firmas en caseta**.
2. Para cada movimiento (**Registrar Salida**, **Confirmar llegada a esta sede**, **Autorizar salida de regreso**, **Registrar Regreso**) elige: **Dos firmas (persona y Seguridad)**, **Solo Seguridad** o **Sin firma**.
3. Si un motivo debe ser distinto, abre **Excepciones por motivo (avanzado)** y elige las firmas en el renglón de ese motivo. Deja **Igual que todos** en lo demás.
4. Toca **Guardar circuito**.

![Firmas en caseta](img/pases-salida/circuito-firmas-caseta.png)

**Qué debes ver:** los pases nuevos piden esas firmas; los que ya están en curso conservan las que tenían. La revisión de los artículos se pide siempre. En la ficha del pase, el **Resumen** dice qué movimientos piden menos firmas.

## Cómo avisar cuando una firma tarda (administrador)

1. En **Circuito**, baja a **Firmas que tardan**.
2. En **Recordar a quien debe firmar después de** escribe un número y elige **horas** o **días** (por ejemplo 4 horas).
3. En **Avisar al siguiente nivel después de** escribe un tiempo mayor (por ejemplo 1 día).
4. Toca **Guardar circuito**.

![Firmas que tardan](img/pases-salida/circuito-firmas-que-tardan.png)

**Qué debes ver:** cuando un paso lleva ese tiempo sin firma, a quien debe firmarlo le llega otra vez el aviso en la **campana** («Recordatorio: el pase PS-… espera tu firma») y por correo si está encendido. Si sigue sin firma, se avisa a quien firma el **siguiente paso** del circuito o, si era el último, a quien configura los pases en esa sede («Firma atrasada: pase PS-…»). Cada aviso se manda una sola vez por paso y queda en la bitácora del pase. Si dejas los dos campos vacíos (así viene), nadie recibe avisos nuevos; solo se ve el reloj.

## En el celular

![Pases en el celular](img/pases-salida/celular.png)

## Si algo sale mal

| Mensaje o síntoma | Qué significa | Qué hacer |
|---|---|---|
| «Elige al solicitante: escanea su gafete, busca su nombre o regístralo con «Nuevo Colaborador».» | Falta el solicitante. | Escanea su gafete o búscalo. |
| «Solo puedes solicitar pases de salida a tu nombre.» | Tu rol pide pases solo para ti. | Deja tu nombre en **Solicitante**; si el pase es de otra persona, que lo pida ella o la caseta. |
| «Tu usuario no está vinculado a un colaborador: pide a tu administrador que lo vincule en Usuarios para poder solicitar pases.» | Tu cuenta no está ligada a tu ficha de colaborador. | Pide a tu administrador que la vincule en [Usuarios](usuarios.md). |
| «Agrega al menos un artículo que salga en el pase.» | No hay artículos. | Escanea o escribe al menos uno. |
| «Eres el solicitante de este pase: no puedes aprobarlo tú.» | No se aprueba lo propio. | Lo aprueba otra persona del circuito. |
| «Escribe el motivo del rechazo: el solicitante lo verá para corregir su pase.» | El rechazo necesita motivo. | Escribe qué hay que corregir. |
| «Marca o escanea cada artículo que sale: faltan …» | No verificaste todos los artículos. | Escanea o marca los que faltan. |
| «Escribe el nombre de quien se lleva el equipo.» | Falta quién recibe. | Escribe su nombre y pide su firma. |
| Recuadro amarillo «Espera la firma de …» | No te toca firmar este paso. | Espera a que firme la persona indicada. |
| «El aviso al siguiente nivel debe ir después del recordatorio (más horas o días).» | En **Firmas que tardan** el segundo tiempo es menor o igual al primero. | Escribe un tiempo mayor en **Avisar al siguiente nivel**. |
| «En «Recordar a quien debe firmar» el máximo es 30 días.» | El tiempo es demasiado largo. | Escribe 30 días o menos. |
| «En el paso «…», escribe el valor en pesos (solo números, por ejemplo 50000).» | La condición de valor tiene letras. | Escribe solo la cantidad. |
| «En el paso «…», el valor «menor a» debe ser mayor que el valor «mayor o igual a».» | El tope es menor que el mínimo. | Corrige las cantidades o deja vacío el tope. |
| «Cada motivo necesita al menos un paso obligatorio sin condición de valor: …» | Ese motivo podría quedar sin aprobaciones. | Deja al menos un paso obligatorio sin condición de valor para ese motivo. |
| «El valor de un artículo no puede ser negativo.» | Escribiste un valor menor a cero. | Corrige el valor o déjalo vacío. |

## Preguntas frecuentes

- **¿Cómo sé qué pases me tocan?** Abre **Mis pendientes** o la píldora **Pendientes de mi firma**.
- **¿Qué pasa si el equipo no regresa a tiempo?** El pase se marca **Vencido — Debió Regresar** y cada mañana llega un recordatorio a la **campana** (y por correo, si está encendido) de quien lo pidió y de quien aprueba en la sede. Al registrar el regreso, el aviso queda leído.
- **¿Por qué en este pase la caseta no pidió mi firma?** Tu empresa configuró ese movimiento con **Solo Seguridad** o **Sin firma**. Lo ves en el **Resumen** del pase.
- **¿Si cambian la configuración, cambia mi pase?** No. Cada pase conserva las firmas de caseta que tenía al enviarse.
- **¿Puedo hacer el pase desde el celular?** Sí, todo funciona igual.
- **¿Puedo borrar un pase?** No; si ya no se necesita, se cancela antes de aprobarse.

## Relacionado

- [Menús, Mis pendientes y modos de pantalla](menus.md)
- [Equipos de seguridad](equipos.md)
- [Bitácora de accesos](accesos.md)
