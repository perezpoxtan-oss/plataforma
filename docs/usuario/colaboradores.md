---
titulo: Colaboradores
modulos: [colaboradores]
seccion: Recursos Humanos
orden: 10
resumen: Directorio del personal de la empresa, sus sedes, altas provisionales de la caseta y su validación por Recursos Humanos.
---

# Colaboradores

**¿Para qué sirve?** Es el directorio del personal de la empresa. Cada colaborador tiene un número de empleado, una sede física (o es corporativo), un departamento (área) y un puesto (rango). La caseta los busca aquí para registrar accesos, préstamos de llaves, pases de salida y responsivas.

![Colaboradores](img/colaboradores/1-lista.png)

## Antes de empezar

- Para ver la pantalla tu rol debe poder consultar *Colaboradores*. Si no aparece en el menú, pide el permiso a tu administrador.
- Para dar de alta, editar o dar de baja necesitas esos permisos. Si no ves **Nuevo Colaborador**, tu rol solo consulta.
- Los **datos legales** (CURP, RFC, NSS, nacimiento, nacionalidad, correo personal y dirección) solo los ve y captura quien tiene el permiso **Datos personales**.
- Si tu rol es de una sede, solo puedes registrar colaboradores en tus sedes.
- Antes de dar de alta conviene tener listos los [Departamentos y Puestos](departamentos-y-puestos.md).

## Cómo encontrar a un colaborador

1. Entra a **Recursos Humanos → Colaboradores**.
2. Escribe en **Buscar empleado, número o puesto...** el nombre, el número de empleado, el puesto o el departamento.
3. Si quieres, elige en **Todas las sedes** (incluye a quienes la tienen como sede adicional) y en **Todos los departamentos**.
4. Con **Todos los registros / Por validar** ves solo las altas provisionales de la caseta.

**Qué debes ver:** las fichas que coinciden, con su número, puesto, departamento, sede y si está **ACTIVO**, **BAJA** o **POR VALIDAR**. El filtro se mantiene mientras trabajas.

## Cómo dar de alta a un colaborador

1. Toca la tarjeta **Nuevo Colaborador**. Se abre **Alta de Colaborador**.
2. **1. Sede Física:** donde trabaja. Si la dejas en **-- Corporativo (todas las sedes) --**, trabaja en todas. Si tu rol es de una sede, es obligatoria.
3. **Núm. Empleado:** mientras escribes te avisa si ya existe.
4. **2. Departamento (área):** solo aparecen los que aplican en la sede elegida.
5. **3. Puesto (rango):** se acota según el departamento. Los puestos sin departamento (por ejemplo *Gerente*) siempre aparecen.
6. Escribe **Nombre(s)**, **Apellido Paterno**, **Apellido Materno** y, si quieres, el **Teléfono** (10 dígitos; puedes escribir espacios o guiones).
7. Si tienes el permiso, llena **Datos Legales (Opcionales)**: CURP, RFC, NSS, Fecha de Nacimiento, Estado de Nacimiento y Nacionalidad. El CURP y el RFC se pasan a mayúsculas solos.
8. Toca **Registrar Colaborador**.

![Alta](img/colaboradores/2-alta.png)

**Qué debes ver:** «Colaborador «Juan Pérez López» creado correctamente.»

### Avisos mientras escribes

- **Núm. Empleado:** verde «Número disponible.»; rojo «Ya existe un colaborador con el número «1001» en esta empresa. No se puede repetir.» (si es de una sede que no tienes a cargo, solo te dice que existe).
- **Nombre y apellidos:** amarillo «Ya existe un colaborador con ese nombre. ¿Es la misma persona? Si lo es, no lo des de alta otra vez: búscalo en la lista.» Si es otra persona con el mismo nombre, continúa normalmente.

![Número y nombre repetidos](img/colaboradores/14-numero-y-nombre.png)

## Cómo editar a un colaborador

1. Toca el **lápiz** (Editar) de la ficha. Se abre **Editar Colaborador**.
2. Además de lo del alta, aquí se capturan el **Correo Personal** y la **Dirección Completa** (en **Datos Legales y de Contacto (Opcionales)**).
3. Toca **Guardar Cambios**.

![Editar](img/colaboradores/3-editar.png)

