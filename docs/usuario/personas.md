---
titulo: Padrón de personas
modulos: [visitantes]
seccion: Padrones
orden: 20
resumen: Registrar antes de que lleguen a visitantes, personal de proveedores y contratistas.
---

# Padrón de personas

**¿Para qué sirve?** Aquí se registran **antes de que lleguen** las personas externas que entran a la sede: visitantes (generales, candidatos de Recursos Humanos y familiares), el personal de los proveedores y los contratistas. Así, en la caseta solo se buscan y se registra su entrada sin volver a capturar todo.

![Padrón de personas](img/personas/01-lista.png)

## Antes de empezar

- Para **ver** el padrón necesitas que tu rol pueda consultar el *Padrón de personas*. Si no lo ves en el menú, pide a tu administrador que te dé ese permiso.
- Para **registrar**, **editar** o **dar de baja** necesitas además esos permisos. Si no ves el botón **Registrar Persona** o el botón **Editar Perfil**, tu rol solo puede consultar.
- Si tu rol solo consulta, el folio de la identificación se ve oculto (por ejemplo `INE: ••••1234`). Es normal: protege los datos de la persona.

## Cómo encontrar a una persona

1. Entra a **Padrones → Padrón de personas**.
2. Escribe en el buscador **Buscar por nombre, folio o empresa...** parte del nombre, el folio o la empresa.
3. Si quieres ver solo un tipo, toca **Visitantes**, **Proveedores** o **Contratistas**. **Todos** vuelve a mostrar a todos.
4. Al tocar **Visitantes** aparece la lista **Todas las categorías**: elige *Visitante general*, *Candidato/prospecto* o *Familiar/personal*. Con los demás tipos esa lista se oculta, porque la categoría solo aplica a visitantes.
5. El botón **Pendientes de verificar** muestra las personas que la caseta registró al momento y que aún nadie revisa (ver [Altas por verificar](altas-por-verificar.md)).

![Filtro de contratistas](img/personas/02-filtro-contratistas.png)

**Qué debes ver:** solo las fichas que coinciden. Cada ficha muestra el **tipo** (con su color), si está **ACTIVO** o **DE BAJA**, la identificación, el motivo habitual de sus visitas y quién la registró o editó, con fecha y hora.

## Cómo registrar una persona

1. Toca la tarjeta **Registrar Persona** (la primera de la lista).
2. Escribe el **Nombre Completo**.
3. Elige el **Tipo**: *Visitante*, *Proveedor* o *Contratista*.
4. Si es **Visitante**, elige la **Categoría del Visitante**: *Visitante general*, *Candidato/prospecto* (viene a una entrevista) o *Familiar/personal*.
5. Si es **Proveedor** o **Contratista**, elige en **Empresa que representa** su empresa. La lista cambia según el tipo:
   - *Contratista*: solo aparecen las empresas registradas como contratistas.
   - *Proveedor*: aparecen proveedores, transporte, agencias y taxis.
   - Si la empresa no aparece, deja **-- Otra empresa (escríbela abajo) --** y escribe su nombre en **¿De qué empresa viene?**. Si lo que escribes es una empresa que **sí** está en el directorio (aunque cambien mayúsculas, acentos o «S.A. de C.V.»), al guardar la persona queda ligada a su ficha sola: así no se crean empresas repetidas.
6. En **Identificación** elige el documento (INE, Pasaporte, Licencia de conducir, CURP, Cédula profesional, Credencial IMSS u Otra) y escribe el **Folio / Número**. Puedes escribirlo con espacios o guiones: se quitan solos.
7. **Teléfono** (opcional): de 10 a 15 dígitos. Puedes escribir espacios, guiones o paréntesis.
8. Si quieres anotar el motivo habitual de sus visitas, abre **Más datos** y escríbelo en **Motivo habitual de sus visitas** (opcional; por ejemplo «Mantenimiento de elevadores»). El motivo de **cada** visita lo captura la caseta en la Bitácora de accesos.
9. Toca **Guardar**.

![Registrar persona](img/personas/03-alta.png)

