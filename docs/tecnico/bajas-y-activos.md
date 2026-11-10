# Bajas y ciclo de vida de los activos

Plan de correcciones, **Etapa 2** (C-13 a C-18, C-20 y C-21; decisiones DD-06 a DD-09). Decisión de arquitectura: [ADR-0018](../decisiones/ADR-0018-bajas-y-ciclo-de-vida-de-activos.md). Manual: `docs/usuario/antes-de-dar-de-baja.md` y las páginas de llaves, gafetes, equipos, préstamo de llaves, responsivas, vouchers, accesos, pases de salida, estacionamientos y los tres padrones.

## 1. Estados únicos de llaves, gafetes y equipos (C-14)

`App\Support\EstadoActivo` es la única lista de estados:

| Clave | Texto | Llave | Gafete | Equipo |
|---|---|---|---|---|
| `disponible` | DISPONIBLE | guardado | guardado | guardado |
| `en_uso` | EN USO | calculado (préstamo abierto) | calculado (acceso abierto) | guardado (responsiva por turno) |
| `asignado` | ASIGNADO | calculado (responsable permanente) | — | guardado (responsiva fija) |
| `fuera` | FUERA | — | — | guardado (pase de salida) |
| `en_mantenimiento` | EN MANTENIMIENTO | — | — | guardado |
| `no_regreso` | NO REGRESÓ | guardado | guardado | guardado |
| `baja` | DE BAJA | guardado | guardado | guardado |

- Migración `2026_11_11_000100_estados_unicos_de_activos`: agrega `llaves.estado` y `gafetes.estado` (desde `activo`), convierte `equipos.estado = asignado` de responsivas por turno a `en_uso` y de pases abiertos a `fuera`; CHECK desde `CatalogoChecks`. `down()` regresa a `asignado` y quita las columnas.
- `App\Models\Concerns\TieneEstadoActivo` (Llave, Gafete): mantiene `activo` y `estado` en sincronía al guardar (`activo = false` solo en `baja`), `noRegreso()` y el scope `enCaseta`.
- **Candado:** `CustodiaActivos::exigirBajaPermitida()` rechaza la baja desde el catálogo de algo EN USO, ASIGNADO o FUERA con un mensaje que manda al módulo que lo tiene. La llave con solo responsable permanente sí se da de baja (el voucher la propone como responsable).
- **Revisión de la Etapa 2:** el lector (`AccionesLector`) muestra la llave NO REGRESÓ con **Recibir** (sobre su préstamo «No regresó») en lugar de «Prestar»; Tablero e Informe ejecutivo separan «No regresaron» de «Disponibles» en la gráfica de llaves; «Antes de dar de baja» solo propone y acepta reemplazos de la misma empresa (`users` no tiene el scope de tenant) y `POST …/no-regreso` exige que la llave esté prestada o el equipo a cargo de alguien. Pruebas: `RevisionEtapaDosTest`.
- Quién sí cambia el estado: Préstamo de llaves (préstamo/recepción/«No regresó»), Bitácora de accesos (gafete al dar salida), Responsivas (`asignarPorResponsiva` con modalidad), Pases de salida (`moverEquipos`) y la baja con voucher.

## 2. Receptor general (C-16)

`App\Support\Receptor`: el receptor de un préstamo o una responsiva, y el responsable de un voucher, puede ser `colaborador`, `persona` (Padrón de personas, con su empresa) o `proveedor` (empresa externa).

- Columnas: `prestamos_llaves.receptor_tipo/receptor_id`, `responsivas.receptor_tipo/receptor_id`, `vouchers_reposicion.responsable_tipo/responsable_id` (migración `000200`). `colaborador_id` pasa a anulable y se sigue llenando cuando es colaborador (informes y ficha del colaborador no cambian). `down()` se niega si hay registros sin colaborador (no se puede regresar sin perder datos).
- Pantalla: componente `componentes/receptor.blade.php` (Colaborador / Persona externa / Empresa externa) y `GET /receptores/buscar` (`ReceptorController`, mismo permiso que la pantalla que lo usa). Un externo con el acceso restringido se rechaza.
- `Receptor::moverReferencias()` se llama al unir duplicados (Altas por verificar y unión de colaboradores).
- Vista: `nombreReceptor()` en `PrestamoLlave` y `Responsiva`, `nombreResponsable()` en `VoucherReposicion`. Leen `$this->attributes` para no fallar con selects parciales (modo estricto).

## 3. Gafete e identificación al dar salida (C-15)

