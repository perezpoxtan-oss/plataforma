# Bitácora de accesos ("Control de Accesos")

Réplica de `modules/accesos` de SEGCAT (inventario §4.22). Es la pantalla más usada de la caseta: quién está dentro, quién espera autorización y el historial, con el "Registro Inteligente de Ingreso" que cambia según el tipo de persona.

## Piezas

| Pieza | Archivo |
|---|---|
| Migración | `database/migrations/2026_10_10_000100_crear_bitacora_de_accesos.php` |
| Modelos | `app/Models/Acceso.php`, `app/Models/AcompananteAcceso.php` |
| Registro de ingreso | `app/Services/Accesos/RegistroAccesos.php` |
| Cambios de estado | `app/Services/Accesos/MovimientosAccesos.php` (+ `MovimientoNoPermitido`) |
| Consultas, alcance, búsquedas | `app/Services/Accesos/ConsultaAccesos.php` |
| Ocupación de estacionamientos | `app/Services/Accesos/OcupacionPorAccesos.php`, registrada en `app/Providers/AccesosServiceProvider.php` |
| Controlador (delgado) | `app/Http/Controllers/Seguridad/AccesoController.php` |
| Vistas | `resources/views/seguridad/accesos/` (`index`, `_ficha`, `_ingreso`, `_acompanante`, `_dialogos`) |
| JavaScript / CSS | bloque "Bitácora de accesos" al final de `public/js/plataforma.js`, `public/css/plataforma.css` y `public/css/modos-pantalla.css` |
| Espera y escalamiento | `app/Services/Autorizaciones/Escalamiento.php`, `app/Console/Commands/EscalarAutorizaciones.php` |
| Pruebas | `tests/Feature/Seguridad/AccesosTest.php`, `tests/Feature/Seguridad/EscalamientoAutorizacionesTest.php` |

## Tablas

### `accesos` (SEGCAT: `bitacora_accesos`)

| Columna | Qué guarda |
|---|---|
| `empresa_id`, `sede_id` | Empresa y sede (filtro de empresa automático; alcance por sede) |
| `tipo` | `colaborador`, `huesped`, `visitante` (Personal Externo), `proveedor`, `contratista`, `emergencia` |
| `movimiento` | `entrada` (ingreso), `salida_temporal` (tour / salida por material) o `regreso` |
| `acceso_origen_id` | En salidas temporales y regresos: el ingreso al que pertenecen |
| `estado` | `pendiente` → `en_sitio` → `finalizado` |
| `nombre` | Titular en mayúsculas (o "UNIDAD DE EMERGENCIA") |
| `colaborador_id`, `persona_id`, `proveedor_id` | Ligas a Colaboradores, Padrón de personas y Proveedores (empresa o agencia) |
| `empresa_procedencia` | Empresa / agencia como se capturó |
| `motivo_visita`, `visita_colaborador_id`, `persona_visita` | Personal externo: RH o visita a colaborador (ligado) |
| `host_colaborador_id` | Proveedor/contratista: colaborador que lo citó (obligatorio) |
| `identificacion` | ID custodiada: `ine`, `licencia`, `pasaporte` |
| `gafete_id`, `gafete_texto` | Gafete prestado y su nomenclatura en ese momento ("S/G" si no lleva) |
| `modo_arribo`, `vehiculo_id`, `placas`, `zona_estacionamiento_id`, `conductor` | Llegada, vehículo (padrón), zona y conductor del huésped (taxi/app) |
| `num_acompanantes` | Cuántos llegaron con él (aunque no se capturen sus datos) |
| `tiene_reserva`, `numero_reserva`, `tipo_pase`, `habitacion` | Huésped |
| `tipo_visita`, `departamento_id`, `area_trabajo`, `actividad` | Proveedor / contratista |
| `tipo_emergencia`, `observaciones` | Emergencia |
| `entrada_at`, `autorizado_at`/`autorizado_por`, `salida_at`/`salida_por` | Tiempos (UTC) y quién hizo cada paso |
| `creado_por`, `actualizado_por`, `created_at`, `updated_at` | Auditoría |

