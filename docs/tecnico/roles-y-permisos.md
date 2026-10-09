# Roles y Permisos

Réplica de `modules/roles/rol_lista.php` y `modules/permisos/permisos_lista.php` de SEGCAT sobre el motor de permisos (ver `nucleo.md` y ADR-0002).

## Áreas del catálogo

Los módulos se agrupan en tres áreas. Así aparecen en la Matriz de permisos y así se contratan:

| Área | Módulos | Por qué |
|---|---|---|
| **Dirección** | Empresas, Sedes, Zonas y áreas, Departamentos, Puestos, Turnos, Usuarios, Roles, Matriz de permisos, Configuración, Auditoría (+ Identidad, solo plataforma) | La estructura de la empresa y su gobierno. Es la base que toda empresa tiene, contrate lo que contrate |
| **Recursos Humanos** | Colaboradores | El personal. Con el tiempo: vacaciones, incidencias, expedientes |
| **Seguridad** | Padrones, Operación y sus reportes (Tablero, Informe ejecutivo, Tendencias, Bitácora del día) | La operación de seguridad |

- Departamentos, Puestos y Turnos se quedan en **Dirección** y no en Recursos Humanos: Seguridad los necesita aunque la empresa no contrate RH.
- El **menú** conserva las secciones de SEGCAT (Estructura, Padrones, Operación) y suma **Recursos Humanos**.
- La migración `2026_10_06_000400` reacomoda las áreas de las bases existentes: Organización + Administración pasan a Dirección, y Reportes pasa a Seguridad.

## Pantallas

| Ruta | Permiso | Qué hace |
|---|---|---|
| `GET /roles` | `roles.ver` | Fichas de roles: nivel, descripción, usuarios, quién creó o editó |
| `POST /roles` | `roles.crear` | Nuevo rol (diálogo) |
| `PUT /roles/{rol}` | `roles.editar` | Nombre, descripción, nivel, activo |
| `DELETE /roles/{rol}` | `roles.eliminar` | Solo si no tiene usuarios |
| `GET /permisos?rol=` | `permisos.ver` | Matriz de permisos de un rol |
| `PUT /permisos/{rol}` | `permisos.editar` | Guarda la matriz completa del rol |
| `POST /empresa-activa` | Super Administrador | Elige la empresa de trabajo (o las plantillas) |

En SEGCAT ambas pantallas usaban los permisos del módulo `permisos`. Ahora **Roles** tiene los suyos (`roles.*`), así se puede dejar a alguien ajustar permisos sin dejarlo crear o borrar roles.

## Reglas

- Nombre y nivel jerárquico son únicos por empresa. Menor número = más privilegios.
- Nadie crea, edita, sube de nivel ni elimina un rol de **nivel igual o superior al suyo**. Tampoco se toca su propio rol.
- En la matriz solo se valida **lo que cambia**. Agregar, quitar o ampliar un permiso exige tenerlo con un alcance igual o mayor. Lo que el rol ya tenía, otorgado por alguien de más nivel, se conserva al guardar.
- Marcar cualquier acción agrega "Ver" (en el navegador y en el servidor). Quitar "Ver" quita las demás acciones del módulo.
- El **alcance** se elige por módulo y aplica a todas sus acciones: *Solo los propios*, *Su sede* o *Toda la empresa*.
- La matriz muestra solo los módulos **contratados** por la empresa. Los permisos de módulos no visibles se conservan al guardar.
- Módulos y acciones salen del catálogo de la base de datos: lo que llegue del navegador y no exista en el catálogo se ignora.
- Desactivar un rol retira de inmediato sus permisos a quienes lo tienen; el motor ignora roles inactivos.
- Roles y Matriz son de **toda la empresa** (un rol lo usan todas las sedes): crearlos, editarlos, borrarlos o cambiar sus permisos exige `roles.*` / `permisos.editar` con **alcance de empresa**. Con "Solo los propios", solo los roles que él dio de alta. Con alcance de sede se consultan (auditoría AZ-02).
- "Solo los propios" **nunca** cuenta como toda la empresa, aunque la asignación no tenga sede: use `Autorizador::alcanceDeEmpresa()` (no `sedesPermitidas() === null`) para decidir si alguien puede cambiar algo de toda la empresa, y `Autorizador::soloPropios()` para acotar a lo que dio de alta (auditoría AZ-04).
- Quien da de alta o edita usuarios con alcance de sede solo asigna sus sedes (nunca "Todas las sedes") (auditoría AZ-01).
- Todo cambio queda en `auditoria` con los eventos `roles.creado`, `roles.actualizado`, `roles.eliminado` y `permisos.rol_actualizado`, incluyendo el antes y el después.