**Qué debes ver:** «Colaborador «…» actualizado correctamente.»

## Cómo asignar sedes adicionales

Si alguien trabaja en más de una sede (por ejemplo un agente que cubre turnos en otra sede):

1. Toca **Gestionar sedes adicionales** en su ficha.
2. Marca las sedes donde **también** trabaja, además de la principal.
3. Toca **Guardar Sedes**.

![Sedes adicionales](img/colaboradores/4-sedes-adicionales.png)

**Qué debes ver:** «Sedes adicionales de «…» actualizadas.» La ficha dice «+1 sede adicional» y aparece al filtrar por esa sede. Si tu rol es de una sede, solo ves tus sedes; las demás se conservan.

## Cómo dar de baja o reingresar a un colaborador

1. Toca el **círculo rojo** (Dar de baja) y confirma con **Aceptar** («¿Dar de baja a este colaborador? Podrás reactivarlo con el mismo botón.»).
2. Para regresarlo, toca la **flecha circular** (Reingresar) y confirma.

**Qué debes ver:** la ficha en **BAJA**. Ya no aparece al buscarlo desde otros módulos. No se borra a nadie: su historial en bitácoras, pases y responsivas se conserva.

## Cómo ver el código QR y asignar el gafete o tarjeta NFC / RFID

Cada colaborador tiene su propio **código QR** y puede tener una **etiqueta NFC / RFID** (su gafete o tarjeta). Con ellos la caseta lo encuentra al escanear en Accesos, Préstamo de llaves o Pases de salida.

1. En la ficha del colaborador toca el ícono de **QR** (Código QR e identificación). Se abre **Código e identificación** con su nombre, su número de empleado, el QR y la dirección del código (botón **Copiar**).
2. Si tu rol puede editar colaboradores, abajo aparece **Asignar etiqueta NFC / RFID**: acerca el gafete o la tarjeta al lector USB/Bluetooth o al NFC del celular Android, o escribe su número, y toca **Guardar etiqueta**.
3. Si ya tenía una etiqueta, se muestra la actual; al guardar otra, la anterior deja de funcionar.

![Código e identificación (ejemplo de la ventana)](img/llaves/13-identificacion.png)

**Qué debes ver:** «Etiqueta … asignada. Ya se puede leer con el lector.» Si esa tarjeta ya es de otra cosa (otro colaborador, una llave, un vehículo…), el aviso te dice de cuál y no se asigna.

- Quien solo consulta colaboradores ve el QR, pero no el apartado para asignar la etiqueta.
- Las altas provisionales (**POR VALIDAR**) reciben su etiqueta hasta que Recursos Humanos las valida.

## Cómo registrar un alta provisional (caseta)

Si en la caseta necesitas registrar a alguien que **no aparece** al buscarlo:

1. Toca la tarjeta **Alta provisional** (también aparece dentro de los formularios de accesos, préstamos y pases). Se abre **Alta Provisional de Colaborador**.
2. Escribe **Nombre(s)**, **Apellido Paterno** y elige la **Sede**. El **Número de Empleado** solo si lo sabes.
3. Si ya hay alguien con ese nombre, la plataforma te lo muestra («Ya hay alguien registrado con ese nombre. Si es la misma persona, úsala; si no, confirma que es otra.»): tócalo para usarlo, o toca **Es otra persona: registrarla**.
4. Toca **Registrar provisional**.

![Alta provisional en la caseta](img/colaboradores/9-caseta-alta-provisional.png)

**Qué debes ver:** la persona queda marcada **PROVISIONAL / POR VALIDAR** y ya la puedes usar. Recursos Humanos la revisará después.

## Cómo validar las altas provisionales (Recursos Humanos)

1. En **Inicio** (y en **Mis pendientes**) aparece «N altas provisionales por validar». Tócala y llegas a la lista filtrada. Dentro de Colaboradores también verás el aviso.
2. Elige **Por validar** en el filtro.
3. En cada ficha elige:
   - **Validar:** revisa y corrige los datos, asigna su **Núm. Empleado** y toca **Validar Colaborador**. Desde ese momento es un colaborador normal.
   - **Es un duplicado:** si la persona ya estaba registrada (por ejemplo «Beto Hernandez» es Roberto Hernández), busca y elige el **Colaborador correcto** y toca **Unir registros**. Todo lo que la caseta registró con el provisional pasa a ese colaborador y el provisional queda dado de baja.