Índices: `(empresa_id, sede_id, estado)`, `(empresa_id, estado, salida_at)`, `(gafete_id, estado)`, `(zona_estacionamiento_id, estado)`.

### `acompanantes_acceso` (SEGCAT: `bitacora_acompaniantes`)

`empresa_id`, `acceso_id` (cascada), `nombre`, `identificacion`, `gafete_id` + `gafete_texto`, `salida_at`/`salida_por`, `salida_temporal_at`/`_por`, `regreso_temporal_at`/`_por`, auditoría.

## Estados y movimientos

```
proveedor / contratista:  PENDIENTE ──autorizar (accesos.aprobar)──▶ EN SITIO ──salida──▶ FINALIZADO
demás tipos:              EN SITIO ──salida──▶ FINALIZADO
huésped / proveedor / contratista en sitio:
    salida temporal ─▶ fila "salida_temporal" EN SITIO ─regreso─▶ esa fila FINALIZADA + fila "regreso" FINALIZADA
acompañante:  en sitio ─salida─▶ salió   ·   (proveedor/contratista) en sitio ─temporal─▶ fuera ─regreso─▶ en sitio
```

Transiciones prohibidas (responden con el aviso en rojo, sin tocar nada): autorizar algo que no está pendiente; dar salida a un pendiente o dos veces; salida temporal de un personal externo, colaborador o emergencia, de alguien pendiente o ya fuera; regreso sin salida temporal abierta; cambiar la zona de un acceso sin vehículo o finalizado; salida definitiva de un acompañante que está fuera temporalmente; cualquier movimiento de acompañantes de un titular que ya no está en sitio. Cada cambio es una actualización condicionada al estado esperado (el doble clic no "reabre y cierra" el registro).

Al dar salida al titular: sus acompañantes que seguían dentro salen con él y, si estaba fuera en tour, el tour se cierra. "Gente en Sitio" cuenta ingresos (`movimiento = entrada`): quien está en tour aparece en su tarjeta como **FUERA** («Fuera en tour» / «Fuera temporal»), no como otra persona.

## Experiencia de uso aprobada (criterios 1, 3, 4 y 5 de `docs/criterios-ux.md`)

### Vocabulario corto de estados en la tarjeta (cambio visual aprobado)

`_ficha.blade.php` calcula `[$pastilla, $clasePastilla, $iconoPastilla, $detalle]` con un solo `match`: cuatro pastillas (`PENDIENTE` ámbar · `EN SITIO` rojo · `FUERA` ámbar · `FINALIZADO` gris, clase `.estado-acceso.finalizado`) y el detalle en el renglón chico `.ficha-acceso-detalle` (`data-estado-detalle`) debajo de la cabecera: «Espera a: Nombre (Departamento) · 12 min», «Espera a: Recursos Humanos · 4 min», «RR. HH. pide que espere», «Confirma con el host: …», «Autorizado por …», «RR. HH. dijo que pase», «Fuera en tour», «Fuera temporal», «Regreso de tour», «Salida temporal», «No autorizado por …: no ingresó» (clase `negado`). En el historial el gafete va en el mismo renglón. Los colores y el estilo de la tarjeta no cambian; los textos en mayúsculas de SEGCAT («ESPERANDO AUTORIZACIÓN», «FUERA EN TOUR», «SALIDA A TOUR», «NO AUTORIZADO por …») se reemplazan por estas pastillas y detalles. `rh/recepcion/_ficha-acceso.blade.php` conserva la caja ámbar de espera (`data-acceso-esperando`, sondeo) con el aviso «Ya se avisó … Esta tarjeta cambia sola con la respuesta» y la línea `data-espera-escalado` («Se escaló a: …»).

### Reloj, color y «Espera a» (`App\Services\Autorizaciones\Escalamiento`)

