# Lección 8 — Empresas y Sedes

**Para:** Super Administrador y Administrador del cliente · **Duración:** 15 minutos

## Práctica (en QA)

1. Como **Super Administrador**:
   - abre Estructura → Empresas y da de alta "Grupo Caribe" con RFC `GCA200101AB1`, rubro Hotel y zona horaria Cancún;
   - en **Empresa de trabajo** elige Grupo Caribe y verifica que ya tiene sus roles base (Roles).
2. Como `admin.demo`:
   - abre Estructura → Sedes y da de alta la **Nueva Sede** "Hotel Demo Aeropuerto" con código `AER`, ciudad Cancún y estado Quintana Roo;
   - intenta otra sede con el código `aer`: no lo deja, porque el código no se repite dentro de la empresa.
3. Edita la sede Aeropuerto y deja la zona horaria en "La de la empresa".
4. Desactívala con **⊘** y vuelve a activarla.

## Para recordar

- **Una empresa tiene varias sedes.** El Director ve todas; los usuarios de una sede solo la suya.
- **Zona horaria:** las horas se muestran en la de la sede si el usuario trabaja en una sola sede con zona propia; si no, en la de la empresa.
- **Desactivar no borra:** el historial se conserva.

## Autoevaluación

1. ¿Puede haber dos sedes con el código `CEN` en la misma empresa? *(No; en empresas distintas sí)*
2. ¿Quién da de alta una empresa nueva? *(Solo el Super Administrador)*
