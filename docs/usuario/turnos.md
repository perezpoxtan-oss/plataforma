---
titulo: Turnos
modulos: [turnos]
seccion: Estructura
orden: 70
resumen: Los horarios fijos de la empresa (Matutino, Vespertino, Nocturno…) y las sedes que usan cada uno.
---

# Turnos

**¿Para qué sirve?** Los turnos son los **horarios fijos** de la empresa: Matutino, Vespertino, Nocturno… Vienen en todos los planes (menú **Estructura**). Se usan en otras pantallas, por ejemplo en [Rutas de transporte](rutas.md) (cada ruta puede llevar su turno; es opcional) y en [Colaboradores](colaboradores.md) (el turno que trabaja cada persona).

![Turnos](img/turnos/1-turnos.png)

## Antes de empezar

- Para ver la pantalla tu rol debe poder consultar *Turnos*. Si no aparece en el menú, pide el permiso a tu administrador.
- Crear, editar y dar de baja turnos es de **toda la empresa**: solo lo hace quien tiene alcance de empresa (por ejemplo el administrador).
- Si tu usuario tiene **una sola sede**, ves todos los turnos de la empresa y solo puedes activar o quitar el turno **en tu sede**.

## Cómo leer la pantalla

1. Entra a **Estructura → Turnos**.
2. Cada ficha muestra el **horario** y cuánto dura (por ejemplo «07:00 a 15:00 · 8 h»), el aviso **Termina al día siguiente** si cruza la medianoche, y en qué **sedes** se usa.
3. Para encontrar un turno usa **Buscar turno...** o **Todas las sedes**.

## Cómo dar de alta un turno

1. Toca la tarjeta **Nuevo Turno**. Se abre **Alta de Turno**.
2. Escribe el **Nombre del Turno**. Mientras escribes te avisa si ya existe o se parece a otro.
3. Escribe la **Hora Inicio** y la **Hora Fin** en 24 horas (por ejemplo `22:00` y `06:00`).
   - Si el fin es menor que el inicio, el turno termina al día siguiente. Es correcto.
   - Lo único que no se permite es que empiece y termine a la misma hora.
4. En **Sedes que usan este turno** deja **Todas las sedes** (incluye las que abras después) o desmárcala y elige las sedes.
5. Toca **Guardar**.

![Alta de turno](img/turnos/2-alta-turno.png)

**Qué debes ver:** «Turno «Nocturno Fin de Semana» creado correctamente.»

### Avisos mientras escribes el nombre

- **Verde:** «Nombre disponible.»
- **Rojo:** «Ya existe un turno «…» en esta empresa. No se puede repetir.» Si está dado de baja, aparece **Reactivar**.
- **Amarillo:** «Se parece a uno que ya existe (sin contar acentos, mayúsculas ni signos). Revisa que no sea el mismo:».

![Turno repetido](img/turnos/7-ya-existe.png)

## Cómo cambiar las sedes de un turno

1. Toca el botón de sedes de la ficha (por ejemplo **1 sede** o **Todas las sedes**). Se abre **Sedes que usan "…"**.
2. Marca o desmarca las sedes.
3. Toca **Guardar**.

![Sedes que usan el turno](img/turnos/3-sedes-que-usan.png)

**Qué debes ver:** «Sedes del turno «…» actualizadas.» Si tu usuario tiene una sola sede, en esta ventana solo aparece tu sede; las demás se quedan como están.

## Cómo editar, dar de baja o reactivar un turno

1. **Editar** (lápiz): cambia el nombre o el horario en **Editar Turno** y toca **Guardar**. Verás «Turno «…» actualizado correctamente.»
2. **Dar de baja** (círculo rojo): confirma con **Aceptar**. El turno no se borra y su tarjeta dice **DE BAJA**.
3. **Reactivar** (flecha circular): lo vuelve a activar.

> Si tiene cosas vigentes (colaboradores o rutas que lo usan), antes de confirmar se abre [Antes de dar de baja](antes-de-dar-de-baja.md) con la lista y lo que pasará con cada una.

## En el celular y en los modos Noche y Sol

![Celular](img/turnos/4-celular.png)

![Modo Noche](img/turnos/5-modo-noche.png)

![Modo Sol](img/turnos/6-modo-sol.png)

## Si algo sale mal

Los mensajes salen en rojo dentro de la ventana; lo que escribiste se conserva.

![Error dentro de la ventana](img/turnos/8-error-en-dialogo.png)

| Mensaje o síntoma | Qué significa | Qué hacer |
|---|---|---|
| La hora de fin debe ser distinta a la de inicio. | Pusiste la misma hora dos veces. | Corrige una de las horas. |
| Escribe la hora de inicio como HH:MM (24 horas). / Escribe la hora de fin como HH:MM (24 horas). | La hora está mal escrita. | Escribe por ejemplo `07:00`. |
| Ya existe un turno con ese nombre en esta empresa. | El nombre ya se usa. | Usa el existente o reactívalo. |
| Marca al menos una sede o elige «Todas las sedes». | Quitaste todas las sedes. | Marca al menos una. |
| Un turno no aparece al capturar una ruta o un colaborador. | No está marcado para esa sede o está dado de baja. | Agrégale la sede o reactívalo. |

## Preguntas frecuentes

**¿Cómo capturo un turno nocturno?** Escribe la hora de inicio y la de fin normalmente (por ejemplo 23:00 a 07:00). La ficha dirá **Termina al día siguiente**.

**¿Para qué sirven las sedes del turno?** Para que en cada sede solo aparezcan los turnos que de verdad se usan ahí.

**¿Puedo borrar un turno?** No; se da de baja para conservar el historial.

**¿Qué pasa con los colaboradores si doy de baja su turno?** Lo conservan (su ficha dice «turno desactivado»), pero ya no se ofrece al dar de alta a alguien nuevo. Para ver quién trabaja en cada turno, usa el filtro **Todos los turnos** en [Colaboradores](colaboradores.md).

## Relacionado

- [Rutas de transporte](rutas.md)
- [Colaboradores](colaboradores.md)
- [Departamentos y puestos](departamentos-y-puestos.md)