- `esperasPara(Collection $accesos)` devuelve por acceso pendiente `[texto, nombres, minutos, nivel, desde, escalado]` con **una consulta por par departamento-sede** (`Autorizaciones::aQuienAvisar`, que respeta las delegaciones) y una para los nombres de `escalado_a`. `ConsultaAccesos::pendientes()` carga `autorizacionDepartamento.departamento`; el controlador pasa `esperas` y `umbrales` a la vista (`test_la_pantalla_carga_todo_sin_consultas_por_tarjeta` sigue en pie).
- Umbrales por empresa (`AjustesRecepcion::recordarMinutos()` / `escalarMinutos()`, guardados en `empresas.preferencias.recepcion.recordar_minutos` / `escalar_minutos`; por omisión `PanelRecepcion::ESPERA_AMBAR = 10` y `ESPERA_ROJA = 20`). Se editan en **Recepción → Ajustes → «Cuando la autorización tarda»** (`PUT /rh/recepcion/ajustes` con `recordar_minutos` y `escalar_minutos`; 1–240, el segundo mayor que el primero; lo guarda quien tiene `candidatos.configurar` **o** `autorizaciones.configurar` con alcance de empresa; auditado como `candidatos.configurado`). El panel de Recepción usa los mismos umbrales.
- La tarjeta lleva `data-espera-desde` (timestamp), `data-espera-ambar` y `data-espera-roja`: `pintarEsperas()` (JS) avanza el reloj y cambia `espera-normal|ambar|roja` cada 30 s sin pedir nada al servidor. `GET /accesos/autorizaciones-estado` responde además `esperas: {id: {minutos, nivel, escalado}}`; el sondeo (15 s) muestra «Se escaló a: …» sin recargar y recarga solo cuando cambia el estado.

### Escalamiento (nunca dos veces)

- Nivel 1, a los `recordar_minutos`: `Escalamiento::recordar()` reenvía el aviso (campana con botones + correo con botones firmados) a quienes ya fueron avisados (`autorizaciones.avisados`; si está vacío, se recalcula). Marca `recordatorio_en`; auditoría `autorizaciones.recordatorio`.
- Nivel 2, a los `escalar_minutos`: `escalarUna()` avisa a `aQuienEscalar()`: suplentes del departamento en la sede (o sus delegados) **y** Jefe de seguridad de la sede (`jefesDeSeguridad()`: activos con `accesos.aprobar` que alcancen la sede y rol «Jefe de seguridad»; si la empresa no usa ese rol, quienes pueden aprobar en la sede); para las de tipo `recepcion`, el resto de RR. HH. de la empresa (`candidatos.editar`, sin los ya avisados) y el Jefe de seguridad. Quien puede responder (`puedeResponder`) recibe los botones; los demás, el aviso `autorizacion_escalada` con «usa Confirmar Autorización en la bitácora» y correo. La caseta (quien registró) recibe «Se escaló a …». Marca `escalado_en` y `escalado_a` (ids); auditoría `autorizaciones.escalada` (`sin_a_quien: true` si no había nadie). El detalle de la autorización muestra «Recordatorio enviado …» y «Escalado … a …».
- Corre **en proceso**: `plataforma:escalar-autorizaciones [--si-toca]` (`App\Console\Commands\EscalarAutorizaciones`), llamado por `despliegue/desplegar.sh` en cada corrida del cron (cada 5 min) como los demás recordatorios; `--si-toca` solo imprime cuando hizo algo. Además `escalarSiToca(empresaId)` se dispara al abrir la pestaña Pendientes y en cada sondeo de estado (hosting sin cron confiable), con candado `Cache::add` de 60 s por empresa y la consulta `queTardan()` (solo pendientes con `recordatorio_en`/`escalado_en` nulos y `solicitada_en` ya vencida); un error de correo se reporta y no tira la pantalla.
- Migración `2026_10_21_000100_escalamiento_de_autorizaciones` (`recordatorio_en`, `escalado_en`, `escalado_a` JSON en `autorizaciones`; `down()` las quita). Pruebas: `tests/Feature/Seguridad/EscalamientoAutorizacionesTest.php`.

