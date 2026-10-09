---
titulo: Roles y permisos
modulos: [roles, permisos]
seccion: Estructura
orden: 40
resumen: Los perfiles de usuario (roles), su jerarquía y la matriz que dice qué puede hacer cada rol en cada módulo.
---

# Roles y permisos

**¿Para qué sirve?** Los **roles** son perfiles (Administrador, Supervisor, Agente…). Los **permisos** dicen qué puede hacer cada rol en cada pantalla (módulo): ver, crear, editar, eliminar, aprobar, imprimir… y sobre qué registros (los propios, los de su sede o toda la empresa). A cada usuario se le asigna un rol en [Usuarios](usuarios.md).

![Roles y jerarquía](img/roles/1-roles.png)

## Antes de empezar

- Para ver estas pantallas tu rol debe poder consultar *Roles* y *Matriz de permisos* (normalmente el administrador).
- Cada rol tiene un **nivel**: menor número = más autoridad. Solo puedes administrar roles con un número **mayor** que el tuyo.
- Las casillas grises de la matriz son permisos que tú no tienes y por eso no puedes dar ni quitar.

### Roles que trae cada empresa

| Rol | Nivel | Para qué |
|---|---|---|
| Administrador | 10 | Administra toda su empresa. |
| Director | 20 | Consulta y aprueba en toda la empresa. |
| Recursos Humanos | 25 | Administra el personal y valida las altas provisionales de la caseta. |
| Jefe de seguridad | 30 | Opera y supervisa seguridad en su sede; desbloquea usuarios. |
| Asistente | 40 | Apoyo de gestión de seguridad en su sede: captura, corrige, imprime y exporta; no elimina, no aprueba ni firma. |
| Supervisor | 50 | Da seguimiento a la operación de su sede. |
| Agente | 60 | Registra la operación de caseta; en Padrones solo consulta. |

Son un punto de partida: cada empresa los ajusta en la Matriz de permisos.

## Cómo crear un rol

1. Entra a **Estructura → Roles**. Se abre **Roles y Jerarquía**.
2. Toca la tarjeta **Nuevo Rol**.
3. Escribe el **Nombre del Rol**, una **Descripción** y el **Nivel Jerárquico**. Debajo dice tu nivel, desde qué número puedes crear y qué niveles ya se usan. Para meterlo entre dos niveles usa un número intermedio (por ejemplo 35 entre 30 y 40).
4. Toca **Crear Rol**.

![Nivel explicado al crear un rol](img/roles/7-nivel-explicado.png)

**Qué debes ver:** «Rol creado correctamente. Ya puedes configurarle sus permisos por módulo.»

## Cómo editar, desactivar o eliminar un rol

1. **Lápiz:** se abre **Editar Rol**. Cambia el nombre, la descripción, el nivel o desactívalo, y toca **Guardar Cambios**. Un rol desactivado deja sin permisos a quienes lo tienen.
2. **Bote de basura:** confirma con **Aceptar** («¿Eliminar el rol «…»? Solo se puede si ningún usuario lo tiene asignado.»). Si está gris, primero reasigna a esas personas.
3. **Ver permisos:** abre la matriz de ese rol.

![Editar rol](img/roles/2-editar-rol.png)

## Cómo dar o quitar permisos

1. Entra a **Estructura → Matriz de permisos**. Se abre **Permisos por Rol**.
2. Arriba toca el rol (la píldora amarilla es el que estás viendo).
3. Los módulos están agrupados por área (Dirección, Recursos Humanos, Seguridad). Toca el nombre de un área para abrirla o cerrarla; **Expandir todo** y **Contraer todo** las abren o cierran todas.
4. En cada módulo marca **Ver**, **Crear**, **Editar**, **Eliminar** y, si las tiene, las **Otras acciones** (Aprobar, Firmar, Imprimir, Exportar, Configurar…).
5. En **Alcance** elige sobre qué registros aplica:
   - **Solo los propios:** solo lo que esa persona capturó.
   - **Su sede:** lo de las sedes asignadas al usuario.
   - **Toda la empresa:** todo.
6. Toca **Guardar Permisos de este Rol** y confirma con **Aceptar**.

![Matriz de permisos](img/roles/3-matriz.png)

**Qué debes ver:** «Permisos actualizados correctamente.» El cambio aplica de inmediato a todos los usuarios con ese rol.

- Al marcar cualquier acción se marca **Ver** sola; al quitar **Ver** se quitan las demás.
- Si el rol es de tu nivel o superior, la matriz se abre en modo consulta.
- En el celular la tabla se desliza de lado.

![Matriz en el celular](img/roles/4-matriz-celular.png)

## Super Administrador: empresa de trabajo

Arriba aparece **Empresa de trabajo**:

- **Plantillas de la plataforma (empresas nuevas):** los roles y permisos que recibe cada empresa nueva.
- **Una empresa:** administras sus roles como si fueras su administrador.

![Selector de empresa](img/roles/5-selector-empresa.png)

## Si algo sale mal

| Mensaje o síntoma | Qué significa | Qué hacer |
|---|---|---|
| Tu nivel es 10: solo puedes crear o administrar roles de nivel 11 en adelante (número mayor = menos autoridad). | Escribiste un nivel igual o superior al tuyo. | Usa un número mayor que tu nivel. |
| Ya existe un rol con ese nivel jerárquico; cada nivel debe ser único. | Ese nivel ya lo tiene otro rol. | Usa un número intermedio libre. |
| Ya existe un rol con ese nombre. | El nombre ya se usa. | Usa otro nombre. |
| El bote de basura está gris. | Hay usuarios con ese rol. | Reasígnalos en [Usuarios](usuarios.md) o desactiva el rol. |
| No puedo marcar una casilla (está gris). | Tú no tienes ese permiso. | Pídelo a alguien de nivel superior. |
| Un usuario no ve una pantalla que le di. | Falta **Ver**, o el alcance no incluye su sede. | Revisa **Ver** y el **Alcance** del módulo. |

## Preguntas frecuentes

**¿Qué alcance doy a un agente de caseta?** Normalmente **Su sede**: así solo ve y registra lo de su sede.

**¿Cuándo uso «Solo los propios»?** Cuando alguien solo debe corregir lo que él mismo capturó.

**¿Cambiar un permiso afecta a quien ya está dentro?** Sí, de inmediato, a todos los que tienen ese rol.

**¿Puedo crear un rol con más autoridad que el mío?** No. Solo roles de nivel inferior al tuyo.

## Relacionado

- [Usuarios](usuarios.md)
- [Bitácora de auditoría](auditoria.md)
- [Configuración](configuracion.md)
