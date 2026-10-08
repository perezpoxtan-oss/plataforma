# Menús y «Mis pendientes»

Reorganización de la navegación aprobada por el dueño del proyecto (lección 35). No cambia rutas, permisos ni pantallas: solo dónde aparece cada módulo y con qué nombre.

## Acomodo (mismo orden en PC y celular)

| # | Menú (`menus.clave`) | Sección (`seccion_menu`) | Módulos (`modulos.clave` → nombre en el menú) |
|---|---|---|---|
| 1 | Operación (`operacion`) | Caseta | `accesos` Bitácora de accesos · `prestamo_llaves` Préstamo de llaves · `pases_salida` Pases de salida · `transporte` Bitácora de transporte |
| | | Incidentes | `novedades` **Novedades** · `lost_found` Lost & Found · `robo` **Robo** |
| | | Protección civil | `recorridos_pc` Recorridos de Protección Civil |
| | | Activos | `responsivas` Responsivas · `vouchers` Vouchers de reposición (antes en Padrones) |
| | | Consulta | `procedimientos` Procedimientos |
| 2 | Padrones (`padrones`) | Personas y vehículos | `proveedores` **Empresas externas** · `visitantes` Padrón de personas · `vehiculos` Padrón vehicular |
| | | Inventarios | `llaves` Catálogo de llaves · `gafetes` Gafetes · `equipos` Equipos de seguridad · `equipos_pc` Equipos de Protección Civil |
| | | Instalaciones | `estacionamientos` Estacionamientos · `rutas` Rutas de transporte |
| | | Herramientas | `etiquetas_qr` Etiquetas QR |
| 3 | Recursos Humanos (`recursos_humanos`) | Personal | `colaboradores` |
| | | Recepción y candidatos | `recepcion_rh` Recepción de RR. HH. · `candidatos` |
| | | Catálogos | `departamentos` · `puestos` · `turnos` |
| 4 | Informes (`informes`, nuevo, `bi-graph-up`) | Informes | `dashboard` Tablero · `bitacora_dia` **Bitácora del día** · `tendencias` · `informe_ejecutivo` |
| 5 | Estructura (`estructura`) | Empresa | `empresas` · `sedes` · `espacios` Zonas y áreas |
| | | Accesos y permisos | `usuarios` · `roles` · `permisos` Matriz de permisos |
| | | Sistema | `configuracion` · `identidad` (solo superadministrador) · `auditoria` Bitácora de auditoría |

En **negritas**, los nombres cortos que cambian respecto al nombre del módulo: se guardan en la columna nueva `modulos.nombre_menu` (el nombre del módulo en la Matriz de permisos no cambia). Prioridad del nombre visible: `empresa_modulos.nombre_visible` → terminología del rubro → `nombre_menu` → `nombre`.

**Autorizaciones departamentales** (`autorizaciones`) sale de los menús (`menu_id` nulo): conserva su ruta `/autorizaciones` y su permiso; se llega desde «Mis pendientes», la campana e Inicio.

## Reglas

- Un renglón aparece solo si el usuario puede `modulo.ver` **y** el módulo tiene pantalla: `modulos.ruta` no nula y registrada (`Route::has`). Antes, un módulo sin ruta salía con enlace a «en migración»; ahora no sale en ningún menú (PC, celular ni barra inferior). La pantalla `/modulos/{clave}` sigue existiendo.
- Un menú o sección sin renglones visibles se oculta. Por eso **Informes** no se ve hasta que exista alguna de sus pantallas: basta registrar la ruta en `CatalogoSeeder::RUTAS`.
- Barra inferior del celular: Inicio · Accesos · Novedades · Menú (orden de Operación).
- Plantillas de rol: `RolesPlantillaSeeder::esPadron()` decide por menú. Para que mover Vouchers a Operación no le dé al Agente crear/editar vouchers, `PADRONES_EN_OTRO_MENU = ['vouchers']` lo sigue tratando como padrón. Se comprobó que los permisos de todas las plantillas y roles quedan idénticos antes y después.

## Migración `2026_10_16_000100_reorganizar_menus`

