# Lección 6 — Turnos

**Para:** Administrador del cliente · **Duración:** 10 minutos

## Práctica (en QA, como `admin.demo`)

1. Abre **Recursos Humanos → Turnos**. Verás los 4 de demo. *Nocturno* dice "23:00 a 07:00 · 8 h" y "Termina al día siguiente".
2. En el filtro elige **Hotel Demo Centro**: *Mixto Playa* desaparece, porque solo se usa en Playa.
3. Toca **Nuevo Turno** y escribe "matutino": te avisa que ya existe. Cámbialo a **Guardia 12 h**, de 19:00 a 07:00, y guarda. La ficha debe decir "12 h" y "Termina al día siguiente".
4. Intenta crear otro turno de 08:00 a 08:00: el sistema lo rechaza.
5. En *Mixto Playa*, toca **1 sede**, marca también *Hotel Demo Centro* y guarda. Ahora dice **2 sedes**.
6. Desactiva *Guardia 12 h* con ⊘ y reactívalo con ↺.

## Para recordar

- Un turno puede cruzar la medianoche; la duración se calcula sola.
- *Todas las sedes* incluye las sedes que abras más adelante.
- Quien solo tiene una sede puede activar o quitar un turno **en su sede**, pero no crear ni editar turnos.

## Autoevaluación

1. ¿Cuánto dura un turno de 22:00 a 06:00? *(8 horas; termina al día siguiente)*
2. Un Gerente de la sede Centro quita su sede de un turno que estaba en "Todas las sedes". ¿Qué pasa con Playa? *(Playa lo sigue usando; el turno queda solo en las demás sedes)*