![Por validar](img/colaboradores/11-rh-por-validar.png)

![Validar](img/colaboradores/12-validar.png)

![Es un duplicado](img/colaboradores/13-es-duplicado.png)

**Qué debes ver:** ««Beto Hernandez» validado con el número 2047.» o ««Beto Hernandez» se unió con «Roberto Hernández Cruz» (#1005).»

## En el celular y en modo Noche

![Celular](img/colaboradores/5-celular.png) ![Alta en el celular](img/colaboradores/6-celular-alta.png)

![Modo Noche](img/colaboradores/7-noche.png)

## Si algo sale mal

Los mensajes salen en rojo dentro de la ventana; lo que escribiste se conserva.

![Error dentro de la ventana](img/colaboradores/15-error-en-dialogo.png)

| Mensaje o síntoma | Qué significa | Qué hacer |
|---|---|---|
| Ya existe otro colaborador registrado con ese número de empleado. | El número ya se usa. | Revisa el número con Recursos Humanos. |
| Ya existe otro colaborador registrado con ese CURP. / RFC. / NSS. | Esa persona ya está registrada. | Búscala en la lista (puede estar de baja). |
| El CURP no tiene el formato correcto (18 caracteres, ej. ABCD123456HDFXYZ01). | El CURP está incompleto. | Cópialo de su documento. |
| El RFC no tiene el formato correcto (12 o 13 caracteres, ej. ABCD123456XYZ). | El RFC está incompleto. | Corrígelo o déjalo vacío. |
| El NSS debe tener exactamente 11 dígitos. | Faltan o sobran números. | Corrige el NSS. |
| El teléfono debe tener de 10 a 15 dígitos. | Faltan números. | Escribe el teléfono con lada. |
| La fecha de nacimiento debe ser anterior a hoy. | La fecha está mal. | Corrige la fecha. |
| Elige una de tus sedes: solo puedes registrar colaboradores en ellas. | Elegiste una sede que no es tuya. | Elige una de tus sedes. |
| El departamento «…» no aplica en la sede elegida. | Ese departamento no se usa en esa sede. | Elige otro o agrégale la sede en Departamentos. |
| El puesto «…» no aplica en el departamento elegido. | El puesto es de otro departamento. | Elige otro puesto. |
| Este colaborador ya no está pendiente de validar. | Otra persona ya lo validó o lo unió. | Recarga la página. |
| Elige un colaborador activo y ya validado. | Al unir elegiste uno inválido. | Elige el colaborador correcto. |
| No veo el apartado **Asignar etiqueta NFC / RFID**. | Tu rol solo consulta colaboradores, o el colaborador está **POR VALIDAR**. | Pide a Recursos Humanos que lo valide, o el permiso de editar a tu administrador. |
| No veo los datos legales. | Tu rol no tiene el permiso **Datos personales**. | Pídelo a tu administrador si lo necesitas. |

## Preguntas frecuentes

**¿Se puede borrar a un colaborador?** No. Se da de baja y se puede reingresar; así se conserva su historial.

**¿Qué significa «Corporativo»?** Que el colaborador no pertenece a una sede en particular y trabaja en todas.

**¿Por qué no aparece un departamento al dar de alta?** Porque no aplica en la sede elegida. Revísalo en [Departamentos y puestos](departamentos-y-puestos.md).

**¿Quién ve el CURP, RFC y NSS?** Solo quien tiene el permiso **Datos personales**. En la bitácora de auditoría se ven ocultos (solo los últimos 4 caracteres).

**¿Para qué sirve su QR si ya tiene gafete?** El QR se lee con la cámara de cualquier celular o tableta; el gafete NFC / RFID con el lector. Los dos llevan a la misma ficha.

**¿Cómo le doy una cuenta para entrar a la plataforma?** En [Usuarios](usuarios.md), vinculando la cuenta con su ficha de colaborador.

## Relacionado

- [Departamentos y puestos](departamentos-y-puestos.md)
- [Turnos](turnos.md)
- [Usuarios](usuarios.md)
- [Altas por verificar](altas-por-verificar.md)