## Super Administrador

Un selector "Empresa de trabajo" (`EmpresaDeTrabajo`, sesión `empresa_activa_id`) define sobre qué empresa opera. Sin elegir, trabaja sobre las **plantillas de la plataforma**: los roles que se copian a cada empresa nueva.

## Plantillas de rol (`RolesPlantillaSeeder`)

| Plantilla | Nivel | Permisos por omisión |
|---|---|---|
| Administrador | 10 | Todo, toda la empresa (incluye `usuarios.desbloquear`) |
| Director | 20 | Ver, aprobar, exportar e imprimir, toda la empresa |
| Jefe de seguridad | 30 | Seguridad y Reportes completos, más `usuarios.ver` y `usuarios.desbloquear`; su sede |
| Asistente | 40 | Seguridad y Reportes (Operación **y** Padrones): ver, crear, editar, imprimir y exportar; sin eliminar, aprobar ni firmar. Su sede |
| Jefe de departamento | 45 | `pases_salida` ver/aprobar/imprimir (sede) y crear (propios); `autorizaciones` ver/responder (sede); `vacantes` ver (sede) y crear (propios); `procedimientos.ver` (sede) |
| Supervisor | 50 | Seguridad y Reportes sin eliminar; su sede |
| Agente | 60 | **Operación**: ver, crear, editar, imprimir y firmar. **Padrones**: solo ver. Su sede |
| Solicitante | 70 | `pases_salida` ver y crear (propios); `procedimientos.ver` (sede) |

- Padrones u Operación se decide por el **menú** del módulo (`modulos.menu_id`, o el de su padre en submódulos), no por nombres: `RolesPlantillaSeeder::esPadron()`. Por eso `MenuSeeder` corre antes que `RolesPlantillaSeeder` en `DatabaseSeeder`.
- Las plantillas solo se crean si no existen (no se pisan los cambios). Para bases existentes, la migración `2026_10_05_000200_agente_solo_consulta_padrones` quita crear/editar/imprimir/firmar de los Padrones a los roles "Agente" (plantilla y copias por empresa) **solo si siguen exactamente igual a la plantilla anterior** (ver/crear/editar/imprimir/firmar de todos los módulos de Seguridad, alcance sede, nada más). Un Agente personalizado no se toca; se ajusta a mano en la Matriz de permisos. No tiene reversa.

### Recursos Humanos (nivel 25)

- **Puede:**
  - todo en su área (Colaboradores, datos personales y validar altas provisionales), en toda la empresa;
  - consultar Sedes, Departamentos, Puestos y Turnos.
- **No tiene** permisos de Seguridad.

La caseta (Jefe de seguridad, Asistente, Supervisor y Agente) recibe `colaboradores.ver` y `colaboradores.provisional` de su sede: consulta al personal de su sede y da altas provisionales.

### Asistente (QA U-02)

SEGCAT tiene "Asistente de Seguridad - Apoyo de Gestión Local" en el nivel 40, entre el Jefe (30) y el Supervisor (50). La plantilla `Asistente` usa `RolesPlantillaSeeder::ACCIONES_ASISTENTE`.

- **Decisión:** a diferencia del Agente, el Asistente **sí captura y corrige en Padrones** (llaves, gafetes, vehículos…), porque su función en SEGCAT es mantener los catálogos de su sede. No elimina, no aprueba ni firma (eso queda en Jefe y Supervisor) y no administra usuarios. Las acciones que un módulo no tenga (p. ej. `exportar` en Gafetes) simplemente no se otorgan.
- `RolesPlantillaSeeder::definiciones()` concentra las reglas de todas las plantillas y `asegurarPlantilla($nombre)` crea una sola si falta (devuelve `null` si el catálogo está vacío o su nivel ya lo ocupa otra plantilla).
- `ProvisionarEmpresa::copiarPlantilla()` copia una plantilla con sus permisos a una empresa (lo usa el alta de empresas) y `copiarPlantillaSiFalta()` lo hace solo si la empresa no tiene ya un rol con ese nombre **ni otro rol en ese nivel**.
- Migración `2026_10_06_000100_agregar_rol_asistente` (idempotente, sin reversa): crea la plantilla si falta y agrega el Asistente a cada empresa existente que no lo tenga. Si una empresa ya usa el nivel 40 para un rol propio, no se crea (el administrador puede darlo de alta en otro nivel). En una instalación nueva no hace nada: lo crean el seeder y el alta de empresas.

