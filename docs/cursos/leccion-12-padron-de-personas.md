# Lección 12 — Padrón de personas

**Para:** Administrador del cliente, Jefe de seguridad y Operador de caseta · **Duración:** 15 minutos

## Práctica (en QA, como `admin.demo`)

1. Abre **Padrones → Padrón de personas**. Verás 12 personas de demo. Raúl Domínguez Can dice **BAJA**.
2. Toca **Contratistas**: quedan 3. Toca **Todos** y en la lista de categorías elige *Candidato/prospecto*: quedan Sofía Castillo y Miguel Ángel Pech.
3. Escribe en el buscador `ekcsjs`: aparece José Luis Ek Cauich (lo encuentras por el folio de su INE).
4. Toca **Registrar Persona**:
   - Elige el tipo **Contratista**: aparece **Empresa que representa** y desaparece la categoría.
   - Deja *No está en el directorio* y escribe «Elevadores del Golfo» en **Empresa de procedencia**.
   - Identificación **INE**, folio `mnrslr-850314-23m700` (con guiones y minúsculas). Teléfono `998 145 7820`.
   - Toca **Guardar en Padrón**: te avisa que ese folio ya lo tiene **Laura Méndez Ríos**. Cambia el folio a `GOLF900101HQRLVR01` y guarda.
5. Toca **Editar Perfil** de la persona nueva: el folio quedó en mayúsculas y sin guiones, y el teléfono solo con dígitos.
6. Dala de baja con **⊘** y reactívala con **↺**.
7. Cierra sesión y entra como `agente.demo`: ves el padrón, pero el folio sale oculto (`••••M700`), no hay **Registrar Persona** ni **Editar Perfil**.

## Para recordar

- Un folio de identificación no se repite en tu empresa, aunque se escriba con espacios o guiones distintos. Otra empresa puede tener el mismo.
- La categoría (prospecto, familiar) solo aplica a visitantes.
- Si la empresa del proveedor está en el directorio, elígela; si no, escríbela en «Empresa de procedencia».
- Quien solo consulta ve el folio oculto. Dar de baja no borra a la persona.

## Autoevaluación

1. ¿Por qué no te deja guardar a alguien con el folio `MNRSLR 850314 23M700` si ya existe `MNRSLR85031423M700`? *(Es el mismo folio: los espacios y guiones no cuentan)*
2. Un agente de caseta, ¿puede registrar personas en el padrón? *(Con la plantilla, no: solo consulta. El Administrador puede darle «Crear» en la Matriz de permisos)*
3. ¿Qué pasa al guardar una persona que abriste desde la ficha de un proveedor? *(Regresas a la ficha de ese proveedor)*
4. Un capturista con alcance «propios», ¿puede editar a una persona que registró otro usuario? *(No: solo ve el botón Editar Perfil en las que él registró)*