### «Dar salida» a la vista y botón honesto

Ver los apartados «Dar salida a la vista» y «Mensajes» más abajo: el campo de escaneo o búsqueda (`[data-salida-vista]`, `GET /accesos/en-sitio`) está siempre arriba de la bitácora, el botón **Dar salida** lo enfoca y el camino anterior (dentro de Nuevo Ingreso) se conserva. El botón del formulario dice «Registrar y dejar pasar» o «Registrar · queda pendiente de autorización» (`data-texto-registrar`, anticipado con `data-rh-espera-en`, `data-visitas-esperan` y `data-departamentos-con-responsable`; el servidor decide) y el mensaje de éxito (`AccesoController::mensajeDeRegistro`) ofrece el siguiente paso.

## Reglas del ingreso (`RegistroAccesos::registrar`)

- **Sede**: activa y dentro del alcance de `accesos.crear` (si el usuario tiene una sola sede, va fija).
- **Gafete**: solo personal externo, proveedor y contratista (colaborador, huésped y emergencia nunca, aunque llegue forzado). Debe ser de la sede, activo y libre (`Gafete::disponibleParaAsignar()`); el de cada acompañante igual, sin repetirse en el mismo registro. Se revisa otra vez con el gafete bloqueado dentro de la transacción.
- **Colaborador / host / a quién visita**: activo, no unido a otro, de la sede, corporativo (sin sede) o con la sede como adicional. Host obligatorio para proveedor y contratista; "a quién visita" obligatorio con motivo "Visita a Colaborador".
- **Nombre**: obligatorio salvo colaborador (sale del registro) y emergencia ("UNIDAD DE EMERGENCIA").
- **Vehículo**: con "Vehículo" las placas son obligatorias; "A Pie" descarta placas y zona. Las placas se buscan en el Padrón Vehicular; si existen se usa ese vehículo y solo se completan marca/modelo/color vacíos; si no, se registra con propiedad derivada (Colaborador, Emergencia y Personal externo → `propio_visitante`; Huésped → `propio_huesped`, o `taxi_app` si trae conductor; Proveedor/Contratista → `empresa_proveedor` con su empresa).
- **Zona**: activa y de la sede; solo con vehículo.
- **Huésped**: con reserva el pase siempre es Estancia; el conductor solo se guarda si llegó en vehículo; la agencia cae a Proveedores como `agencia_viajes`.
- **Empresa externa**: proveedor/contratista se busca por nombre (sin mayúsculas ni espacios dobles) antes de crearla (categoría `proveedor` o `contratista`, solo en la sede del acceso). Una empresa **dada de baja (vetada)** no puede ingresar.
- **Padrón de personas** (personal externo, proveedor, contratista): elegida de la lista → se usa. Escrita a mano y ya existe una persona activa del mismo tipo con ese nombre → error `persona_repetida` y el diálogo pregunta **"¿Es la misma persona? Sí, es la misma / No, es alguien distinto"** (`persona_decision=misma|distinta`). Nueva → se crea (nombre en formato Título) con su empresa.
- **Acompañantes**: hasta 15; filas sin ningún dato se omiten; `num_acompanantes` = el mayor entre el número capturado y las filas.
- **Guardar y capturar siguiente** (`siguiente=1`): regresa con la sesión `siguiente` (sede, tipo y aviso) y el diálogo se reabre listo.

## Gafete "EN SITIO" (cambio en `App\Models\Gafete`)

`disponibleParaAsignar()` = activo **y** no `prestados()`. El scope `prestados()` es un gafete con un acceso `pendiente` o `en_sitio`, o con un acompañante sin salida (aunque esté fuera temporalmente) de un acceso abierto. `disponibles()` = activos y no prestados. Corrige dos fallas de SEGCAT: el gafete de un acompañante que ya había salido seguía "ocupado" y el de un proveedor pendiente se podía prestar a otro.

## Ocupación de estacionamientos