### Solicitante y Jefe de departamento

Personal fuera de la caseta que solo levanta solicitudes (Solicitante) y su jefe, que las aprueba (Jefe de departamento). Reglas en `RolesPlantillaSeeder::SOLICITUDES` (`reglaSolicitudes()`), más sus renglones en `PROCEDIMIENTOS`.

- **Propios solo donde el servicio filtra por `creado_por`.** Revisado contra `app/Services`: `AdministradorPasesSalida::limitar()` y `CircuitoPasesSalida::tienePermisoEnSede()` sí; `AdministradorProcedimientos::limitar()` también, pero con «propios» solo vería los procedimientos que escribió, así que `procedimientos.ver` va con alcance de sede (lo publicado de su sede; el acuse sigue `pendientesDe()`). `AdministradorVacantes::limitar()` no filtra por propios: el Jefe ve por sede y quien crea sin `vacantes.editar` ve además sus borradores (`creado_por`). `Autorizaciones` no usa alcance propios (decide por `DepartamentoResponsable`), por eso no se da al Solicitante. No hay módulo de visitas pre-registradas.
- **Solo a su nombre:** quien no tiene `colaboradores.ver` ni `colaboradores.provisional` solo registra pases cuyo solicitante es el colaborador vinculado a su usuario (`AdministradorPasesSalida::soloASuNombre()`); el formulario lo trae ya elegido (`PaseSalidaController::solicitanteFijo()`).
- **Quién firma:** el paso «Jefe de Departamento» del circuito (departamento = `solicitante`) lo firma quien tiene `pases_salida.aprobar` en la sede de origen y cuyo colaborador vinculado es del departamento del solicitante (`CircuitoPasesSalida::cumpleRegla()`).
- **Vacantes:** sin `vacantes.editar` solo existe «Guardar borrador»; Recursos Humanos edita y publica.
- Migración `2026_10_18_000100_agregar_roles_solicitante_y_jefe_de_departamento` (idempotente, sin reversa): crea las plantillas y las copia a cada empresa que no tenga ese nombre ni ese nivel.
- Datos demo: `solicitante.demo` y `jefedepto.demo` (Recepción, Centro), vinculados a los colaboradores 1014 y 1015; la jefa es responsable de Recepción en Centro.

### Nivel mínimo explicado (QA R-02)

El alta de rol muestra, debajo del nivel, "Tu nivel es N. Solo puedes crear roles de nivel N+1 en adelante (número mayor = menos autoridad: 20 Director, 30 Jefe de seguridad, 40 Asistente…)", armado con el nivel real de quien captura y los roles existentes de la empresa. El aviso del navegador usa `data-mensaje-min` (ver `acceso-y-diseno.md`) y el servidor responde "Tu nivel es N: solo puedes crear o administrar roles de nivel N+1 en adelante…".

Debajo del título se indica la empresa: "Roles de «Hotel Demo»" (o el texto de plantillas si el Super Administrador no eligió empresa).

## Componentes

- `App\Http\Controllers\Administracion\RolController`, `PermisoController`, `EmpresaActivaController`
- `App\Services\Permisos\AdministradorRoles`: `crearRol`, `actualizarRol`, `eliminarRol`, `sincronizarPermisos`
- `App\Support\Tenancy\EmpresaDeTrabajo`
- Vistas en `resources/views/administracion/`; estilos en la sección "Componentes de pantallas de administración" de `public/css/plataforma.css`; comportamiento en `public/js/plataforma.js`. Los diálogos usan `data-abrir-dialogo`, `data-cerrar-dialogo` y `data-confirmar`, sin `onclick`.
- `CatalogoSeeder::RUTAS` enlaza cada módulo migrado con su pantalla. Los demás siguen mostrando el aviso "en migración".

## Pruebas

`tests/Feature/Administracion/RolesYPermisosTest.php`, `AyudasDeCapturaTest.php`, `tests/Feature/Nucleo/PlantillaAsistenteTest.php` y `tests/Feature/Nucleo/PlantillaSolicitudesTest.php`
