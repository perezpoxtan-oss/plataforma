# Registro de cambios

Formato: [versiones semánticas](https://semver.org/lang/es/) — MAYOR.MENOR.PARCHE.

## [Sin publicar]
- Zonas y áreas: Zonas/Edificios → Pisos → Habitaciones → Detalle (áreas y elementos) y Secciones, con las mismas pantallas de SEGCAT. Una sola estructura en árbol, alta por lote, copia de pisos, asignación masiva a secciones, tipos propios por empresa y desactivación en cascada.
- Empresas (alta por el Super Administrador con módulos y roles base; "Mi Empresa" para el cliente) y Sedes con terminología del rubro, código único por empresa, dirección completa y zona horaria propia.
- Identidad de la plataforma (solo Super Administrador): nombre, eslogan, titular, colores, símbolo e ícono, con vista previa en vivo; módulos de tipo plataforma.
- Usuarios: alta, edición, desactivar y reactivar con rol y sede, buscador y filtro por sede, cierre inmediato de sesión y reglas contra escalamiento.
- Roles y Jerarquía y Matriz de permisos con alcance por módulo, acciones adicionales y reglas contra escalamiento; selector de empresa de trabajo para el Super Administrador.
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