- Agrega `modulos.nombre_menu` (varchar 80, nulo) si no existe.
- Instalación nueva (tabla `menus` vacía): no toca datos; `MenuSeeder` arma todo igual.
- Instalación existente (QA/Producción): crea o actualiza los 5 menús (nombre, ícono, `orden` = `orden_movil`), acomoda cada módulo (menú, sección, orden, color, nombre corto) y saca `autorizaciones` del menú. Es idempotente (se puede correr dos veces). Lleva su propia copia del acomodo: no depende del seeder.
- `down()`: borra el menú Informes y la columna.

## «Mis pendientes»

Botón junto a la campana (PC: «Mis pendientes» + número; celular: ícono + número). Abre una lista corta con lo que el usuario puede resolver:

| Renglón | Aparece si… | Cuenta (mismo contador que Inicio) | Enlace |
|---|---|---|---|
| Autorizaciones por responder | `autorizaciones.responder` y es responsable o delegado de algún departamento | `Autorizaciones::pendientesPara()` | `autorizaciones.index` |
| Firmas de pases de salida | `pases_salida.ver` y (`aprobar` o `firmar`) | `AdministradorPasesSalida::idsPorFirmar()` (aprobaciones y pasos de caseta) | `pases-salida.pendientes` |
| Procedimientos por leer | `procedimientos.ver` y el usuario está vinculado a un colaborador | `AdministradorProcedimientos::pendientesDe()` | `procedimientos.por-leer` |
| Altas por verificar | puede verificar algún padrón (`<padrón>.editar`) | `AltasPorVerificar::pendientesPorPadron()` | el padrón (si es uno) o Inicio (desglose por padrón) |

- Si ningún renglón aplica, el botón no existe. Si aplica alguno pero el total es 0, el botón está oculto (`hidden`) y aparece solo cuando llega algo.
- Servicio `App\Support\Menu\MisPendientes` (`#[Scoped]`: una sola vez por petición aunque se pinte en PC y celular), siempre dentro de la empresa de trabajo (`Tenant::conEmpresa`); superadministrador sin empresa: nada.
- Se refresca con la **misma consulta de la campana**: `GET /notificaciones/resumen` agrega `pendientes: {total, items[{clave, titulo, icono, total, url}]}`. En esa consulta periódica el cálculo se reutiliza hasta 25 s (`Cache`, llave `mis-pendientes:{usuario}:{empresa}`); la pantalla siempre lo calcula fresco.
- JS: bloque «Menús y Mis pendientes» al final de `public/js/plataforma.js`: abre/cierra el panel (Escape y clic fuera) y lee una copia de la respuesta del resumen de la campana (envuelve `fetch` solo para esa URL; no hay un segundo reloj). Sin `innerHTML`.
- CSS: bloque «Menús y Mis pendientes» al final de `plataforma.css` y `modos-pantalla.css` (Sol y Noche). Botones de 44 px.

## Archivos

`database/migrations/2026_10_16_000100_reorganizar_menus.php`, `database/seeders/MenuSeeder.php`, `database/seeders/RolesPlantillaSeeder.php` (bloque «Menús»), `app/Support/Menu/ConstructorMenu.php`, `app/Support/Menu/MisPendientes.php`, `resources/views/componentes/mis-pendientes.blade.php`, `resources/views/layouts/app.blade.php`, `app/Http/Controllers/RecursosHumanos/NotificacionController.php` (`resumen`), `app/Providers/AppServiceProvider.php` (orden de atajos).

## Pruebas

`tests/Feature/Acceso/MenusReorganizadosTest.php`: acomodo del Administrador y del superadministrador, nombres cortos, Agente (Operación y Padrones, barra inferior Accesos → Novedades, vouchers solo consulta), Recursos Humanos (sin Autorizaciones en el menú pero con su pantalla), Jefe de seguridad, Informes y módulos sin ruta ocultos (y visibles al tener ruta), migración sobre una instalación con el acomodo anterior (dos veces, y el seeder después), «Mis pendientes» (conteo, visible/oculto/inexistente, JSON del resumen, aislamiento entre empresas, datos demo y número acotado de consultas).
