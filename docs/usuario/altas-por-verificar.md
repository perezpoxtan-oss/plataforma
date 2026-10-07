# Altas por verificar

En la caseta a veces llega **un auto, una persona o una empresa que no está en el padrón**. El guardia no puede detener la operación, pero tampoco conviene que cada quien registre a su manera. Si no, el padrón se llena de repetidos: «ABC-123-A» y «ABC123A», «Laura Méndez Ríos» y «LAURA MENDEZ RIOS».

Por eso:

1. **Antes de registrar algo nuevo, la plataforma pregunta «¿Es alguno de estos?»** y muestra lo parecido que ya existe.
2. Si no es ninguno, **se registra y se usa de inmediato**, pero queda **pendiente de verificar**.
3. Quien administra el padrón lo **revisa**: lo acepta, lo rechaza o lo une con el registro correcto.

Esto aplica a los padrones de **Vehículos**, **Empresas externas** y **Personas**. Los colaboradores tienen su propia «alta provisional», que valida Recursos Humanos (ver [Colaboradores](colaboradores.md)).

## Para la caseta (Agente)

Tú sigues **sin poder editar los padrones**: solo los consultas. Lo nuevo lo registras desde tu pantalla de trabajo:

| Desde | Puedes registrar |
|---|---|
| **Bitácora de accesos** | el vehículo (escribiendo o escaneando las placas), la persona y la empresa de un proveedor o contratista |
| **Bitácora de transporte** | la unidad y el chofer |
| **Pases de salida** | la empresa destino («Nuevo Proveedor») |
| **Lost & Found** | a la persona que recibe un objeto («Registrar en el Padrón») |

### «¿Es alguno de estos?» al escribir

En el **Registro Inteligente de Ingreso**, cuando escribes unas placas, un nombre o una empresa que no aparece igual, la lista te muestra los **parecidos** (marcados con ≈). Encuentra parecidos aunque cambien los acentos, las mayúsculas, los guiones o los espacios, o aunque se confundan la O con el 0.

![Placas parecidas](img/altas-por-verificar/15-accesos-es-alguno-de-estos-placas.png)

- Si **es uno de ellos**, tócalo: queda elegido con sus datos (marca, color…).
- Si **no es ninguno**, sigue capturando. Al guardar se registra como nuevo y queda pendiente de verificar.

Lo mismo con el nombre de la persona:

![Personas parecidas](img/altas-por-verificar/14-accesos-es-alguno-de-estos-persona.png)

Lo que dice «Pendiente de verificar» ya lo registró alguien de la caseta y todavía no lo revisan. Lo puedes usar.

### En las ventanas de registro rápido

Las ventanas «Registro Rápido de Persona» y «Nueva Empresa Externa» avisan que lo que registres queda pendiente de verificar:

![Aviso en el registro rápido](img/altas-por-verificar/16-alta-rapida-persona-pendiente.png)

Al tocar **Registrar**, si hay algo parecido, la ventana pregunta primero:

![¿Es alguno de estos?](img/altas-por-verificar/17-alta-rapida-persona-parecidos.png)

- Toca el que corresponde para **usarlo**: la ventana se cierra y queda elegido en tu formulario.
- Si no es ninguno, toca **No es ninguno: registrarlo como nuevo**.

![Nueva Empresa Externa en Pases de salida](img/altas-por-verificar/18-pases-nueva-empresa-parecidos.png)

> Lo que aparece «Dado de baja» no se puede elegir: pide a tu supervisor que lo reactive.

### Si algo fue rechazado

Si quien administra el padrón **rechazó** un registro (por ejemplo, unas placas inventadas), ya no se puede usar. Al intentarlo verás:

«XTR901C» fue rechazado al verificar el Padrón vehicular (motivo: …). Ya no se puede usar: revisa los datos o avisa a tu supervisor.

Si se **unió** con el registro correcto, no tienes que hacer nada: al escribir las mismas placas o el mismo nombre, la plataforma usa el correcto.

### En los padrones

Al consultar los padrones verás la insignia **Pendiente de verificar** en lo que todavía no se revisa. No tienes botones para verificar ni editar.

![El agente consulta](img/altas-por-verificar/13-agente-ve-padron-sin-verificar.png)

## Para quien administra el padrón (verificar)

Verifica quien puede **editar** ese padrón: por omisión el Administrador, y el Jefe de seguridad en sus sedes. Si tú mismo registras algo desde la caseta, ya nace verificado.

### El aviso en Inicio

