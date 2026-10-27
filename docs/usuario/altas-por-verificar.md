---
titulo: Altas por verificar
modulos: [vehiculos.editar, proveedores.editar, visitantes.editar, colaboradores.aprobar]
seccion: Padrones
orden: 110
resumen: Una sola bandeja para lo que la caseta registra al momento (personas, vehículos, empresas y colaboradores): se usa de inmediato y después alguien lo revisa: es correcto, es el mismo o se rechaza.
---

# Altas por verificar

**¿Para qué sirve?** En la caseta a veces llega **un auto, una persona o una empresa que no está en el padrón**. El guardia no puede detener la operación, pero si cada quien registra a su manera, el padrón se llena de repetidos («ABC-123-A» y «ABC123A», «Laura Méndez Ríos» y «LAURA MENDEZ RIOS»). Por eso:

1. Antes de registrar algo nuevo, la plataforma pregunta **«¿Es alguno de estos?»** y muestra lo parecido que ya existe.
2. Si no es ninguno, **se registra y se usa de inmediato**, pero queda **por verificar**.
3. Quien administra el padrón lo **revisa** en una sola bandeja, **Padrones → Altas por verificar**: **es correcto**, **es el mismo** que otro registro o se **rechaza**.

Aplica al [Padrón de personas](personas.md), al [Padrón vehicular](vehiculos.md), a [Empresas externas](proveedores.md) y a [Colaboradores](colaboradores.md): la bandeja tiene una pestaña para cada uno y los mismos tres caminos.

![Aviso en Inicio](img/altas-por-verificar/01-inicio-aviso-altas-por-verificar.png)

## Antes de empezar

- **Para registrar desde la caseta** basta con poder trabajar en la pantalla de origen (por ejemplo la Bitácora de accesos). No necesitas poder editar los padrones.
- **Para verificar** necesitas poder **editar** ese padrón (por ejemplo el administrador, o el jefe de seguridad en sus sedes). Solo verás las altas de tus sedes y las pestañas de los padrones que puedes verificar.
- **Los colaboradores** los verifica Recursos Humanos si tu plan lo incluye; si no, el administrador (permiso **Aprobar** de Colaboradores).
- Si tú mismo registras algo y puedes editar ese padrón, ya nace verificado.

## Cómo registrar algo nuevo desde la caseta

Lo nuevo se registra desde tu pantalla de trabajo:

| Desde | Puedes registrar |
|---|---|
| [Bitácora de accesos](accesos.md) | el vehículo (escribiendo o escaneando las placas), la persona y la empresa de un proveedor o contratista |
| [Bitácora de transporte](transporte.md) | la unidad y el chofer |
| [Pases de salida](pases-salida.md) | la empresa destino (**Nuevo Proveedor**) |
| [Lost & Found](lost-found-y-robo.md) | a la persona que recibe un objeto (**Registrar en el Padrón**) |

1. Escribe las placas, el nombre o la empresa. Si no aparece igual, la lista te muestra los **parecidos** (marcados con ≈), aunque cambien los acentos, las mayúsculas, los guiones o los espacios, o se confundan la O con el 0.
2. Si **es uno de ellos**, tócalo: queda elegido con sus datos.
3. Si **no es ninguno**, sigue capturando y guarda: se registra como nuevo y queda pendiente de verificar.

![Placas parecidas](img/altas-por-verificar/15-accesos-es-alguno-de-estos-placas.png)

En las ventanas de registro rápido (por ejemplo **Registro Rápido de Persona** o **Nueva Empresa Externa**) verás el aviso «Lo que registres aquí se usa **de inmediato** y queda **por verificar**: la verifica quien administra el padrón.». Al tocar **Registrar**, si hay algo parecido, la ventana pregunta primero **¿Es alguno de estos?**:

- Toca el que corresponde para **usarlo**: la ventana se cierra y queda elegido.
- Si no es ninguno, toca **No es ninguno: registrarlo como nuevo**. La ventana te pregunta una vez más: «¿Seguro que no es ninguno de los N parecidos? Se creará un registro nuevo pendiente de verificar».
  - Toca **Sí, registrar nuevo** para registrarlo.
  - Toca **Volver a revisar** si quieres mirar otra vez la lista; nada se registra hasta que elijas.

![¿Es alguno de estos?](img/altas-por-verificar/17-alta-rapida-persona-parecidos.png)

**Qué debes ver:** el registro elegido en tu formulario. En los padrones aparece con la insignia **Por verificar**; lo puedes usar normalmente.

![El agente consulta el padrón](img/altas-por-verificar/13-agente-ve-padron-sin-verificar.png)

