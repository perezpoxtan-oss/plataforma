# Eliminar definitivamente (borrado físico controlado)

Permite **borrar de la base de datos** un registro de catálogo o padrón que se capturó por error y que **nadie usa todavía**. Es una acción distinta de la baja: en casi todos los módulos la acción `eliminar` ("Eliminar" en la Matriz) es **dar de baja / desactivar** y no se cambió. La nueva acción es `borrar` ("Eliminar definitivamente").

Aprobado por el dueño del proyecto con estas reglas:

- Solo **catálogos y padrones**. **Nunca** Empresas, Usuarios, ni bitácoras o evidencias: Accesos, Novedades (incluye Lost & Found, Robo, Accidentes, Siniestros, Recorridos PC), Préstamo de llaves, Responsivas, Pases de salida, Transporte, Vouchers, Firmas y Auditoría. Esas solo se anulan o se dan de baja.
- Si **cualquier** registro depende de él, no se borra: se explica qué lo usa y se ofrece la baja.
- Por omisión solo lo tiene la plantilla **Administrador**, con alcance de empresa. El Super Administrador puede (con empresa de trabajo elegida).

## Qué se puede eliminar

`App\Services\Borrado\RegistroBorrado::definiciones()` — la clave es la que va en la URL; el módulo es el del permiso y el del evento de auditoría.

| Clave (URL) | Módulo / permiso | Modelo | Se teclea para confirmar | Alcance | Se borra con él | Cuenta como dependencia (además de las llaves foráneas) |
|---|---|---|---|---|---|---|
| `sedes` | `sedes.borrar` | `Sede` (forceDelete) | código | empresa | `departamento_sede`, `sede_turno` | colaboradores y empresas externas asignadas (`colaborador_sede`, `proveedor_sede`), usuarios con rol en la sede; **la única sede de la empresa** |
| `espacios` | `espacios.borrar` | `Espacio` (Zonas y áreas) | nombre | sede | — | zonas o áreas dentro (`padre_id`), llaves que la abren (`espacio_llave`) |
| `departamentos` | `departamentos.borrar` | `Departamento` | nombre | empresa | `departamento_sede`, `departamento_puesto` | |
| `puestos` | `puestos.borrar` | `Puesto` | nombre | empresa | `departamento_puesto` | |
| `turnos` | `turnos.borrar` | `Turno` | nombre | empresa | `sede_turno` | |
| `colaboradores` | `colaboradores.borrar` | `Colaborador` | núm. de empleado (o nombre) | sede (+ todas sus sedes adicionales) | `colaborador_sede` | usuarios, y las columnas de `AdministradorColaboradores::REFERENCIAS` / `REFERENCIAS_ADICIONALES` |
| `roles` | `roles.borrar` | `Rol` (solo de la empresa, nunca plantillas) | nombre | empresa + reglas de nivel de `AdministradorRoles` | `rol_permisos` | usuarios con el rol (`usuario_roles`) |
| `proveedores` | `proveedores.borrar` | `Proveedor` | nombre | empresa | `proveedor_sede` | |
| `personas` | `visitantes.borrar` | `Persona` | nombre completo | empresa | — | |
| `vehiculos` | `vehiculos.borrar` | `Vehiculo` | placas | empresa | — | |
| `llaves` | `llaves.borrar` | `Llave` | nomenclatura | sede | `horarios_llave` (propia), `espacio_llave`, `grupo_espacio_llave` | vouchers (`origen_tipo = llave`) |
| `gafetes` | `gafetes.borrar` | `Gafete` | nomenclatura | sede | — | vouchers (`origen_tipo = gafete`) |
| `tipos_gafete` | `gafetes.borrar` | `TipoGafete` | nombre | empresa | — | |
| `equipos` | `equipos.borrar` | `Equipo` | número de serie | sede | — | vouchers (`origen_tipo = equipo`) |
| `tipos_equipo` | `equipos.borrar` | `TipoEquipo` | nombre | empresa | — | |
| `equipos_pc` | `equipos.borrar` | `EquipoPc` | ID (número de serie) | sede | — | |
| `estacionamientos` | `estacionamientos.borrar` | `ZonaEstacionamiento` | nombre | sede | — | |
| `rutas` | `rutas.borrar` | `Ruta` | nombre | sede | `ruta_horarios` y sus `ruta_paradas` (propias) | movimientos de transporte de la ruta **o de sus horarios** |
| `paraderos` | `rutas.borrar` | `Paradero` | nombre | sede | — | paradas en rutas (`ruta_paradas`) |

Usuarios no se incluyó a propósito: casi todo usuario ya actuó (creó, editó, firmó…) y su id está en la auditoría; se dan de baja.

## Cómo detecta las dependencias (`App\Services\Borrado\BorradoSeguro`)

