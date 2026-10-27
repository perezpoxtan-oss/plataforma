---
titulo: Departamentos y puestos
modulos: [departamentos, puestos]
seccion: Estructura
orden: 60
resumen: Catálogos de áreas (departamentos) y rangos (puestos) que se eligen al dar de alta a un colaborador.
---

# Departamentos y puestos

**¿Para qué sirve?** Los **departamentos** son las áreas de la empresa (Seguridad, Recepción, Ama de Llaves…). Los **puestos** son los rangos (Agente, Supervisor, Gerente…). Al dar de alta a un [colaborador](colaboradores.md) eliges su departamento, su puesto y su sede; los préstamos de llaves y las autorizaciones también usan estos catálogos.

![Departamentos](img/departamentos/1-departamentos.png)

## Antes de empezar

- Para ver estas pantallas tu rol debe poder consultar *Departamentos* y *Puestos*. Si no aparecen en el menú, pide el permiso a tu administrador.
- Vienen en **todos los planes**, en el menú **Estructura**.
- Son catálogos de **toda la empresa**: solo los modifica quien tiene alcance de empresa (por ejemplo el administrador). Si tu rol es de una sede, los verás en modo consulta.

## Cómo crear un departamento

1. Entra a **Estructura → Departamentos**.
2. Toca la tarjeta **Nuevo Departamento**. Se abre **Alta Departamento**.
3. Escribe el **Nombre del Departamento**. Mientras escribes te avisa si ya existe o se parece a otro.
4. En **Sedes donde aplica** deja **Todas las sedes** (incluye las que abras después) o desmárcala y elige solo algunas (por ejemplo, un *Club de Playa* que solo existe en una sede).
5. Toca **Guardar**.

![Sedes donde aplica](img/departamentos/2-sedes-donde-aplica.png)

**Qué debes ver:** «Departamento «Spa y Bienestar» creado correctamente.» La ficha dice en qué sedes aplica, cuántos puestos tiene ligados y cuántos colaboradores activos tiene.

### Nombrar al responsable del departamento

Un departamento recién creado no tiene quien autorice: su tarjeta lo avisa con **Sin quien autorice en** (las sedes donde falta) y qué pasa con sus visitas (de fábrica, **esperan al supervisor o al jefe de seguridad**). La tarjeta usa la misma regla que la caseta: no basta con nombrar a alguien; su cuenta debe estar activa, tener el permiso **Responder** y entrar a esa sede. Además, cada tarjeta dice por sede qué cubre: «**Autoriza visitas:** Sí/No · **Firma pases:** Sí/No (lo firma …)», porque los pases de salida los puede firmar otra persona (ver [Autorizaciones](autorizaciones.md)). Toca **Nombrar responsable** (o el botón **Responsables** de arriba) para abrir **Responsables por departamento** y elegir al titular y a sus suplentes; ahí mismo se configura qué espera en la caseta (ver [Autorizaciones](autorizaciones.md)). Necesitas el permiso **Configurar** de *Autorizaciones*.

![Departamento sin responsable](img/departamentos/8-sin-responsable.png)

### Avisos mientras escribes el nombre

- **Verde:** «Nombre disponible.»
- **Rojo:** «Ya existe un departamento «…» en esta empresa. No se puede repetir.» Si está dado de baja, aparece **Reactivar**: úsalo en lugar de crearlo otra vez.
- **Amarillo:** «Se parece a uno que ya existe (sin contar acentos, mayúsculas ni signos). Revisa que no sea el mismo:». Por ejemplo «Recepcion» y «Recepción».

![Departamento parecido](img/departamentos/6-parecido.png)

## Cómo crear un puesto

1. Entra a **Estructura → Puestos**.
2. Toca la tarjeta **Nuevo Puesto**. Se abre **Alta de Puesto**.
3. Escribe el **Nombre del Puesto** (también te avisa si ya existe o se parece).
4. Si quieres, marca los **Departamentos donde aplica**. Si no marcas ninguno, el puesto sirve para cualquier departamento.
5. Toca **Guardar**.

