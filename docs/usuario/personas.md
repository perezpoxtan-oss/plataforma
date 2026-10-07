# Padrón de personas

El registro previo de las personas externas que entran a la empresa: **Padrones → Padrón de personas**. Aquí están los visitantes (generales, candidatos de Recursos Humanos y familiares), el personal de los proveedores y los contratistas. La caseta los busca aquí para registrar su acceso sin capturar todo cada vez.

![Padrón de personas](img/personas/01-lista.png)

Cada ficha muestra:

- **Tipo** (arriba a la izquierda): *Visitante general*, *Candidato/Prospecto*, *Familiar/Personal*, *Proveedor* o *Contratista*, cada uno con su color.
- **ACTIVO** o **BAJA**.
- **Viene de:** la empresa que representa o de donde viene.
- **Identificación:** tipo y folio. Si tu rol solo consulta el padrón, el folio se ve oculto (por ejemplo, `INE: ••••H800`).
- El motivo de la visita y quién la registró o editó, con fecha y hora.

## Buscar y filtrar

- Escribe en el buscador un nombre, una empresa o un folio.
- Toca **Visitantes**, **Proveedores** o **Contratistas** para ver solo ese tipo; **Todos** los vuelve a mostrar.
- Con la lista de categorías ves solo a los *Visitantes generales*, *Candidatos/prospectos* o *Familiares*.

![Filtro de contratistas](img/personas/02-filtro-contratistas.png)

## Registrar una persona

1. Toca **Registrar Persona**.
2. Escribe el **Nombre Completo** y elige el **Tipo**.
3. Si es **Visitante**, elige su **Categoría**: visitante general, candidato/prospecto (viene a una entrevista) o familiar/personal.
4. Si es **Proveedor** o **Contratista**, elige en **Empresa que representa** la empresa del directorio de proveedores. Si no aparece, déjalo en *No está en el directorio* y escribe su nombre en **Empresa de procedencia**.
5. **Identificación:** elige el tipo (INE, pasaporte, licencia…) y escribe el **folio**. Puedes escribirlo con espacios o guiones: se guardan solos sin ellos.
6. **Teléfono** (opcional): de 10 a 15 dígitos; puedes escribir espacios, guiones o paréntesis.
7. **Motivo de la Visita** (opcional).
8. Toca **Guardar en Padrón**. La lista se recarga y la ficha nueva queda resaltada.

![Registrar persona](img/personas/03-alta.png)

![Contratista con su empresa](img/personas/04-alta-contratista.png)

### Si el folio ya existe

Un folio de identificación no puede repetirse en tu empresa. Si ya lo tiene alguien, el aviso te dice **quién** (por ejemplo, «Laura Méndez Ríos» (Visitante)). Búscala en la lista: probablemente ya está registrada.

![Folio repetido](img/personas/06-folio-duplicado.png)

## Registrar al personal de un proveedor

Desde la ficha de un proveedor, el botón para agregar personal abre aquí el alta con la empresa y el tipo ya elegidos (contratista o proveedor, según la empresa) y el aviso «Registrando personal de …». La empresa queda **fija** (con un candado: no se puede cambiar por otra). Al guardar **o al cerrar** la ventana regresas a la ficha del proveedor.

![Desde la ficha del proveedor](img/personas/07-desde-proveedor.png)

## Editar

Toca **Editar Perfil** en la ficha, corrige y toca **Actualizar Cambios**. Si tu rol solo puede editar lo que tú registraste, el botón aparece solo en tus fichas.

![Actualizar perfil](img/personas/05-editar.png)

## Dar de baja y reactivar

- **⊘** da de baja a la persona (ya no aparece al buscarla en la caseta). No se borra: su historial se conserva.
- **↺** la reactiva.

Editar el perfil nunca cambia si está activa o de baja.

## Lo que ve cada rol

| Rol (plantilla) | Puede |
|---|---|
| Administrador, Jefe de seguridad | Todo: registrar, editar, dar de baja y reactivar |
| Asistente | Registrar y editar |
| Supervisor | Registrar y editar |
| Director, Agente | Solo consultar (folio oculto) |

![Vista de consulta (Agente)](img/personas/13-agente-consulta.png)

## En el celular y en modo Noche

La pantalla se acomoda al celular y respeta los modos **Sol** y **Noche**.

![Celular](img/personas/08-celular.png)
![Alta en el celular](img/personas/09-celular-alta.png)
![Modo Noche](img/personas/10-noche.png)
![Editar en modo Noche](img/personas/11-noche-editar.png)
![Modo Sol](img/personas/12-sol.png)

## Avisos mientras escribes (Ronda 5)

No tienes que esperar a guardar para saber si alguien ya está registrado:

- **Folio / Número**: si ese folio ya lo tiene otra persona de tu empresa, aparece una caja **roja**: «Ese folio ya está registrado en la empresa (los espacios y guiones no cuentan)». Abajo dice de quién es. No se podrá guardar: busca a esa persona en la lista. Si está dada de baja y tú puedes reactivar, toca **Reactivar** en vez de registrarla otra vez.
- **Nombre completo**: si hay personas con un nombre muy parecido, aparece una caja **amarilla** con sus nombres. Es solo un aviso (dos personas pueden llamarse igual): revisa que no sea la misma antes de guardar.

![Avisos de nombre parecido y folio repetido](img/ronda-5b/personas-folio-y-nombre.png)

En el celular el aviso aparece debajo del teléfono, antes de la nota del folio:

![Aviso en el celular](img/ronda-5b/personas-folio-movil.png)

Así se ve en modo **Noche**:

![Modo Noche](img/ronda-5b/personas-folio-noche.png)

## La empresa según el tipo (Ronda 5)

La lista **Empresa que representa** cambia según el **Tipo**:

- **Contratista**: solo empresas registradas como **Contratista**.
- **Proveedor**: proveedores, transporte, agencias y taxis (no contratistas).

![Contratista: solo empresas contratistas](img/ronda-5b/personas-empresas-contratista.png)

![Proveedor: el resto de las empresas](img/ronda-5b/personas-empresas-proveedor.png)

Si la empresa no aparece, deja «No está en el directorio de proveedores» y escribe su nombre en **Empresa de procedencia**. Si la empresa está mal clasificada, pide que corrijan su categoría en Padrones → Empresas Externas.

Desde la ficha de una empresa externa la empresa ya viene puesta y no se puede cambiar:

![Desde la ficha de Constructora Maya](img/ronda-5b/ficha-agregar-persona.png)

El **Agente** solo consulta el padrón: no ve el botón de registrar ni los avisos.

![Lo que ve el Agente](img/ronda-5b/personas-agente.png)