1. **Llaves foráneas reales.** Lee `Schema::getForeignKeys()` de **todas** las tablas y arma el mapa "quién apunta a quién". Se guarda en caché un día con una llave que incluye la última migración aplicada (`migrations`), así una migración nueva invalida el mapa.
2. **Cualquier fila que apunte al registro es dependencia**, sin importar si la llave foránea borraría en cascada (`cascadeOnDelete`) o dejaría el campo vacío (`nullOnDelete`). Así nunca se pierde historia en silencio (p. ej. un acceso que perdería su persona, o una sede que se llevaría sus rutas en cascada). Se cuentan filas distintas por tabla aunque apunten por varias columnas (un acceso con colaborador y host).
3. **Auto-referencias**: zonas dentro de una zona (`espacios.padre_id`), colaborador unido a otro (`fusionado_en_id`).
4. **Tablas puente puras** (solo sus llaves foráneas, y quizá `id` y fechas; p. ej. `proveedor_sede`, `colaborador_sede`, `espacio_llave`): se detectan solas y **se borran con el registro**, porque son su configuración. Excepciones: `usuario_roles` siempre cuenta, y cada módulo puede declarar las que sí cuentan (`cuentan`: para una sede, los colaboradores y empresas externas asignados; para una zona, las llaves que la abren).
5. **Tablas hijas propias** (`propios`: horarios de una llave, horarios y paradas de una ruta, permisos de un rol): se borran con él, y antes se revisa **recursivamente** que nadie más las use (un horario de ruta usado en la bitácora de transporte impide borrar la ruta).
6. **Referencias sin llave foránea** declaradas por el módulo (`extras`) y **polimórficas**: vouchers (`vouchers_reposicion.origen_tipo` + `origen_id`, tipos de `VoucherReposicion::ORIGENES`).
7. **Reglas propias** (`verificar`): la única sede de la empresa no se borra; un rol pasa por `AdministradorRoles::exigirPuedeAdministrarRol($actor, $rol, 'roles.borrar')` (nivel, propios, alcance).

La bitácora de **auditoría no cuenta** como dependencia (es polimórfica y todo registro tiene su alta ahí); al contrario, recibe la copia.

Mensaje (nombres de `BorradoSeguro::ETIQUETAS`, en singular o plural): «Tiene 3 préstamos de llave y 1 voucher: no se puede eliminar; puedes darla de baja.»

## Borrado

En una transacción:

1. vuelve a revisar las dependencias (alguien pudo usarlo mientras se confirmaba) → `\DomainException` con el mensaje (409);
2. guarda las filas de tablas puente y propias y las borra (lo más profundo primero);
3. borra el registro (`forceDelete()` si usa `SoftDeletes`, como Sede);
4. si la base rechaza el borrado por una llave foránea no prevista (`QueryException`) no se borra nada y se responde «Otro registro todavía lo usa…»;
5. registra en `auditoria` el evento **`<modulo>.eliminado_definitivo`** con `antes` = **todas las columnas del registro** (`getAttributes()`) más `relaciones` = {tabla: filas} de lo que se borró con él, y `despues` = null. Con eso se puede reconstruir a mano.

Después se olvida la caché de permisos (`Autorizador::olvidar()`), por si fue un rol.

## Endpoints

| Ruta | Permiso | Qué hace |
|---|---|---|
| `GET /borrar/{registro}/{id}` (`borrar.revisar`) | `<modulo>.borrar` | JSON: `nombre`, `tipo` («la sede»), `confirmar` (texto a teclear), `puede_eliminar`, `mensaje`, `baja` (formulario `{url, metodo, campos}` o `{texto}` con la indicación) y `url` |
| `DELETE /borrar/{registro}/{id}` (`borrar.destroy`) | `<modulo>.borrar` | Campo `confirmacion`. JSON: 200 `{ok, mensaje, redirect}`; 422 si no coincide lo tecleado; 409 si algo depende de él. Sin JSON: redirige con el aviso en sesión (`ok` / `error`). Límite 30 por minuto |

Orden de revisión: clave desconocida → 404; permiso `<modulo>.borrar` → 403; sin empresa de trabajo, registro de otra empresa o inexistente → 404; alcance:

- **sede**: el motor (`Autorizador::puede($actor, permiso, $registro)`) revisa empresa, sede y "solo los propios"; fuera de sus sedes → **404** (como en las demás pantallas). Un colaborador con sedes adicionales fuera de su alcance → 403.
- **empresa**: catálogos de toda la empresa (departamentos, puestos, turnos, roles, proveedores, personas, vehículos, tipos, sedes) exigen el permiso con **alcance de empresa** (o "Solo los propios" si él lo dio de alta) → si no, 403 «Es de toda la empresa…».

La confirmación compara sin importar mayúsculas ni espacios de más (`Entrada::texto`).