- `MovimientosAccesos::salida($actor, $acceso, $opciones)` recibe `gafetes_revisados`, `devolvio[]`, `identificacion_revisada`, `identificacion_devuelta`. Sin opciones (lector, salida desde otra pantalla) se asume devuelto: la salida es igual de rápida.
- Lo no devuelto: `accesos.gafete_no_regreso`, `acompanantes_acceso.gafete_no_regreso`, `accesos.identificacion_devuelta` (migración `000300`); el gafete queda `no_regreso` y se avisa con `CustodiaActivos::avisarNoRegreso()` (tipo de campana `activo_no_regreso`).
- `AdministradorGafetes::recibir()` lo regresa a DISPONIBLE (`PATCH /gafetes/{id}/recibir`).
- La confirmación de **Dar salida** (`plataforma.js`, `casillasDevolucion`) pinta las casillas ya marcadas desde `data-dar-salida` de la tarjeta.

## 4. Reglas de la llave (C-17)

`AdministradorPrestamosLlaves::reglasNoCumplidas()` revisa caducidad, horario (con cruce de medianoche, en la zona horaria de la sede), puesto/departamento objetivo y responsable permanente distinto. Si algo no se cumple, el préstamo **no** se rechaza: pide `excepcion_motivo` (error en ese campo) y lo guarda en `prestamos_llaves.excepcion_motivo` (migración `000400`) con el evento de auditoría `prestamo_llaves.excepcion`. Un receptor que no es colaborador no tiene puesto ni departamento: si la llave los exige, siempre pide motivo.

## 5. Pases de salida y equipos (C-18)

- `exigirEquiposParaPase()`: equipo del padrón solo si está DISPONIBLE, es de la sede de origen, cantidad 1, no repetido y no está en otro pase abierto.
- `moverEquipos()`: salida → FUERA; regreso → DISPONIBLE; cierre con faltantes → NO REGRESÓ (con aviso) y `equiposNoRegresaron` para el mensaje.
- El destino «colaborador» ya no se ofrece (se entrega con Responsivas). Un pase existente con ese destino se puede seguir corrigiendo.

## 6. Antes de dar de baja (C-20)

`App\Services\Bajas\AntesDeDarDeBaja` y `BajasController` (`GET /antes-de-dar-de-baja/{tipo}/{id}`, `POST /antes-de-dar-de-baja/{tipo}/{id}/no-regreso`).

- `TIPOS`: colaboradores, personas, proveedores, vehiculos, llaves, equipos, sedes, estacionamientos, departamentos, puestos, turnos, espacios (modelo, permiso de baja, ruta de baja, lista).
- `revisar()` devuelve renglones con nivel `bloquea` (sede con gente en sitio, zona con autos dentro), `pendiente` (préstamos, responsivas, pases con equipo, único responsable) o `aviso`. Reutiliza las consultas de «qué lo usa».
- `exigirRevisado()` se llama en el `estado()` de cada controlador: sin `revisado=1` y con algo vigente redirige a la revisión; con `revisado=1` vuelve a revisar (bloqueo → error `baja`, falta reemplazo → error `reemplazo_id`).
- `aplicar()` dentro de la misma transacción de la baja: préstamos y responsivas → NO REGRESÓ, equipos de pases a su nombre → NO REGRESÓ, llaves con ese responsable permanente → sin responsable, reemplazo en `departamento_responsables`, `bajar_vehiculos`, `etiqueta_nfc = null`, avisos `activo_no_regreso` y `baja_con_pendientes`. En sedes, las cuentas que solo trabajaban ahí se listan (no se tocan).
- Mis pendientes: `PendientesActivos` agrega «Llaves, gafetes o equipos que no regresaron».

## 7. Restringir acceso (C-13)

- Columnas `restringido_en`, `restringido_hasta`, `restringido_motivo`, `restringido_por` en `personas`, `vehiculos` y `proveedores`; `accesos.aviso_padron` (migración `000500`). Las empresas externas que estaban inactivas (antes «vetadas») pasan a activas y restringidas con el motivo «Estaba dada de baja (vetada) antes de separar «Dar de baja» de «Restringir acceso».»; `down()` las regresa.
- `App\Models\Concerns\RestringibleEnAcceso` (`restringido()`, `textoRestriccion()`, scope `restringidos`); `RestriccionAcceso` y `PATCH /restriccion/{padron}/{id}` (`accion=restringir|quitar`), permiso `{modulo}.eliminar`, auditoría `{modulo}.restringido` / `.restriccion_quitada`.
- Caseta (`RegistroAccesos::revisarPadrones`): restringido → rechazo; nombre parecido (`RevisionPadrones`, sin acentos ni sufijos de razón social) → `parecido_restringido` con «¿Es la misma?» (`restriccion_decision`); dado de baja → `reactivar` («Reactivar y continuar», `reactivar_decision=si`); `aviso_padron` lo ve quien autoriza.

