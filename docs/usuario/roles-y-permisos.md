# Roles y permisos

Los **roles** son perfiles (Administrador, Supervisor, Agente…). Los **permisos** dicen qué puede hacer cada rol en cada módulo. Se configuran en **Estructura → Organización Interna → Roles** y **→ Matriz de permisos**.

## Roles y Jerarquía

![Roles](img/roles/1-roles.png)

- Cada ficha muestra el **nivel**: menor número = más autoridad. Solo puedes administrar roles con un número **mayor** que el tuyo.
- Debajo del título se ve de qué empresa son los roles: "Roles de «Hotel Demo»".
- **Nuevo Rol:** escribe el nombre, una descripción y un nivel que no esté ocupado. Para meterlo entre dos niveles usa un número intermedio, por ejemplo 25 entre 20 y 30.
- Debajo del nivel la pantalla te dice **tu nivel** y desde qué número puedes crear: "Tu nivel es 10. Solo puedes crear roles de nivel 11 en adelante (número mayor = menos autoridad: 20 Director, 30 Jefe de seguridad, 40 Asistente, 50 Supervisor, 60 Agente)". Si escribes un número menor, el aviso lo explica igual.

![Nivel explicado al crear un rol](img/ajustes2/04-nivel-de-rol-explicado.png)

### Roles que trae cada empresa

| Rol | Nivel | Para qué |
|---|---|---|
| Administrador | 10 | Administra toda su empresa |
| Director | 20 | Consulta y aprueba en toda la empresa |
| Jefe de seguridad | 30 | Opera y supervisa seguridad en su sede; desbloquea usuarios |
| Asistente | 40 | Apoyo de gestión de seguridad en su sede: captura, corrige, imprime y exporta en Operación y en Padrones; no elimina, no aprueba ni firma |
| Supervisor | 50 | Da seguimiento a la operación de su sede |
| Agente | 60 | Registra la operación de caseta; en Padrones solo consulta |

Son un punto de partida: cada empresa los ajusta en la Matriz de permisos.
- **Lápiz:** cambia nombre, descripción, nivel, o desactiva el rol. Un rol desactivado deja sin permisos a quienes lo tienen.
- **Bote de basura:** solo funciona si nadie tiene ese rol. Si está gris, primero reasigna a esas personas.

![Editar rol](img/roles/2-editar-rol.png)

## Matriz de permisos

![Matriz](img/roles/3-matriz.png)

1. Arriba elige el rol (la píldora amarilla es el rol que estás viendo).
2. Marca lo que ese rol puede hacer en cada módulo: **Ver, Crear, Editar, Eliminar** y, si el módulo las tiene, **otras acciones** como Aprobar, Firmar, Imprimir o Exportar.
3. En **Alcance** decide sobre qué registros aplica:
   - *Solo los propios*: solo lo que esa persona capturó.
   - *Su sede*: lo de las sedes donde está asignada.
   - *Toda la empresa*: todo.
4. Pulsa **Guardar Permisos de este Rol** y confirma. El cambio aplica de inmediato a todos los que tienen ese rol.

Para tener en cuenta:

- Al marcar cualquier acción se marca "Ver" sola; al quitar "Ver" se quitan las demás.
- Las casillas grises son permisos que tú no tienes y por eso no puedes dar ni quitar.
- Si el rol es de tu nivel o superior, la pantalla se abre en modo consulta.

En celular la tabla se desliza de lado.

![Matriz en celular](img/roles/4-matriz-celular.png)

## Super Administrador: empresa de trabajo

![Selector](img/roles/5-selector-empresa.png)

Arriba aparece **Empresa de trabajo**:

- **Plantillas de la plataforma:** son los roles y permisos que recibe cada empresa nueva al darse de alta.
- **Una empresa:** administras sus roles como si fueras su administrador.