## Permisos

- Acción nueva `borrar` = «Eliminar definitivamente» (`CatalogoSeeder::ACCIONES`); se liga **solo** a los módulos de `RegistroBorrado::modulos()` (`CatalogoSeeder::guardarModulo`). Aparece en la Matriz de permisos en «Otras acciones».
- `RolesPlantillaSeeder::soloAdministradorBorra()`: ninguna plantilla salvo **Administrador** recibe `borrar` (antes el Jefe de seguridad y el Supervisor habrían recibido todo lo de Seguridad, y Recursos Humanos todo lo suyo).
- Migración `2026_10_10_000390_agregar_accion_eliminar_definitivamente` (bases existentes; idempotente): crea la acción, la liga a esos módulos y la otorga, con alcance de empresa, a los roles «Administrador» (plantilla y copias) **en los módulos donde ya tienen `eliminar`**. Nadie más. `down()` la quita.

## Interfaz

- `resources/views/componentes/borrar.blade.php`: botón discreto rojo «Eliminar definitivamente» al pie del diálogo de edición (fuera del formulario) solo para quien tiene `<modulo>.borrar`. El diálogo de confirmación es uno por página (`@once` + `@push('scripts')`, porque no puede ir dentro de otro `<form>`). Parámetros: `registro`, `id` (opcional), `compacto`, `nombre`.
- En los diálogos de edición compartidos el id lo toma el JS del botón que abrió «Editar» (`data-accion="editar-registro"`, `editar-rol` o `editar-ruta`).
- `componentes/borrar-tipos.blade.php`: en Gafetes y Equipos, lista plegada «Eliminar tipos de … sin usar» con los tipos capturados por error.
- JS: bloque «Eliminar definitivamente» al final de `public/js/plataforma.js` (sin código en línea; CSP). CSS: bloque al final de `plataforma.css` (en el celular es hoja inferior) y de `modos-pantalla.css` (Noche y Sol).
- Pantallas con el botón: Sedes, Zonas y áreas (todos sus diálogos de edición), Departamentos, Puestos, Turnos, Colaboradores, Roles (no en plantillas), Proveedores (lista y ficha), Padrón de personas, Padrón vehicular, Llaves, Gafetes (+ tipos), Equipos (+ tipos), Catálogo de Equipos PC, Estacionamientos, Rutas y Paraderos.

## Agregar un módulo

1. Una entrada en `RegistroBorrado::definiciones()` (y, si es un módulo nuevo del catálogo, la migración que liga `borrar` y la otorga al Administrador).
2. `@include('componentes.borrar', ['registro' => '<clave>', 'id' => $editandoId])` después del `</form>` de su diálogo de edición.
3. Un caso en `tests/Feature/Seguridad/EliminarDefinitivoTest::casos()`.

**Nunca** registre bitácoras ni evidencias.

## Datos demo

`CrearDatosDemo::borradoDemo()` (primera vez) crea registros sin uso para practicar: departamento «Compras (duplicado)», puesto «Puesto de prueba», tipo de gafete «VIP (prueba)» y paradero «PARADERO DE PRUEBA» (Centro). (Una persona de prueba no se agregó: la prueba de datos demo de Personas cuenta exactamente 12.) Los demás datos demo tienen historial y sirven para ver el rechazo (departamento Seguridad, llave HDC-BOD-01, vehículo ABC123A).

## Qué se corrigió respecto a SEGCAT

- En SEGCAT «Eliminar» casi siempre era desactivar: un registro capturado por error (un departamento duplicado, un tipo de gafete mal escrito) se quedaba para siempre en las listas. Ahora se puede borrar si nadie lo usa.
- SEGCAT borraba roles con `DELETE FROM roles` sin dejar copia; ahora queda la copia completa (con sus permisos) en la auditoría.
- Cuando algo dependía del registro, SEGCAT dependía de que la base fallara por llave foránea; aquí se revisa antes y se dice exactamente qué lo usa, y lo que la base borraría «en cascada» o dejaría vacío también cuenta como uso.
- Confirmación tecleando el nombre o identificador en lugar de un `confirm()` del navegador.

## Pruebas

`tests/Feature/Seguridad/EliminarDefinitivoTest.php` (por cada uno de los 19 registros: borra sin dependencias con copia en auditoría y confirmación tecleada; rechaza con una dependencia real; otra empresa 404; sin permiso 403. Además: bitácoras, evidencias, Empresas y Usuarios no registrados; solo el Administrador; migración; alcance de sede; sedes adicionales; tablas puente y propias; varias dependencias; única sede; roles; Super Administrador; sin JavaScript; botón visible solo con permiso). El recorrido de seguridad (`tests/Feature/SeguridadAuditoria`) incluye `/borrar/{registro}/{id}`.
