# «Configura tu empresa» (asistente de arranque)

Petición del dueño: tarjeta en **Inicio** que guía al Administrador de una empresa nueva (o casi vacía) con los pasos para empezar a operar; cada paso se marca solo con los datos reales.

- Servicio: `app/Services/Arranque/AsistenteArranque.php`
- Controlador: `app/Http/Controllers/AsistenteArranqueController.php` (`PUT /configura-tu-empresa`, nombre `asistente.visibilidad`, campo `visible` 0/1)
- Vistas: `resources/views/panel/_configura-tu-empresa.blade.php` (Inicio) y `resources/views/administracion/configuracion/_configura-tu-empresa.blade.php` (Configuración)
- Pruebas: `tests/Feature/Direccion/ConfiguraTuEmpresaTest.php`

## Pasos y cuándo se marcan

| Paso | Hecho cuando | Enlace (si tiene permiso) |
|---|---|---|
| Identidad y logotipo | `empresas.logo_ruta` no vacío | Empresas (`empresas.ver`) |
| Sedes | alguna sede activa | Sedes (`sedes.ver`) |
| Departamentos y responsables | algún departamento activo **y** algún `departamento_responsables` | Departamentos |
| Usuarios y roles | más de un usuario activo en la empresa | Usuarios |
| Importar colaboradores | algún colaborador activo y no provisional | Importar colaboradores (`colaboradores.importar`) o la lista |
| Zonas y edificios | alguna zona/edificio (`espacios.nivel = edificio`) | Importar zonas y la lista |
| Padrones | algún proveedor, persona o vehículo | Importar (o la lista) de Empresas externas, Personas y Vehículos |

Todo se calcula en `Tenant::conEmpresa()` con los modelos de la empresa; no hay casillas manuales ni tablas nuevas.

## Quién la ve y cuándo

- Solo quien tiene `configuracion.editar` (sin nombres de rol en código) y con empresa de trabajo.
- Preferencia en `empresas.preferencias['configura_tu_empresa']` (`visible` | `oculto`), sin columna nueva:
  - **Empresa nueva** (creada en **Empresas** o al activar una suscripción en `Aprovisionamiento`): `AsistenteArranque::marcarNueva()` la deja `visible` hasta que alguien toque **Ya terminé**.
  - **Sin preferencia** (empresas que ya existían, como la empresa de ejemplo): se muestra sola solo si está casi vacía, `MAX_HECHOS_CASI_VACIA` = 2 pasos hechos o menos.
  - **Ya terminé** → `oculto`; **Estructura → Configuración → Mostrar en Inicio** → `visible`. Aplica a toda la empresa.
- Cada cambio se audita como `configuracion.configura_tu_empresa` (antes/después).

## Criterios de experiencia de uso

- Criterio 5: cada paso trae su enlace directo; al ocultarla, el aviso ofrece **Ir a Configuración**.
- Criterio 7: la tarjeta solo enumera; el detalle de cada paso vive en su pantalla.
- Verbos: **Ya terminé** y **Mostrar en Inicio / Ocultar de Inicio** son excepciones aprobadas con la petición del dueño.

## Reversión

Sin migración propia. Si se revierte el código, la clave `configura_tu_empresa` se queda en `empresas.preferencias` sin que nadie la lea.