## Cómo encontrar lo que falta verificar

1. En cuanto la caseta registra algo nuevo te llega a la **campana** (arriba a la derecha) «Alta por verificar: …», con el botón **Verificar** que te lleva directo a la ficha. También aparece en **Inicio** (la tarjeta **«N altas por verificar»**, con un botón por pestaña), en **Mis pendientes** y en el menú **Padrones → Altas por verificar**.

   ![Alta por verificar en la campana](img/altas-por-verificar/30-campana-alta-por-verificar.png)
2. Toca el botón (o abre **Padrones → Altas por verificar**). Llegas a la bandeja con sus pestañas **Personas**, **Vehículos**, **Empresas** y **Colaboradores**, cada una con su número. Cada ficha dice quién la registró, cuándo y hace cuánto.
3. Si prefieres revisar desde el padrón, la píldora **Por verificar** del padrón muestra solo lo que falta.

![Vehículos pendientes](img/altas-por-verificar/02-vehiculos-pendientes-de-verificar.png)

**Qué debes ver:** las fichas con el botón amarillo **Verificar**. Además llega un **correo** a quien puede verificar; se puede apagar en [Configuración](configuracion.md) → avisos por correo.

## Cómo verificar una ficha

1. Toca **Verificar** en la ficha. Se abre **Verificar Alta Pendiente**, con quién lo registró, cuándo, desde qué pantalla y en qué sede.
2. En **¿Qué encontraste?** elige una opción:

### Es correcto

1. Revisa los datos y corrige lo que haga falta (por ejemplo el modelo que la caseta no capturó).
   - En un vehículo **De una empresa** (por ejemplo un taxi o una unidad de transporte) completa la **Empresa propietaria** y el **Número económico**: son obligatorios. Si es de una persona, cambia **¿De quién es?** a **Particular**.
2. Toca **Aprobar**.

![Es correcto](img/altas-por-verificar/03-verificar-vehiculo-es-correcto.png)

**Qué debes ver:** ««XTR901C» quedó verificado en el padrón. Gracias por revisarlo.» Queda como un registro normal. Quien lo registró en la caseta recibe en su campana «Tu alta «XTR901C» quedó verificada».

### Es el mismo

1. Elige el registro correcto en **¿Es alguno de estos?**, o búscalo en **¿No está en la lista? Búscalo en el padrón** (escribe al menos 2 letras).
2. Toca **Aprobar · es el mismo**.

![Es el mismo](img/altas-por-verificar/04-verificar-vehiculo-ya-existia.png)

**Qué debes ver:** ««…» se unió con «…»: todo lo registrado pasó al registro correcto.» Los accesos, movimientos de transporte, entregas, personal o flotilla del alta pasan al registro correcto. Quien la registró recibe en su campana «Tu alta «…» ya existía» con el nombre correcto, para usarlo la próxima vez.

### Rechazar

1. Escribe el **Motivo del rechazo** (mínimo 5 letras), por ejemplo «Las placas no existen». Así la caseta sabe qué corregir.
2. Toca **Rechazar**.

![Rechazar](img/altas-por-verificar/05-verificar-vehiculo-rechazar.png)

**Qué debes ver:** ««…» quedó rechazado: se conserva como historia y ya no se puede usar en la operación.» Lo rechazado no se borra, pero ya no se puede usar, editar ni reactivar. **Quien lo registró recibe en su campana** «Tu alta «…» fue rechazada» con el motivo, sin esperar a intentar usarla de nuevo.

![El rechazo en la campana de la caseta](img/altas-por-verificar/31-campana-alta-rechazada.png)

![Ficha rechazada](img/altas-por-verificar/06-ficha-rechazada.png)

### Empresas externas y personas

Funciona igual. En empresas externas reconoce el mismo nombre con o sin «S.A. de C.V.»; en personas, el nombre sin acentos o en otro orden. Al unir personas, si la correcta no tenía identificación, se queda con la que capturó la caseta.

> ¿Dos personas **ya verificadas** son la misma? Únelas desde el [Padrón de personas](personas.md) con **Unir con otra ficha**.

![Unir empresa](img/altas-por-verificar/08-verificar-empresa-ya-existia.png)

![Es correcto (persona)](img/altas-por-verificar/10-verificar-persona-es-correcto.png)

### Colaboradores

En la pestaña **Colaboradores** cada ficha trae tres botones:

