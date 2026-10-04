# Registro de cambios

Formato: [versiones semánticas](https://semver.org/lang/es/) — MAYOR.MENOR.PARCHE.

## [Sin publicar]
- Portal de QA con despliegue automático: GitHub arma el paquete y el servidor lo instala, verifica y regresa solo si falla.
- Franja "Ambiente de pruebas" fuera de Producción y empresa demo con un usuario por rol.
- Pantalla de acceso, cierre por inactividad con aviso, bloqueo tras 5 intentos y estructura de pantallas (PC y celular) idénticas a SEGCAT.
- Menú principal configurable (Estructura, Padrones, Operación) según permisos, contratación y rubro.
- Hoja de estilos y JavaScript centrales; Bootstrap, íconos y tipografía servidos desde el propio servidor.
- Estructura base: Laravel 13, CI con MariaDB 11.4 (InnoDB), documentación y plantillas de entrega.
- Núcleo multi-empresa: rubros, empresas, sedes y filtro automático por empresa.
- Motor de permisos: áreas, módulos, submódulos, acciones, roles por empresa, alcance (propios/sede/empresa), reglas contra escalamiento y auditoría.
- Catálogo base tomado de SEGCAT, plantillas de rol y alta de empresas.
- Identidad de la plataforma configurable (nombre, logos, colores).
- Comandos `plataforma:instalar` y `plataforma:superadmin`.
