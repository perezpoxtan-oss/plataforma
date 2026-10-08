# Pases de salida

Operación → Caseta y Control → **Pases de salida**. Aquí se autoriza y se sigue todo equipo que **sale de la sede**: un préstamo, una venta, una reparación, un traspaso… Cada pase pasa por **aprobaciones** (en orden) y por la **caseta**; si el equipo debe volver, no se cierra hasta que regresa.

![Lista de pases](img/pases-salida/01-lista.png)

## Cómo se lee una ficha

- **PS-000123**: el folio. A la derecha, la **etiqueta de color** dice en qué va.
- **Espera tu firma** (azul): te toca a ti. Esa ficha tiene un borde azul.
- El nombre es el del **solicitante**; abajo, el motivo, cuántos artículos salen y de dónde a dónde va.
- La **línea de pasos** muestra el avance: **Solicitud → Aprobaciones → Salida → En destino / Fuera → Regreso → Cerrado**.
  - Verde con palomita: ya se hizo.
  - Azul con relojito: es el paso que sigue.
  - Rojo: algo requiere atención (rechazado o vencido).
- El recuadro azul dice **quién debe actuar ahora**, por ejemplo «Espera la firma de: Contraloría (paso 2 de 3)» o «Caseta de Hotel Demo Centro: registrar la salida».
- Abajo, las fechas: cuándo se creó, cuándo sale y cuándo debe regresar (en rojo si ya se venció).

| Etiqueta | Qué quiere decir |
|---|---|
| **Pendiente de Aprobación** (naranja) | Le faltan firmas de aprobación. |
| **Rechazado — devuelto al solicitante** (rojo) | Alguien lo rechazó: hay que corregirlo y reenviarlo, o cancelarlo. |
| **Aprobado, listo para salir** (azul) | Ya puede salir; la caseta registra la salida. |
| **Salió — Espera Regreso** (naranja) | Ya salió y debe volver. |
| **En destino, espera regreso** (turquesa) | Llegó a la otra sede. |
| **En tránsito de regreso** (naranja fuerte) | Viene de vuelta. |
| **Regreso parcial — faltan artículos** (naranja) | Regresó una parte; falta lo demás. |
| **Salió — Cerrado** / **Regresado / Cerrado** (verde) | Terminó. |
| **Cerrado con faltantes** (café) | Se cerró aunque algo no regresó (ver la bitácora). |
| **Cancelado** (gris) | Se canceló antes de aprobarse. |
| **Vencido — Debió Regresar** (rojo) | Ya pasó su fecha de regreso y no ha vuelto. ¡Hay que dar seguimiento! |

## Filtros y búsqueda

- Toca una **píldora**: Todos, **Pendientes de mi firma**, Pendientes de Aprobación, Aprobados listos para salir, Fuera de la propiedad, Esperando Regreso, **Vencidos**, Cerrados o Rechazados. El número dice cuántos hay.
- En **Buscar** escribe el folio, el nombre o número de empleado del solicitante, el equipo o su serie. También puedes **escanear el QR de la hoja impresa** con el lector: aparece ese pase.
- Si ves varias sedes, elige una en la lista.

![Vencidos](img/pases-salida/03-filtro-vencidos.png)

## Mi bandeja de firmas

Toca **Mi bandeja** (o el aviso de **Inicio**): solo aparecen los pases que esperan **tu** firma — las aprobaciones que te tocan y, si trabajas en caseta, los pases que debes dejar salir o recibir.

![Aviso en Inicio](img/pases-salida/18-inicio-aviso.png)
![Bandeja](img/pases-salida/02-bandeja-jefe.png)

## Hacer un pase nuevo

1. Toca **Nuevo Pase**. Si trabajas en una sola sede, la **Sede de Origen** ya viene elegida.
2. **1. Motivo y Solicitante**: elige el motivo (el sistema te dice si el equipo debe regresar). Escanea el gafete del solicitante o escribe su nombre y elígelo. ¿No aparece? **Nuevo Colaborador** (alta provisional; Recursos Humanos la revisa).
3. **2. Enviar A**: otra sede, un proveedor (¿no está? **Nuevo Proveedor**) o un colaborador que se lo lleva. La dirección y el teléfono se llenan solos. Si el equipo regresa, escribe la **Fecha Tentativa de Regreso**: con ella el sistema avisa cuando se venza.
4. **3. Artículos que Salen**: si es un equipo del padrón, **escanea su etiqueta** y se llena solo. Si no, escribe cantidad, equipo, marca, modelo, serie y descripción.
5. Toca **Guardar y Enviar a Aprobación** (o **Registrar y capturar siguiente** si vas a hacer otro). Se avisa por correo a quien debe aprobar el primer paso.

![Nuevo pase](img/pases-salida/04-nuevo-pase.png)

## La ficha del pase

Al tocar una ficha se abre su página: arriba los botones de lo que **tú** puedes hacer y, abajo, tres pestañas:

- **Resumen**: los datos del pase y la lista de **aprobaciones** con quién firmó, cuándo, su comentario y su firma.
- **Artículos**: lo que sale, si salió verificado (o escaneado) y cuánto ha regresado.
- **Firmas y bitácora**: todo lo que ha pasado, con quién, cuándo, comentario, firmas e IP. No se puede borrar ni cambiar.

![Ficha del pase](img/pases-salida/05-ficha-pendiente.png)
![Artículos](img/pases-salida/12-pestana-articulos.png)
![Bitácora](img/pases-salida/13-pestana-bitacora.png)

