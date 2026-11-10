# ADR-0002: Multi-empresa y motor de permisos

- Estado: aceptada
- Fecha: 2026-10-04

## Decisión

**Multi-empresa en una sola base de datos.** Cada modelo operativo lleva `empresa_id` y usa el trait `PerteneceAEmpresa`, que:

- filtra todas las consultas por la empresa activa (`EmpresaScope`);
- asigna la empresa activa al guardar si no viene;
- impide guardar registros de otra empresa (`DomainException`).

La empresa activa la fija el middleware `EstablecerEmpresa`: la del usuario, o la que elija el Super Administrador. En consola no hay filtro salvo que se use `Tenant::conEmpresa()`.

**Permisos sin código.** Las pantallas declaran la habilidad `modulo.accion` (`@can`, `authorize`, middleware `can:`). El `Autorizador` aplica tres filtros:

1. el módulo (y su módulo padre) está activo para la empresa (`empresa_modulos`);
2. algún rol activo del usuario tiene la acción (`rol_permisos`);
3. si hay registro, está en su alcance: `propios` (creado_por), `sede` (sedes de sus asignaciones; asignación sin sede = todas) o `empresa`. Nunca fuera de su empresa.

El Super Administrador (`users.es_superadmin`, sin empresa) pasa siempre.

**Reglas contra escalamiento** (`AdministradorRoles`): nadie otorga un permiso que no tiene ni con más alcance; nadie administra roles o usuarios de nivel igual o superior al suyo; todo cambio queda en `auditoria`.

**Plantillas de rol**: roles con `empresa_id` nulo; `ProvisionarEmpresa` las copia a cada empresa nueva junto con sus módulos.

## Excepción a la convención de nombres
Se conservan en inglés las tablas propias del framework (`users`, `sessions`, `cache`, `jobs`, `password_reset_tokens`) para no pelear con la autenticación y las colas de Laravel.

## Limitación conocida
Si un usuario tiene el mismo permiso en varios roles con distinto alcance por sede, se combina el alcance mayor con la unión de sedes. Se revisará si algún cliente necesita reglas más finas.