## 8. Estacionamientos (C-21)

- `PATCH /estacionamientos/{zona}/liberar/{acceso}` (`liberar`) reemplaza la salida desde la tarjeta: solo quita la zona (`cambiarZona(null)`); la salida se da en la Bitácora.
- `cambiarZona(..., $aunqueEsteLlena)` y el ingreso avisan `zona_llena`; salida temporal con `libera_lugar`.
- `OcupacionPorAccesos` cuenta también los pendientes de autorización.

## Pruebas

`tests/Feature/Seguridad/EtapaDosActivosTest.php`, `EtapaDosCasetaTest.php`, `EtapaDosPasesTest.php` y `tests/Feature/MigracionEtapa2Test.php` (migrar → revertir → migrar con datos).

## Revisión del PR (A-1 a A-8)

- **A-1:** `AdministradorEquipos::recibirNoRegreso` y `PATCH /equipos/{equipo}/recibir` (`equipos.editar`): un equipo NO REGRESÓ que no está en una responsiva (vino de un pase cerrado) vuelve a DISPONIBLE desde su ficha; auditoría `equipos.recibido` con el folio del pase y el aviso pierde su botón.
- **A-2:** «apartado» = el equipo va en un pase PENDIENTE o APROBADO (`CustodiaActivos::PASES_POR_SALIR`, `paseApartado`, `apartadosEnLista`). Sigue DISPONIBLE; la ficha dice «Apartado para el pase …». Responsivas y la baja con voucher piden `motivo_apartado` (`exigirMotivoSiApartado`; queda en la auditoría). Al registrar la salida, un equipo del pase que ya no está DISPONIBLE detiene la salida (error en `verificados`).
- **A-3:** `responsablePropuesto` de un equipo sin responsiva abierta propone al solicitante del último pase del que no regresó.
- **A-4:** `AdministradorPrestamosLlaves::reactivar` rechaza si la llave ya no está DISPONIBLE (NO REGRESÓ o DE BAJA).
- **A-5:** el expediente (pestaña Datos) muestra «Llaves a su cargo» (EN USO y NO REGRESÓ) y «No regresaron» (equipos de sus responsivas o de pases que pidió, y gafetes de sus accesos), cada uno con su permiso de módulo.
- **A-6:** la ficha de la llave muestra un solo estado (EN USO, NO REGRESÓ, ACTIVA o DE BAJA).
- **A-7:** `down()` de `000200` escribe el responsable externo de cada voucher al final de su «¿Cómo pasó?» (`descripcion`) y lo anota en el registro antes de quitar `responsable_tipo/_id`.
- **A-8:** la migración `2026_11_09_000600` (Etapa 1, ya instalada) queda como en develop. `CatalogoChecks::definiciones()` vuelve a ser la lista de la Etapa 1 y `CatalogoChecks::todas()` agrega los CHECK de la Etapa 2 (que pone `2026_11_11_000100`); la usan `RevisionCoherencia`, `IntegridadBaseDatosTest` y `000200`. No hizo falta una migración nueva: la Etapa 2 ya traía la suya.
- Inventario de datos personales: `accesos.identificacion_devuelta` registrada; responsivas y vouchers aclaran que, si el receptor es una persona externa, sus datos viven en el Padrón de personas.

- **Recibir después del cierre:** `AdministradorPasesSalida::recibidoDespuesDelCierre` suma 1 a `cantidad_regresada` del artículo y anota el evento `recibido_tarde` en `pases_salida_bitacora` («Recibido después del cierre por …, fecha: equipo (serie)»); el Informe ejecutivo muestra «Cerrado con faltantes (faltan N)» o «… · lo que faltaba se recibió después».
- **ARCO de personas externas:** `DatosDePersona` busca, exporta y trata `prestamo_llave`, `responsiva` y `voucher` (receptor/responsable persona del Padrón o empresa externa por nombre); ver `docs/tecnico/privacidad.md`.

Pruebas: `EtapaDosPasesTest` (A-1, A-2, A-3), `EtapaDosActivosTest` (A-4, A-5, A-6), `MigracionEtapa2Test` (A-7, A-8).