![Puestos](img/departamentos/3-puestos.png)

**Qué debes ver:** «Puesto «…» creado correctamente.» Para encontrar un puesto usa **Buscar puesto o departamento...**.

## Cómo editar un departamento o un puesto

1. Toca el **lápiz** (Editar) de la ficha.
2. Corrige el nombre, las sedes (departamento) o los departamentos (puesto).
3. Toca **Guardar**.

![Editar puesto](img/departamentos/4-editar-puesto.png)

**Qué debes ver:** «Departamento «…» actualizado correctamente.» o «Puesto «…» actualizado correctamente.»

## Cómo dar de baja o reactivar

1. Toca el **círculo rojo** (Dar de baja) y confirma con **Aceptar**.
2. Para regresarlo, toca la **flecha circular** (Reactivar).

**Qué debes ver:** la ficha en gris con **DE BAJA**. No se borra el historial. Un puesto ligado a un departamento dado de baja conserva esa liga: se ve tachada en su ficha.

> Si tiene cosas vigentes (colaboradores, responsables, llaves o vacantes), antes de confirmar se abre [Antes de dar de baja](antes-de-dar-de-baja.md) con la lista y lo que pasará con cada una.

## En el celular

![Celular](img/departamentos/5-celular.png)

## Si algo sale mal

Los mensajes salen en rojo dentro de la ventana; lo que escribiste se conserva.

![Error dentro de la ventana](img/departamentos/7-error-en-dialogo.png)

| Mensaje o síntoma | Qué significa | Qué hacer |
|---|---|---|
| Ya existe un departamento con ese nombre en esta empresa. | El nombre ya se usa (sin importar mayúsculas). | Usa el existente o reactívalo. |
| Ya existe un puesto con ese nombre en esta empresa. | El puesto ya existe. | Usa el existente o reactívalo. |
| Marca al menos una sede o elige «Todas las sedes». | Quitaste todas las sedes. | Marca al menos una sede. |
| Aparece el globo «Completa este campo». | El nombre está vacío. | Escribe el nombre. |
| No veo **Nuevo Departamento** ni el lápiz. | Tu rol no tiene alcance de empresa. | Pide el cambio a tu administrador. |
| Un departamento no aparece al dar de alta a un colaborador. | No aplica en la sede elegida o está dado de baja. | Agrégale la sede o reactívalo. |

## Preguntas frecuentes

**¿Qué diferencia hay entre departamento y puesto?** El departamento es el área donde trabaja (Recepción); el puesto es su rango o posición (Recepcionista, Gerente).

**¿Para qué sirve «Departamentos donde aplica» en un puesto?** Para que al dar de alta a un colaborador solo aparezcan los puestos de su departamento. Los puestos sin departamento salen siempre.

**¿Puedo borrar un departamento?** No; se da de baja para conservar el historial.

**¿Cuál es la diferencia entre puesto y departamento?** El **puesto** es el rango (Agente, Supervisor, Camarista); el **departamento** es el área (Seguridad, Ama de Llaves). La pantalla de Puestos lo recuerda debajo del título.

**No me deja nombrar al responsable de un departamento.** Se necesita el permiso **Configurar** de **Autorizaciones departamentales** en la Matriz de permisos; la tarjeta «Sin quien autorice» te lo dice si no lo tienes.

**¿Dos departamentos pueden llamarse igual?** No dentro de la misma empresa.

**¿Qué hace el responsable de un departamento?** Autoriza las visitas que llegan a la caseta para su departamento y entrevista a los candidatos que Recursos Humanos le canaliza. Se nombra en **Responsables** (desde Departamentos, Recepción → Ajustes o Autorizaciones).

## Relacionado

- [Colaboradores](colaboradores.md)
- [Turnos](turnos.md)
- [Autorizaciones](autorizaciones.md)
