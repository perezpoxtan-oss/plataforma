# Lección 2 — Roles y permisos

**Para:** Administrador del cliente · **Duración:** 20 minutos

## Objetivo

Crear un rol a la medida, darle exactamente los permisos que necesita y entender por qué no puedes dar más de lo que tú tienes.

## Conceptos

- **Nivel:** un número menor significa más autoridad. Solo administras lo que está por debajo de ti.
- **Acción:** lo que se permite hacer (Ver, Crear, Editar, Eliminar, Aprobar, Firmar…).
- **Alcance:** sobre qué registros aplica (los propios, los de su sede o los de toda la empresa).

## Práctica (en QA, empresa Hotel Demo, usuario `admin.demo`)

1. En **Roles**, crea "Coordinador nocturno" con nivel 45.
2. En **Permisos**, elige "Coordinador nocturno" y dale:
   - *Bitácora de novedades*: Ver y Crear, alcance *Su sede*.
   - *Bitácora de accesos*: Ver, alcance *Toda la empresa*.
3. Guarda. Marca solo "Crear" en otro módulo y observa que "Ver" se marca sola.
4. Intenta eliminar el rol "Agente": no te deja porque tiene usuarios.
5. Elimina "Coordinador nocturno" (no tiene usuarios).

## Para recordar

- Los cambios aplican al instante; revisa antes de guardar.
- Todo cambio queda registrado con tu nombre, la fecha, y el antes y el después.
- Si necesitas dar algo que tú no tienes, pídelo a alguien de mayor nivel.

## Autoevaluación

1. ¿Puedes crear un rol de nivel 5 si el tuyo es 10? *(No: solo números mayores que el tuyo)*
2. ¿Qué pasa si marcas "Editar" sin "Ver"? *(Se marca "Ver" sola)*
3. ¿Qué diferencia hay entre "Su sede" y "Toda la empresa"? *(Las sedes asignadas a la persona frente a todos los registros)*