`OcupacionPorAccesos` cuenta accesos `en_sitio` por `zona_estacionamiento_id` en una sola consulta y se registra con `AccesosServiceProvider` (reemplaza a `SinOcupacion`). La pantalla de Estacionamientos ya muestra la ocupación real y LLENO; el formulario de accesos ofrece solo zonas activas de la sede con "(ocupados/cupo)" y "— LLENO" (se puede asignar igual, como en SEGCAT).

## Endpoints

| Método y ruta | Permiso | Qué hace |
|---|---|---|
| `GET /accesos?pestana=en_sitio\|pendientes\|historial&q=&tipo=&sede=&desde=&hasta=&page=` | `accesos.ver` | Pantalla (historial paginado de 24) |
| `POST /accesos` | `accesos.crear` | Registro Inteligente de Ingreso |
| `PATCH /accesos/{id}/autorizar` | `accesos.aprobar` | Pendiente → En sitio |
| `PATCH /accesos/{id}/salida` | `accesos.editar` | Salida |
| `PATCH /accesos/{id}/zona` | `accesos.editar` | Cambiar / liberar zona |
| `POST /accesos/{id}/salida-temporal` | `accesos.editar` | Salida a tour / temporal (placas, marca, conductor) |
| `PATCH /accesos/{id}/regreso` | `accesos.editar` | Regreso (vehículo y conductor del regreso) |
| `PATCH /accesos/acompanantes/{id}/salida` · `/salida-temporal` · `/regreso` | `accesos.editar` | Movimientos de un acompañante |
| `GET /accesos/en-sitio?q=` o `?gafete={id}` | `accesos.ver` | JSON para «Dar salida» (campo a la vista y diálogo de Nuevo Ingreso: por nombre, placas, gafete o habitación; o el gafete leído con el lector, QR con dirección o etiqueta NFC/RFID) |
| `GET /accesos/autorizaciones-estado?ids=` | `accesos.ver` | Estado en vivo de las tarjetas pendientes (`estados`) y reloj/escalamiento (`esperas`); dispara `escalarSiToca` |
| `GET /accesos/buscar?que=colaborador\|persona\|vehiculo\|proveedor&q=&sede=&tipo=` | `accesos.crear` | Sugerencias del formulario (reutiliza las búsquedas de cada padrón; no exige administrar el padrón) |
| `GET /accesos/gafetes?sede=` | `accesos.crear` | Gafetes libres de la sede |
| `GET /accesos/exportar?...` | `accesos.exportar` | CSV del historial filtrado (BOM para Excel, máx. 10 000 filas) |

Otra empresa u otra sede fuera del alcance → **404**. Superadministrador: elige la empresa de trabajo.

## Permisos por rol (plantillas)

| Rol | Ver | Registrar | Salidas, zona, tours | Autorizar proveedores | Exportar |
|---|---|---|---|---|---|
| Administrador | toda la empresa | ✔ | ✔ | ✔ | ✔ |
| Jefe de seguridad / Supervisor | su sede | ✔ | ✔ | ✔ | ✔ |
| Asistente de seguridad | su sede | ✔ | ✔ | — | ✔ |
| Agente | su sede | ✔ | ✔ | — | — |
| Director | toda la empresa | — | — | ✔ | ✔ |

## Lector universal

Donde SEGCAT tenía "escáner / QR / NFC" se usa `componentes.lector`: gafete asignado (`tipos=gafete`), colaborador que ingresa, host y a quién visita (`tipos=colaborador`), placas (`tipos=vehiculo`, también en Salida a Tour y Regreso) y el gafete que devuelven en "Dar Salida". Además, al escribir aparecen sugerencias (gafetes libres de la sede filtrados por "Tipo de Gafete", colaboradores de la sede, vehículos del padrón); elegir una llama `Lector.elegir()`. Un gafete escaneado que no está libre se rechaza en pantalla. Si el colaborador no aparece, **"¿No aparece? Darlo de alta provisional"** abre el alta provisional de Colaboradores (ADR-0004) y lo deja elegido.

## Auditoría