En **Inicio** aparece la tarjeta **«N altas por verificar»**, con un botón por padrón (Vehículos, Empresas externas, Personas). Solo verás los padrones que puedes editar y las altas de tus sedes.

![Aviso en Inicio](img/altas-por-verificar/01-inicio-aviso-altas-por-verificar.png)

También llega un **correo** a quien puede verificar. Se apaga en Estructura → **Configuración** → Avisos por correo:

![Aviso por correo en Configuración](img/altas-por-verificar/12-configuracion-aviso-por-correo.png)

### La píldora «Pendientes de verificar»

Al tocar un botón del aviso llegas al padrón con la píldora **Pendientes de verificar** encendida: solo se ven las fichas por revisar. Tócala otra vez para ver todas.

![Vehículos pendientes](img/altas-por-verificar/02-vehiculos-pendientes-de-verificar.png)

### Verificar una ficha

Toca **Verificar** en la ficha. La ventana muestra quién lo registró, cuándo, desde qué pantalla y en qué sede. Te pregunta **«¿Qué encontraste?»**:

**1. Es correcto.** Revisa los datos y corrige lo que haga falta (por ejemplo, el modelo que la caseta no capturó). Toca **Aceptar y verificar**. Queda como un registro normal del padrón.

![Es correcto](img/altas-por-verificar/03-verificar-vehiculo-es-correcto.png)

**2. Ya existía.** Era un registro que ya estaba con otra escritura. Elige el correcto de la lista de parecidos o búscalo, y toca **Unir con el elegido**. Todo lo que la caseta registró con el alta (accesos, movimientos de transporte, entregas, su personal o flotilla) **pasa al registro correcto**. El alta queda como «Unido con otro registro».

![Ya existía](img/altas-por-verificar/04-verificar-vehiculo-ya-existia.png)

**3. Rechazar.** El registro no procede (datos inventados, capturado por error…). Escribe el **motivo**: así la caseta sabe qué corregir. Toca **Rechazar alta**.

![Rechazar](img/altas-por-verificar/05-verificar-vehiculo-rechazar.png)

Lo rechazado **no se borra**: queda en el padrón como historia, con su motivo, y ya no se puede usar, editar ni reactivar.

![Ficha rechazada](img/altas-por-verificar/06-ficha-rechazada.png)

Si algo falta o está mal (por ejemplo, el motivo vacío), el aviso aparece **dentro de la ventana**.

### Empresas externas y personas

Funciona igual. En empresas externas la plataforma reconoce el mismo nombre con o sin «S.A. de C.V.»:

![Empresas pendientes](img/altas-por-verificar/07-empresas-externas-pendientes.png)

![Unir empresa](img/altas-por-verificar/08-verificar-empresa-ya-existia.png)

En personas reconoce el nombre sin acentos o en otro orden. Al unir, si la persona correcta no tenía identificación, se queda con la que capturó la caseta.

![Personas pendientes](img/altas-por-verificar/09-personas-pendientes.png)

![Es correcto (persona)](img/altas-por-verificar/10-verificar-persona-es-correcto.png)

![Unir persona](img/altas-por-verificar/11-verificar-persona-ya-existia.png)

## En el teléfono

Todo funciona en el celular: los botones son grandes y la ventana de verificar ocupa la pantalla.

![Inicio en el teléfono](img/altas-por-verificar/19-movil-inicio.png)

![Pendientes en el teléfono](img/altas-por-verificar/20-movil-vehiculos-pendientes.png)

![Verificar en el teléfono](img/altas-por-verificar/21-movil-verificar-ya-existia.png)

## Modos Noche y Sol

![Noche](img/altas-por-verificar/22-noche-vehiculos-pendientes.png)

![Noche: verificar](img/altas-por-verificar/23-noche-verificar.png)

![Sol](img/altas-por-verificar/24-sol-vehiculos-pendientes.png)

![Sol: verificar](img/altas-por-verificar/25-sol-verificar.png)

## Preguntas frecuentes

**¿Puedo usar algo pendiente de verificar?** Sí. Se puede usar desde el momento en que se registra.

**¿Qué pasa con lo que ya se registró si rechazo el alta?** Se queda como está: los accesos o movimientos ya capturados no cambian. Solo ya no se podrá usar de nuevo.

**¿Puedo deshacer una unión?** No desde la pantalla. En la Bitácora de auditoría queda qué se movió y a dónde (evento «Unión de duplicado»).

**¿Quién recibe el correo?** Los usuarios activos que pueden editar ese padrón en la sede donde se registró el alta.