**Qué debes ver:** el mensaje verde «Persona «Juan Pérez» registrada correctamente.» y la ficha nueva resaltada en la lista.

### Avisos mientras escribes

No tienes que esperar a guardar para saber si la persona ya existe:

- **Caja roja debajo del folio:** «Ese folio ya está registrado en la empresa (los espacios y guiones no cuentan). No se puede repetir.» Abajo dice de quién es. No podrás guardar: busca a esa persona en la lista. Si está dada de baja y puedes reactivarla, aparece el botón **Reactivar**: úsalo en lugar de registrarla otra vez.
- **Caja amarilla debajo del nombre:** «Ya hay personas con un nombre parecido. Revisa que no sea la misma antes de registrarla:». Es solo un aviso (dos personas pueden llamarse igual): revisa la lista y, si no es la misma, guarda normalmente.
- **Verde:** «Folio disponible.»

![Avisos de nombre parecido y folio repetido](img/personas/15-folio-y-nombre.png)

## Cómo registrar al personal de una empresa externa

1. Entra a **Padrones → Empresas externas** y toca **Ficha** en la empresa.
2. En la parte de personal toca **Agregar persona**.
3. Se abre **Registrar Persona** con la empresa ya puesta y el aviso «Registrando personal de …». La empresa queda fija (no se puede cambiar).
4. Llena el resto de los datos y toca **Guardar**.

![Desde la ficha de la empresa externa](img/personas/16-desde-empresa-externa.png)

**Qué debes ver:** al guardar **o al cerrar** la ventana regresas a la ficha de la empresa, con la persona en su personal.

## Cómo cargar muchas personas desde Excel

1. Toca **Importar** (arriba, junto al buscador).
2. Toca **Descargar plantilla** y llénala en Excel: **Tipo** (Visitante, Proveedor o Contratista) y **Nombre completo** son obligatorios; el **Folio de identificación** sirve para reconocer a quien ya está en el padrón.
3. Toca **Elegir archivo**, elige tu archivo, toca **Revisar archivo**, revisa la vista previa y toca **Guardar**.

**Qué debes ver:** el resultado con cada fila **Creada**, **Actualizada**, **Omitida (ya existía)** o **Con error**. Si escribes la **Empresa que representa** y ya está en Empresas externas, la persona queda ligada a ella. Ver [Importar desde Excel](importar-desde-excel.md).

## Cómo editar una persona

1. Busca la ficha y toca **Editar Perfil**.
2. Corrige los datos en la ventana **Actualizar Perfil**.
3. Toca **Guardar**.

![Actualizar perfil](img/personas/05-editar.png)

**Qué debes ver:** «Perfil de «Juan Pérez» actualizado correctamente.» Editar nunca cambia si la persona está activa o de baja.

> Si tu rol solo puede editar lo que tú registraste, el botón **Editar Perfil** solo aparece en tus fichas.

## Cómo dar de baja o reactivar a una persona

1. En la ficha toca el botón rojo de **Dar de baja** (círculo con raya).
2. Confirma con **Aceptar** el mensaje «¿Dar de baja este registro? Podrás reactivarlo con un clic.».
3. Para volver a activarla, toca el botón **Reactivar** (flecha circular) y confirma.

**Qué debes ver:** la ficha cambia a **DE BAJA**. Ya no aparece al buscarla en la caseta, pero **no se borra**: su historial de entradas se conserva.

## Cómo unir dos fichas de la misma persona

A veces la misma persona quedó registrada dos veces (por ejemplo «Ana López Uc» y «Ana Lopez»). Si las dos ya están verificadas, puedes unirlas:

1. Necesitas poder editar **y** dar de baja en el padrón (por ejemplo el Jefe de seguridad o el Administrador).
2. En la ficha **repetida** (la que va a quedar fuera de uso) toca el botón azul **Unir con otra ficha** (dos círculos).
3. En la ventana **Unir con otra ficha** elige, de los parecidos, la ficha que se queda. Si no aparece, búscala en **¿No está en la lista? Búscala en el padrón**.
4. Toca **Guardar unión**.

