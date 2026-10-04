# Registro de cambios

Formato: [versiones semánticas](https://semver.org/lang/es/) — MAYOR.MENOR.PARCHE.

## [Sin publicar]
- Estructura base: Laravel 13, CI con MariaDB 11.4 (InnoDB), documentación y plantillas de entrega.
- Núcleo multi-empresa: rubros, empresas, sedes y filtro automático por empresa.
- Motor de permisos: áreas, módulos, submódulos, acciones, roles por empresa, alcance (propios/sede/empresa), reglas contra escalamiento y auditoría.
- Catálogo base tomado de SEGCAT, plantillas de rol y alta de empresas.
- Identidad de la plataforma configurable (nombre, logos, colores).
- Comandos `plataforma:instalar` y `plataforma:superadmin`.
