# ADR-0004 — Áreas: Dirección, Recursos Humanos y Seguridad

**Fecha:** 2026-10-04 · **Estado:** aceptada

## Contexto

El catálogo heredado tenía cuatro áreas (Organización, Seguridad, Reportes, Administración) que no correspondían a cómo se vende ni a cómo se usa la plataforma. El responsable del proyecto pidió esquematizarla en módulos de negocio:

- Dirección para la estructura;
- Recursos Humanos para Colaboradores;
- Seguridad para todo lo demás.

## Decisión

- **Tres áreas:**
  - **Dirección:** estructura de la empresa, usuarios, roles, permisos, configuración y auditoría. Es la base de toda empresa.
  - **Recursos Humanos:** Colaboradores.
  - **Seguridad:** padrones, operación y reportes de seguridad.
- **Departamentos, Puestos y Turnos se quedan en Dirección.** Son catálogos que Seguridad usa aunque la empresa no contrate RH.
- **El menú no cambia de nombre** (Estructura, Padrones, Operación, como en SEGCAT) y se agrega **Recursos Humanos**.
- **La contratación es por módulo** (`empresa_modulos`). Por eso una empresa puede tener el directorio de Colaboradores sin el resto de RH.
- **Colaboradores provisionales:** la caseta da altas provisionales y RH las valida o las une con el registro correcto, con la acción `aprobar`.

## Consecuencias

- Los permisos ya otorgados no cambian: solo se reagrupan.
- Las plantillas de rol se expresan por área y menú; el Agente se limita a los menús de caseta.
- Todo módulo nuevo que guarde `colaborador_id` debe registrarse en `AdministradorColaboradores::REFERENCIAS`.