![Unir con otra ficha](img/personas/17-unir-duplicado.png)

**Qué debes ver:** ««Ana Lopez» se unió con «Ana López Uc»: sus accesos y entregas pasaron al registro correcto y el duplicado quedó fuera de uso (se conserva como historia).» La ficha que se queda toma la identificación o el teléfono que le faltaban. **No se borra nada** y la unión queda en la Bitácora de auditoría; no se puede deshacer desde la pantalla.

## En el celular

La pantalla se acomoda al celular: los botones son grandes y la ventana de registro ocupa toda la pantalla. También respeta los modos **Sol** (alto contraste) y **Noche**.

![Celular](img/personas/08-celular.png) ![Registrar en el celular](img/personas/09-celular-alta.png)

## Si algo sale mal

Los mensajes aparecen dentro de la misma ventana, arriba, en rojo. Lo que ya escribiste se conserva.

![Error dentro de la ventana](img/personas/14-error-en-dialogo.png)

| Mensaje o síntoma | Qué significa | Qué hacer |
|---|---|---|
| El nombre completo es obligatorio. | Dejaste vacío el nombre. | Escribe nombre y apellidos. |
| El folio debe tener de 4 a 30 letras o números (los espacios y guiones se quitan solos). | El folio es muy corto, muy largo o tiene signos. | Revisa el folio en la identificación. |
| El teléfono debe tener de 10 a 15 dígitos. | Faltan o sobran números. | Escribe el teléfono con lada (10 dígitos). |
| Ya existe otra persona registrada en esta empresa con ese folio de identificación: «…». | Ese folio ya es de otra persona. | Búscala en la lista; si está de baja, reactívala. |
| «…» no está registrada como Contratista: elige el tipo Proveedor o una empresa contratista. | El tipo y la empresa no coinciden. | Cambia el tipo o elige otra empresa. |
| «…» está registrada como Contratista: elige el tipo Contratista. | Elegiste *Proveedor* con una empresa contratista. | Cambia el tipo a *Contratista*. |
| El proveedor no existe en esta empresa o está desactivado (dado de baja). | La empresa se dio de baja mientras capturabas. | Elige otra o pide que la reactiven en Empresas externas. |
| No veo **Registrar Persona** ni **Editar Perfil**. | Tu rol solo consulta. | Pide el permiso a tu administrador. |
| Elige con cuál ficha se une (de los parecidos o de la búsqueda). | Tocaste **Guardar unión** sin elegir. | Marca la ficha que se queda. |
| Elige un registro activo y ya verificado del padrón (no puede ser el mismo). | Elegiste la misma ficha, una de baja o una pendiente. | Elige otra ficha activa y verificada. |
| El folio se ve con puntos (••••). | Tu rol solo consulta y el folio está protegido. | Es normal; quien puede editar lo ve completo. |

## Preguntas frecuentes

**¿Se puede borrar a una persona?** No. Se da de baja para que no aparezca en la caseta; su historial se conserva y la puedes reactivar cuando quieras.

**¿Por qué no me deja guardar el mismo folio dos veces?** Porque un folio identifica a una sola persona. Si ya existe, usa esa ficha.

**Dos personas se llaman igual, ¿puedo registrarlas?** Sí. El aviso amarillo solo te pide revisar; si son distintas, guarda normalmente.

**¿Qué pasa con las personas que registra la caseta al momento?** Quedan **pendientes de verificar** hasta que alguien con permiso las revise. Ver [Altas por verificar](altas-por-verificar.md).

**Registré dos veces a la misma persona, ¿cómo lo arreglo?** Con **Unir con otra ficha**: todo lo registrado pasa a la ficha correcta y la repetida queda fuera de uso.

**¿El folio tiene que llevar guiones?** No. Escríbelo como venga; los espacios y guiones se quitan solos.

## Relacionado

- [Empresas externas](proveedores.md)
- [Padrón vehicular](vehiculos.md)
- [Altas por verificar](altas-por-verificar.md)
- [Bitácora de accesos](accesos.md)
- [Importar desde Excel](importar-desde-excel.md)
