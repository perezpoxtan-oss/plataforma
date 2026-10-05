# Registro de cambios

Formato: [versiones semánticas](https://semver.org/lang/es/) — MAYOR.MENOR.PARCHE.

## [Sin publicar]
- Proveedores / Empresas Externas, con las pantallas de SEGCAT: fichas por categoría (9 píldoras), búsqueda, filtros por sede y estado, alta y edición con RFC y teléfono validados, sedes donde opera (todas, incluidas las futuras, o solo algunas), baja / vetada reversible y auditoría. Ficha del proveedor con pestañas Resumen, Personal y Flotilla. Con alcance de sede se registra para su sede y, si el nombre ya existe, no se duplica: se agrega su sede. Búsqueda (`GET /proveedores/buscar`) y alta rápida (`POST /proveedores/rapido`) para otros módulos. Corrige que en SEGCAT "Visible en todas las sedes" guardaba al proveedor sin ninguna sede. Lección 11.
- Padrón de personas (`/personas`), con las pantallas y colores de SEGCAT: visitantes (general, candidato/prospecto, familiar), personal de proveedores y contratistas en una sola lista con filtros por tipo, categoría y texto; folio de identificación normalizado y único por empresa (el aviso dice quién lo tiene), oculto (`••••1234`) para quien solo consulta; teléfono validado; alcance «propios»; baja y reactivación solo con `visitantes.eliminar`; auditoría con folio y teléfono enmascarados. Contrato con la ficha del proveedor (`?nuevo=1&proveedor={id}` y regreso con `volver=proveedor`), búsqueda (`GET /personas/buscar`) y registro rápido (`POST /personas/rapido`, 409 con la persona del folio) para la Bitácora de accesos. 12 personas demo y lección 12.
- Configuración: correo de la plataforma (SMTP, contraseña cifrada, correo de prueba), avisos por correo por empresa (alta provisional a Recursos Humanos) y respaldos de la base (diario automático, antes de cada actualización y manual; 14 días; descarga auditada).
- Las sedes se llaman «Sedes» en todos los rubros (antes Hoteles, Plantas, Torres o Privadas).
- Matriz de permisos: cada área (Dirección, Recursos Humanos, Seguridad) se contrae y muestra cuántos permisos tiene otorgados; botones Expandir todo / Contraer todo.
- Las fechas se muestran en la hora local de la sede o de la empresa (antes salían en hora universal, 5 horas adelante en Cancún).
- Pantallas de error en español (sin permiso, no encontrado, sesión expirada, demasiados intentos, error del sistema, mantenimiento).
- Bitácora de auditoría: consulta legible de quién hizo qué y cuándo, con filtros, detalle de antes y después y exportación a Excel.
- Aviso en el Inicio para Recursos Humanos con las altas provisionales por validar.
- En hoteles la sede se llama «Sede» (antes «Hotel»), a petición del cliente; «Nueva Sede», «Nueva Planta»… con el género correcto.
- Lecciones 8 (Empresas y Sedes) y 9 (Identidad y Bitácora de auditoría).
- Áreas **Dirección** (estructura y gobierno), **Recursos Humanos** (Colaboradores) y **Seguridad** (padrones, operación y reportes) en el catálogo y la Matriz de permisos; menú propio de Recursos Humanos y rol base "Recursos Humanos" (nivel 25).
- Altas provisionales de colaboradores: la caseta registra a quien aún no existe (con aviso si ya hay alguien con ese nombre) y Recursos Humanos lo valida con su número de empleado o lo une con su registro correcto.
- Turnos: horarios de la empresa (también los que cruzan la medianoche, con duración y aviso "Termina al día siguiente"), sedes que usan cada turno (todas, incluidas las futuras, o solo algunas; el usuario de sede solo cambia la suya), filtro por sede, mismas pantallas de SEGCAT y auditoría.
- Ajustes de pruebas QA (ronda 2):
  - Las ventanas de alta y edición se cierran limpias: al cerrarlas (Cancelar, X o Esc) y volver a abrirlas ya no conservan lo capturado; tras un error se reabren con los datos para corregir y, si se cierran, quedan vacías. Genérico para todas las pantallas; excepción con `data-conservar-al-cerrar` (R-01 / U-01).
  - Roles: debajo del nivel jerárquico se explica el nivel propio y desde qué número se puede crear, con la escala de la empresa; el aviso del navegador y el del servidor lo dicen en español claro (`data-mensaje-min`) (R-02).
  - Nuevo rol base **Asistente** (nivel 40, "Apoyo de gestión de seguridad en su sede"), como en SEGCAT: plantilla y, con la migración `2026_10_06_000100_agregar_rol_asistente`, en cada empresa existente que no lo tenga (U-02).
  - Multi-empresa visible: "Usuarios de «Empresa»" y "Roles de «Empresa»" bajo el título, y la línea "Empresa: …" en el alta y edición de usuarios (U-01).
- Colaboradores, con las mismas pantallas de SEGCAT: sede física, sedes adicionales, departamento y puesto con combos dependientes, aviso en vivo de número repetido, baja lógica y reingreso, filtros por sede y departamento. Número de empleado, CURP, RFC y NSS únicos por empresa (antes en toda la base) con validación de formato. Datos personales protegidos por el nuevo permiso `colaboradores.datos_personales` (Administrador) y enmascarados en la bitácora. Registro rápido (`POST /colaboradores/rapido`) y búsqueda (`GET /colaboradores/buscar`) para otros módulos.
- Usuarios: el campo Núm. Colaborador autocompleta con Colaboradores y vincula la cuenta con su colaborador (`users.colaborador_id`, un colaborador por cuenta); la ficha avisa si el colaborador está de baja.
- Departamentos (sedes donde aplica: todas, incluidas las futuras, o solo algunas) y Puestos (operativo o administrativo, ligados a departamentos), con las mismas pantallas de SEGCAT, aviso en vivo de nombre repetido y auditoría.
- Ajustes de pruebas QA (ronda 1):
  - Usuarios: desbloqueo manual de cuentas bloqueadas por intentos fallidos (permiso `usuarios.desbloquear`, para Administrador y Jefe de seguridad), con etiqueta "BLOQUEADO hasta HH:MM" y auditoría `usuarios.desbloqueado` (A-03).
  - Plantilla Agente: en Padrones solo consulta (`ver`); en Operación conserva ver, crear, editar, imprimir y firmar. Los Agentes de empresas que seguían igual a la plantilla anterior se ajustan; los personalizados no se tocan (M-01).
  - Modos de pantalla Normal → Sol → Noche → Normal: Sol es alto contraste real para exteriores y Noche un tema oscuro para turnos nocturnos; se recuerda por equipo y el alto contraste anterior pasa a Sol (M-03).
  - Menú lateral del celular: todos los grupos inician cerrados y se resalta el de la pantalla actual (M-04).
  - Corrección: Zonas y áreas daba error 500 al abrir la pestaña de zonas cuando la empresa ya tenía secciones.
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