## Aprobar o rechazar

1. Abre el pase (desde **Mi bandeja**) y revisa los artículos.
2. Toca **Aprobar**. Firma con **Usar mi firma guardada** o **Firmar ahora** en el recuadro (marca «Guardar mi firma» para no dibujarla la próxima vez; solo tú puedes usarla). Si quieres, escribe un comentario. Toca **Aprobar y firmar**.
3. Si no estás de acuerdo, toca **Rechazar** y escribe el **motivo** (obligatorio): el pase regresa al solicitante.
4. Si un paso es **opcional** y no aplica, puedes tocar **Omitir paso** con un comentario.

Los pasos van **en orden**: el siguiente aprobador recibe su aviso cuando tú firmas. No puedes aprobar tu propio pase, ni firmar dos pasos del mismo pase. Si no te toca, un recuadro amarillo te dice quién debe firmar.

![Aprobar](img/pases-salida/06-aprobar.png)
![Rechazar](img/pases-salida/07-rechazar.png)
![Sin permiso de aprobar](img/pases-salida/19-agente-sin-aprobar.png)

## Si te rechazan un pase

La ficha muestra el motivo en rojo. Toca **Corregir y reenviar**, cambia lo que te pidieron, escribe qué corregiste y toca **Guardar y Reenviar a Aprobación**: las aprobaciones empiezan otra vez desde el primer paso. Si ya no se necesita, toca **Cancelar pase**.

![Rechazado](img/pases-salida/08-ficha-rechazada.png)
![Corregir y reenviar](img/pases-salida/09-corregir-y-reenviar.png)

## En la caseta: salida, llegada y regreso

1. Abre el pase (aparece en **Mi bandeja**) y toca el botón morado: **Registrar Salida**, **Confirmar Llegada**, **Autorizar Salida de Regreso** o **Registrar Regreso**.
2. **Verifica los artículos**: escanea cada equipo del padrón (se marca solo) o márcalo con el dedo. Para la salida deben estar **todos**. Si llegó algo diferente, anótalo en el comentario.
3. Escribe el **nombre** de quien se lleva o entrega el equipo (a veces ya viene) y que **firme** en el recuadro.
4. Firma tú como **Seguridad** (con tu firma guardada es un solo toque) y guarda.

En el **regreso**, escribe cuántos regresan de cada artículo. Si faltan, se guarda como **regreso parcial** y el pase sigue esperando lo demás (escribe qué pasó). Si lo que falta ya no va a volver, marca **Cerrar el pase aunque falten artículos**.

![Registrar salida](img/pases-salida/10-caseta-salida.png)
![Regreso parcial](img/pases-salida/11-caseta-regreso-parcial.png)

| Paso | Quién | Dónde |
|---|---|---|
| **Aprobaciones** | Lo que diga el circuito de tu empresa (permiso **Aprobar**) | Sede de origen |
| **Salida** | Caseta (permiso **Firmar**) | Sede de origen |
| **Llegada** y **Salida de regreso** | Caseta (permiso **Firmar**) | La otra sede (solo si va a otra sede) |
| **Regreso** | Caseta (permiso **Firmar**) | Sede de origen |

## Imprimir y verificar

Toca **Imprimir Pase**: sale una hoja carta con el logo, el folio, un **código QR**, los artículos y todas las firmas (las que faltan quedan con línea para firmar a mano). En la caseta, escanea el QR con el celular: abre una página que confirma que el **pase es auténtico** y su estado en ese momento («Autorizado para salir», «Aún no está aprobado»…).

![Hoja impresa](img/pases-salida/16-impresion.png)
![Verificar el QR](img/pases-salida/17-verificar-qr.png)

## Configurar el circuito (Administrador)

En **Configuración → Pases de salida → Configurar circuito** (o el botón **Circuito** de la lista) decides quién aprueba y en qué orden:

- Cada paso tiene un **nombre** (por ejemplo «Contraloría») y **quién firma**: cualquiera con permiso Aprobar, los usuarios con un **rol**, o **usuarios específicos**.
- Puedes pedir que sea **del mismo departamento que el solicitante** (el jefe del departamento) o de un departamento en particular.
- **Obligatorio** u opcional, y a qué **motivos** aplica (por ejemplo, Gerencia solo en Venta).
- Usa las flechas para cambiar el orden. **Guardar circuito** aplica a los pases nuevos; los que ya están en curso conservan sus pasos.
- Los avisos por correo del circuito se encienden o apagan en **Configuración → Avisos por correo**.

![Circuito](img/pases-salida/14-circuito.png)
![Configuración](img/pases-salida/15-configuracion.png)

## En el celular y de noche

Todo funciona igual en el celular. Con el botón del sol eliges **Sol** (alto contraste para exteriores) o **Noche**.

![Celular](img/pases-salida/20-celular-lista.png)
![Celular, ficha](img/pases-salida/21-celular-ficha.png)
![Celular, caseta](img/pases-salida/23-celular-caseta-salida.png)
![Noche](img/pases-salida/26-noche-ficha.png)
![Sol](img/pases-salida/28-sol-lista.png)

## Ronda 8: pasos que se contraen

Los tres pasos del «Nuevo Pase de Salida» (Motivo y Solicitante, Enviar A, Artículos que Salen) vienen abiertos y se pueden contraer tocando su título; la plataforma recuerda cómo los dejaste. Si falta algo, el paso se abre solo.

![Pase con pasos que se contraen](img/ronda-8/06-pase-con-secciones.png)