1. **Es correcto:** revisa y corrige los datos, asigna su **Núm. Empleado** y toca **Aprobar**. Desde ese momento es un colaborador normal.
2. **Es el mismo:** elige el **Colaborador correcto** y toca **Aprobar · es el mismo**. Todo lo que la caseta registró con esa alta pasa a ese colaborador (y, si tenía cuenta, su cuenta toma su nombre y su número).
3. **Rechazar:** escribe el **Motivo del rechazo** (mínimo 5 letras) y toca **Rechazar**. El alta queda dada de baja, la caseta ya no la puede usar y quien la registró recibe en su campana «Tu alta «…» se rechazó» con el motivo.

**Qué debes ver:** regresas a la bandeja con el mensaje verde y el número de la pestaña baja en uno.

> Si importas tu plantilla de personal desde Excel y una fila tiene el mismo nombre que un alta de la caseta, la vista previa dice «alta de la caseta por verificar: al actualizar se une con ella y queda verificada». Elige **actualizar** lo que ya existe y no se duplica (ver [Importar desde Excel](importar-desde-excel.md)).

## En el celular y en los modos Noche y Sol

![Inicio en el celular](img/altas-por-verificar/19-movil-inicio.png) ![Verificar en el celular](img/altas-por-verificar/21-movil-verificar-ya-existia.png)

![Noche: verificar](img/altas-por-verificar/23-noche-verificar.png)

![Sol: verificar](img/altas-por-verificar/25-sol-verificar.png)

## Si algo sale mal

Los mensajes salen en rojo dentro de la ventana.

Si no ves **Altas por verificar** en el menú **Padrones**, tu rol no puede verificar ningún padrón ni colaboradores: pídelo a tu administrador.

![Error dentro de la ventana](img/altas-por-verificar/26-error-en-dialogo.png)

| Mensaje o síntoma | Qué significa | Qué hacer |
|---|---|---|
| Escribe el motivo del rechazo (mínimo 5 letras): así la caseta sabe qué corregir. | El motivo está vacío o es muy corto. | Escribe por qué se rechaza. |
| El motivo admite máximo 255 caracteres. | El motivo es muy largo. | Resúmelo. |
| Elige un registro activo y ya verificado del padrón (no puede ser el mismo). | Al unir elegiste uno inválido. | Elige el registro correcto de la lista. |
| Este registro ya no está pendiente de verificar (alguien más ya lo revisó). Recarga la pantalla. | Otra persona lo verificó antes. | Recarga la página. |
| «XTR901C» fue rechazado al verificar el Padrón vehicular (motivo: …). Ya no se puede usar: revisa los datos o avisa a tu supervisor. | En la caseta intentaste usar algo rechazado. | Revisa los datos o avisa a tu supervisor. |
| Este registro fue rechazado al verificar el padrón: se conserva solo como historia y ya no se puede reactivar ni editar. | Intentaste editar o reactivar algo rechazado. | Usa o registra el correcto. |
| Lo que aparece «Dado de baja» no se puede elegir. | El registro está de baja. | Pide a tu supervisor que lo reactive. |
| No veo el botón **Verificar**. | No puedes editar ese padrón o el alta no es de tus sedes. | Pide el permiso a tu administrador. |

## Preguntas frecuentes

**¿Puedo usar algo pendiente de verificar?** Sí, desde el momento en que se registra.

**¿Por qué me pregunta dos veces antes de registrar uno nuevo?** Porque un repetido cuesta trabajo después (hay que unirlo). Con la segunda pregunta te aseguras de que de verdad no es ninguno de los parecidos.

**¿Qué pasa con lo ya registrado si rechazo el alta?** Se queda como está: los accesos o movimientos ya capturados no cambian. Solo ya no se podrá usar de nuevo.

**¿Puedo deshacer una unión?** No desde la pantalla. En la [Bitácora de auditoría](auditoria.md) queda qué se movió y a dónde.

**¿Quién recibe el aviso?** Los usuarios activos que pueden editar ese padrón en la sede donde se registró el alta: en la campana siempre, y por correo si está encendido en Configuración. En cuanto alguien la verifica, el aviso pierde su botón para todos.

**¿Cómo se entera la caseta del resultado?** Por su campana: «quedó verificada», «ya existía» (con el registro correcto) o «fue rechazada» (con el motivo).

**Si se unió con el correcto, ¿la caseta tiene que hacer algo?** No. Al escribir las mismas placas o el mismo nombre, la plataforma usa el registro correcto.

## Relacionado

- [Padrón vehicular](vehiculos.md)
- [Empresas externas](proveedores.md)
- [Padrón de personas](personas.md)
- [Colaboradores](colaboradores.md)
- [Bitácora de accesos](accesos.md)