`accesos.creado`, `accesos.autorizado`, `accesos.salida`, `accesos.zona_cambiada`, `accesos.salida_temporal`, `accesos.regreso`, `accesos.salida_acompanante`, `accesos.salida_temporal_acompanante`, `accesos.regreso_acompanante`; del escalamiento, `autorizaciones.recordatorio` y `autorizaciones.escalada` (sin usuario: los hace la plataforma); y en los padrones `visitantes.creado`, `vehiculos.creado`/`actualizado`, `proveedores.creado` cuando el ingreso los crea. `AdministradorColaboradores::REFERENCIAS` incluye `accesos.colaborador_id` (unir duplicados mueve los ingresos).

## Qué se corrigió respecto a SEGCAT

- Las fichas "Gente en Sitio" eran los **últimos 300** registros: ahora en sitio y pendientes se consultan completos y el historial se pagina con filtros (texto, tipo, sede, fechas) y exportación.
- Un gafete ocupado, una zona de otra sede o de baja, o un gafete de acompañante repetido **se descartaban en silencio**; ahora se avisa dentro del diálogo.
- El gafete de un **proveedor pendiente** se podía prestar a otro y el de un **acompañante que ya salió** seguía ocupado.
- Una **empresa vetada** (dada de baja) podía entrar como proveedor.
- La huésped en tour aparecía **dos veces** en "Gente en Sitio" (ingreso y tour) y el conteo se inflaba; la salida final dejaba el tour abierto.
- Una salida temporal de acompañante solo podía hacerse una vez; ahora puede salir y volver varias veces (cada paso queda en la auditoría).
- El host y "a quién visita" eran texto; ahora se ligan al colaborador (y se validan contra la sede).
- El vehículo existente nunca se completaba; ahora se llenan marca/modelo/color que faltaban, sin pisar lo capturado.
- Los bloques del formulario repetían los mismos `name` (el servidor tomaba el del bloque equivocado); ahora hay un solo campo por dato y los bloques ocultos no se envían.
- `onclick`, jsQR desde CDN y el `confirm()` con nombres sin escapar desaparecen; se usa el lector universal y `data-confirmar`.
- Fechas en UTC mostradas en la hora local de la sede; doble envío bloqueado; tamaños táctiles de 44 px; modos Sol y Noche.

## Pendientes y notas

- "Pendientes" no tiene "Rechazar": un proveedor que el host no autoriza se queda pendiente (igual que SEGCAT). Propuesta para una versión futura.
- Unir duplicados mueve también `host_colaborador_id` y `visita_colaborador_id` (`AdministradorColaboradores::REFERENCIAS_ADICIONALES`, revisión funcional FUN-02).

## Ronda 8 (QA AC-05): buscar por acompañante

- **Filtro de las pestañas** (`ConsultaAccesos::filtrar`, usado por Gente en Sitio, Pendientes e Historial): cada palabra también se busca en el **nombre o el gafete de los acompañantes** (`orWhereHas('acompanantes')`). En MySQL (`utf8mb4_unicode_ci`) la comparación ya ignora acentos y mayúsculas.
- **Filtro en vivo** (navegador): sin acentos ni mayúsculas («sofia mendez» encuentra a «SOFÍA MÉNDEZ»).
- **Aviso en la tarjeta**: si lo buscado coincide con un acompañante y no con el titular, la tarjeta del registro principal dice «Coincide con **SOFÍA MÉNDEZ**, acompañante de LAURA MÉNDEZ RÍOS» (`ConsultaAccesos::acompananteQueCoincide`, `ConsultaAccesos::normalizar`; el filtro en vivo lo muestra u oculta al teclear).
- **«Dar Salida»** (`GET /accesos/en-sitio`): ya buscaba acompañantes; ahora cada resultado trae `coincide_acompanante` y la tarjeta lo avisa igual.
- No se agregaron salidas temporales nuevas (las de acompañantes de proveedor y contratista ya existían).
- Pruebas: `AjustesRonda8Test::test_ac05_*`.
