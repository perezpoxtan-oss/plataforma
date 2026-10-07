# Vouchers de reposición

Padrones → Inventarios de Seguridad → **Vouchers de reposición**. Un voucher es el comprobante que se genera **solo** cuando se da de baja una llave, un gafete o un equipo que se perdió, se dañó o lo robaron. Aquí se consultan y se vuelven a imprimir; no se crean ni se borran a mano.

![Vouchers de reposición](img/vouchers/lista.png)

## Qué ves en cada tarjeta

- El **folio** (por ejemplo `VR-2610-48213`) y si es **CON COBRO** (rojo) o **SIN COBRO** (verde).
- El **artículo** que se dio de baja (nomenclatura del gafete o de la llave, o el equipo).
- **Origen** (Llave, Gafete o Equipo), **Motivo**, **Sede**, **Responsable** y, si hay cobro, el **Monto**.
- **¿Cómo pasó?**, quién lo generó y cuándo.

Solo ves los vouchers de los módulos que puedes consultar (por ejemplo, si no tienes Llaves, no ves los de llaves) y de tus sedes.

## Buscar y filtrar

- **Buscar**: folio, artículo, responsable (nombre o número de empleado) o lo que se escribió en "¿Cómo pasó?". Presiona Enter o **Buscar**.
- **Sede**, **Origen** y **Aplica cobro o no** se aplican al elegirlos.
- **Desde / Hasta**: un rango de fechas.
- **Quitar filtros** vuelve a mostrar todo.

![Solo con cobro](img/vouchers/filtro-con-cobro.png)

## Imprimir o reimprimir

Toca **Ver / Reimprimir**: se abre una hoja con **3 copias**: **Seguridad, Recepción y Administración**. El colaborador responsable firma, pero **no recibe copia**. Toca **Imprimir Voucher** y recorta por las líneas punteadas. Si hay cobro, anota a mano la **referencia de pago**.

![Voucher de 3 copias](img/ronda-5/voucher-copias.png)

## Firmas: digital o en papel

Al dar de baja se elige cómo se firma:

- **Firmado digitalmente**: Seguridad y el responsable firmaron en la pantalla. Las firmas ya salen impresas en las tres copias.
- **Firma en papel pendiente**: se eligió firma física. Imprime el voucher, que firmen a mano y toca **Registrar firma en papel**. Puedes tomar una foto de la hoja firmada (opcional); se guarda en un lugar privado y aparece **Ver hoja**.
- **Firmado en papel**: ya se registró, con quién lo registró y cuándo. Con **Cambiar hoja firmada** puedes subir otra foto.

![Estados de firma](img/ronda-5/vouchers-firmas.png)
![Registrar firma en papel](img/ronda-5/voucher-firma-papel.png)

## Copias por correo (cuando hay cobro)

Si el voucher es **CON COBRO**, la plataforma envía cada copia por correo: la de Seguridad, la de Recepción y la de Administración, a las listas que el Administrador captura en **Estructura → Configuración → Avisos por correo**. El correo trae un botón para ver e imprimir el voucher (pide entrar). El colaborador no recibe correo.

## Quién puede qué

- **Agente**: consulta los vouchers de su sede, pero no los imprime ni registra la firma en papel.
- **Asistente, Supervisor, Jefe de seguridad, Director y Administrador**: consultan e imprimen (en sus sedes, o en todas si su alcance es de empresa).

![Lo que ve el Agente](img/vouchers/agente.png)

## En el celular y de noche

Los filtros se acomodan uno debajo de otro y las tarjetas en una columna. El modo **Noche** oscurece todo.

![Celular](img/vouchers/movil.png)
![Modo Noche](img/vouchers/noche.png)

## Si el artículo aparece: «Recuperado»

Si la llave, el gafete o el equipo de un voucher aparece y lo devuelven:

1. En la tarjeta del voucher toca **Recuperado**.
2. Lee lo que va a pasar: el artículo se **reactiva** y el voucher queda **Cancelado por recuperación** (no se borra).
3. Si el voucher tenía **cobro**:
   - Si **todavía no** se le cobraba al responsable, deja la casilla sin marcar: el cobro se **cancela**.
   - Si **ya** se le cobró, marca **Ya se le cobró al responsable** y escribe cómo se le devolverá el dinero. El voucher queda **Reembolso pendiente**.
4. Escribe un comentario (dónde apareció, quién lo entregó) y toca **Confirmar recuperado**.

![Recuperado](img/ronda-6/17-voucher-recuperado-dialogo.png)

Cuando ya le devolviste el dinero, toca **Reembolso entregado** en la tarjeta. Queda **Reembolsado**.

![Estados del voucher](img/ronda-6/16-vouchers.png)

Con el filtro **Cualquier estado** puedes ver solo los vigentes, los cancelados por recuperación, los que tienen reembolso pendiente o los reembolsados. La hoja impresa también muestra el estado:

![Voucher impreso con reembolso pendiente](img/ronda-6/18-voucher-impreso-reembolso.png)

Lo hacen quienes pueden editar vouchers y reactivar en el módulo del artículo (Administrador, Jefe de seguridad). El Agente no ve estos botones.
