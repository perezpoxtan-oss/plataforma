# Equipos de acceso: ZKTeco y Hikvision (decisión 10)

Decisión: [ADR-0025](../decisiones/ADR-0025-equipos-de-acceso.md). Manual: `docs/usuario/equipos-de-acceso.md`. Curso: lección 70.

Los lectores, torniquetes y checadores registran solos la entrada y la salida del **colaborador**. La plataforma vive en un hospedaje compartido (sin SSH, sin procesos escuchando, sin `exec`, sin cron nuevo): **solo recibe llamadas HTTPS del equipo**. Nunca llama a la red del cliente.

## Piezas

| Pieza | Archivo |
|---|---|
| Migración | `database/migrations/2026_11_21_000200_equipos_de_acceso.php` |
| Modelos | `app/Models/EquipoAcceso.php`, `ComandoEquipoAcceso.php`, `MarcajeEquipoAcceso.php` |
| Interfaz común y registro de marcas | `app/Services/EquiposAcceso/Conector.php`, `Conectores.php`, `MarcajeLeido.php` |
| Conectores | `ConectorZkteco.php` (PUSH «iclock»), `ConectorHikvision.php` (eventos ISAPI) |
| Marcajes → entradas y salidas | `app/Services/EquiposAcceso/RegistroMarcajes.php` |
| Altas y bajas (cola) | `app/Services/EquiposAcceso/SincronizacionUsuarios.php` + `app/Providers/EquiposAccesoServiceProvider.php` |
| Pantalla | `app/Services/EquiposAcceso/AdministradorEquipos.php`, `app/Http/Controllers/Administracion/EquiposAccesoController.php`, `resources/views/administracion/equipos-acceso/index.blade.php` |
| Llamadas de los equipos | `routes/equipos.php`, `app/Http/Controllers/Publico/EquiposAcceso/` |
| Sin conexión (reloj) | `app/Services/Relojes/Relojes/EquiposSinConexion.php`, `app/Support/AjustesEquiposAcceso.php` |
| Pruebas | `tests/Feature/EquiposAcceso/` con los fixtures de `tests/fixtures/equipos-acceso/` |

## Tablas

- **`equipos_acceso`**: `empresa_id`, `sede_id`, `nombre`, `marca` (`zkteco` \| `hikvision`), `modelo`, `numero_serie` (único por marca en **toda** la plataforma: ZKTeco se identifica con él), `puerta`, `sentido` (`entrada` \| `salida` \| `ambos`), `token_hash` (sha256; el token se muestra una sola vez), `token_pista`, `ips_permitidas`, `activo`, `ultimo_contacto_at`, `ultima_ip`, `ultimo_marcaje_at`, `version_equipo`, `estampa_marcajes` (el «Stamp» de ZKTeco), autoría.
- **`equipos_acceso_comandos`**: cola por equipo (`tipo` alta \| baja, `pin`, `comando`, `estado` pendiente → enviado → hecho \| error, `resultado` = Return del equipo, `intentos`).
- **`equipos_acceso_marcajes`**: cada marcaje una sola vez (`unique(equipo_acceso_id, clave)`; `clave` = sha256 de la marca y lo que hace único al marcaje), `marcado_at`, `identificador` **enmascarado** («••••5678»), `sentido`, `resultado` (`entrada`, `salida`, `desconocido`, `no_vigente`, `otra_sede`, `sin_entrada`, `ignorado`), `detalle`, `colaborador_id`, `acceso_id`.
- **`accesos.equipo_entrada_id` / `equipo_salida_id`**: el equipo que registró la entrada o la salida. La Bitácora dice «(por Equipo: <nombre>)» y la exportación también.

`down()` quita las columnas de `accesos` y las tres tablas; las entradas y salidas se conservan (`MigracionEquiposAccesoTest`).

## Mismo servicio que la caseta

Los marcajes crean las entradas y salidas por el servicio de la caseta, extraído con el menor cambio posible:

- `RegistroAccesos::entradaDeColaborador(?User, Colaborador, sedeId, [equipo_entrada_id, entrada_at])`: el mismo registro que `registrar()` deja para un colaborador «A pie» (mismas reglas: activo, no unido a otro, de la sede, corporativo o con la sede como adicional; el giro lo ofrece) y la misma auditoría `accesos.creado`.
- `MovimientosAccesos::salida(?User, Acceso, [equipo_salida_id, salida_at])`: la salida de siempre (actualización condicionada, acompañantes, auditoría `accesos.salida`).
- `AdministradorRoles::auditar(?User …)`: sin usuario cuando lo hizo un equipo.

Por eso aparecen en la Bitácora de accesos, en Gente en sitio y en Asistencia sin tocar esos módulos (Asistencia los lee con `App\Services\Asistencia\MarcajesColaborador`, la misma tabla `accesos`). Pruebas: `EntradaSalidaComunTest` y `AsistenciaConEquiposTest` (marcaje ZKTeco → entrada → asistencia, retardo, salida anticipada y horas en «Asistencia del día», el reporte y el Excel).

## Reglas de un marcaje (`RegistroMarcajes`)

1. **Idempotencia:** se guarda primero la fila del marcaje; si ya existía (o llega a la vez por otra petición: índice único), no se hace nada más.
2. **Lo anterior al alta** del equipo en la plataforma (5 min de margen) se descarta sin guardarse: el primer saludo de ZKTeco puede mandar todo su historial.
3. **Quién:** con tarjeta, la etiqueta NFC/RFID del colaborador en cualquiera de sus formas (`Etiqueta::candidatos` y el decimal con 10 dígitos); si es la tarjeta de una **credencial dada de baja**, es él pero no vigente. Con PIN, el **número de empleado** (también sin ceros a la izquierda). Un colaborador unido a otro se sustituye por el correcto.
4. **Vigente:** colaborador activo y, si alguna vez se le emitió credencial, una DISPONIBLE o ASIGNADA sin vencer (la regla de la verificación pública). Si no: `no_vigente`, auditoría `equipos_acceso.marcaje_rechazado` y aviso **«Credencial no vigente usada en <puerta>»** (`equipo_no_vigente`, alerta) a quien registra ingresos o los aprueba en esa sede.
5. **Dónde:** la sede del equipo, con la regla de la caseta. Si no aplica: `otra_sede`.
6. **Sentido:** el del equipo si es «Solo entrada» o «Solo salida». Si es de los dos, lo que dijo el equipo (ZKTeco: estado 1, 2, 5 = salida; 3, 4 = entrada. Hikvision: `attendanceStatus` checkIn / checkOut…). Si no dijo (estado 0 o sin teclas de estado): **se alterna** (en sitio → salida).
7. **Antipassback = solo avisar** (decisión del dueño): una entrada de quien sigue en sitio cierra la anterior **sin inventar su hora de salida** (`estado = finalizado`, `salida_at` vacía, auditoría `accesos.sin_salida`), registra la nueva y avisa «<nombre> no registró su salida» (`equipo_antipassback`, informativo) a quien aprueba ingresos en la sede. Una salida sin entrada se guarda como `sin_entrada` y avisa «no registró su entrada».
8. Una salida con hora anterior a su entrada se guarda con la hora de entrada.

## ZKTeco · protocolo PUSH («ADMS», `/iclock/…`)

| Llamada del equipo | Respuesta |
|---|---|
| `GET /iclock/cdata?SN=…&options=all` | Opciones: `GET OPTION FROM: <SN>`, `ATTLOGStamp=<última estampa o None>`, `OPERLOGStamp=9999`, `ATTPHOTOStamp=None`, `ErrorDelay=60`, `Delay=30`, `TransTimes=00:00;14:05`, `TransInterval=1`, `TransFlag=TransData AttLog`, `Realtime=1`, `Encrypt=None` |
| `POST /iclock/cdata?SN=…&table=ATTLOG&Stamp=…` | Cuerpo: un renglón por marcaje `PIN \t AAAA-MM-DD HH:MM:SS \t estado \t verificación \t código…` (hora local de la sede). Responde `OK: <n>`. Otras tablas (`OPERLOG`, fotos…) se reciben y se descartan (`OK: 0`). Máximo 2 000 renglones por llamada; los mal formados se descartan. |
| `GET /iclock/getrequest?SN=…` | `OK` o hasta 20 renglones `C:<id>:<comando>` |
| `POST /iclock/devicecmd?SN=…` | Cuerpo `ID=<id>&Return=<n>&CMD=…` por renglón; `Return=0` = hecho. Solo cierra comandos de ESE equipo. |

- **Comandos:** alta `DATA UPDATE USERINFO PIN=<número de empleado>\tName=\tPri=0\tPasswd=\tCard=<tarjeta en decimal>\tGrp=1` (**sin el nombre**: privacidad) y baja `DATA DELETE USERINFO PIN=<número>`. Un comando enviado sin respuesta se reenvía a los 5 minutos; al quinto intento queda en `error` (la pantalla lo cuenta).
- **Cuándo se encolan** (`EquiposAccesoServiceProvider`, eventos de los modelos; Credenciales y Colaboradores no cambian): al guardar una credencial (emitir, entregar, reponer, recibir, dar de baja) y al cambiar del colaborador `activo`, `etiqueta_nfc`, `num_empleado`, `sede_id` o `fusionado_en_id`. Debe estar en el equipo quien está activo, con número de empleado, aplica en la sede del equipo y tiene credencial vigente. La baja solo va a equipos que alguna vez lo recibieron; lo aún no entregado del mismo PIN se reemplaza; si lo último ya es igual, no se repite. Un error al encolar se reporta y nunca impide guardar.
- **«Sincronizar»** (`POST /configuracion/equipos-acceso/{equipo}/sincronizar`): encola el alta de todos los que deben estar y la baja de quienes se le mandaron y ya no deben estar (útil tras una carga masiva o al cambiar el equipo). Auditoría `equipos_acceso.sincronizacion`.
- **Autenticación:** el protocolo solo trae el número de serie. Por eso: SN registrado y activo, **IP permitidas** opcionales por equipo (recomendadas) y límite por equipo. Sin el equipo registrado: `401 ERROR` sin decir por qué.

## Hikvision · notificación de eventos ISAPI («Escucha HTTP»)

- `POST /equipos-acceso/hikvision/{token}` o `POST /equipos-acceso/hikvision` con `X-Equipo-Token`. El token (40 caracteres) se genera al dar de alta el equipo y con **Generar otro token** (el anterior deja de servir); solo se guarda su sha256.
- Cuerpo JSON o `multipart/form-data` (el JSON en un campo `event_log` / `AccessControllerEvent` o como parte `application/json`; la foto se descarta sin leerse ni guardarse). Máximo 1 MB; JSON de 64 KB y profundidad 8; hasta 50 eventos.
- Cuenta como paso: `eventType = AccessControllerEvent`, `majorEventType = 5` y `subEventType` en `ConectorHikvision::MENORES_ACEPTADOS` (1, 38, 113: tarjeta o huella según el modelo). **El rostro (75) no se usa** (decisión del dueño): se guarda como `ignorado`. Otros eventos (puerta abierta, botón, alarmas) también. `heartBeat` solo anota el contacto.
- Quién: `employeeNoString` (número de empleado) o `cardNo`. Cuándo: `dateTime` con su zona (sin zona = hora de la sede). Único: `serialNo` + `dateTime`.
- El nombre que manda el equipo **no se guarda**.
- Un cuerpo que no trae ningún evento: `400` y no cuenta como contacto.

### Puente local para Hikvision (fase siguiente, no construida)

La plataforma no puede llamar a la red del cliente, así que **no carga tarjetas en los Hikvision**: la pantalla del equipo lo dice («las altas y bajas de tarjetas se cargan en el equipo o con el puente local, cuando exista»). La fase siguiente es un programa pequeño en una computadora del cliente que: (1) pregunte a la plataforma por la cola del equipo (como `getrequest`, con su token); (2) aplique las altas y bajas con ISAPI (`PUT /ISAPI/AccessControl/UserInfo/SetUp`, `PUT /ISAPI/AccessControl/CardInfo/SetUp`, `PUT /ISAPI/AccessControl/UserInfo/Delete`) en la red local; (3) confirme cada comando. La cola y `SincronizacionUsuarios` ya sirven: bastaría que `ConectorHikvision::sincronizaUsuarios()` devuelva `true` con sus comandos.

## Seguridad

| Requisito | Cómo |
|---|---|
| Sin sesión pero autenticado | Rutas en `routes/equipos.php`, fuera del grupo `web` (sin sesión, cookies ni CSRF; `bootstrap/app.php`, `then:`). ZKTeco: SN registrado y activo + IP permitidas. Hikvision: token por equipo (sha256). |
| Límite con nombre | `AppServiceProvider::limitadoresPublicos`: `equipos.zkteco.cdata` 120/min, `equipos.zkteco.getrequest` 60/min, `equipos.zkteco.devicecmd` 60/min, `equipos.hikvision.eventos` 240/min, **por equipo** (SN o token) y 5 veces eso por IP (varios equipos de un cliente salen por la misma IP). |
| Validación estricta y tamaño | SN `^[A-Za-z0-9_-]{4,40}$`, tabla `^[A-Z_]{2,20}$`, renglones con PIN y fecha válidos (fechas imposibles fuera), token `^[A-Za-z0-9]{32,64}$`, JSON con profundidad máxima; 512 KB (ZKTeco) y 1 MB (Hikvision) → `413`. |
| Idempotencia | `unique(equipo_acceso_id, clave)` y lo ya enviado no se reenvía antes de 5 min. |
| Auditoría | `accesos.creado`, `accesos.salida`, `accesos.sin_salida`, `equipos_acceso.marcaje_rechazado` (sin usuario) y `equipos_acceso.creado/actualizado/baja/reactivado/token/sincronizado`, `configuracion.equipos_acceso`. Los rechazos de SN o token desconocidos van al registro del servidor (sin empresa a la cual auditar). |
| Sin datos personales en respuestas | `ERROR`, `OK`, `OK: <n>`, las opciones y los comandos (PIN y tarjeta, sin nombre). |
| Tenant | El equipo se busca sin filtro y todo lo demás corre en `Tenant::conEmpresa(<empresa del equipo>)`: un equipo jamás escribe en otra empresa (pruebas de tenant en los tres archivos). |
| Dominio | `VerificarHost` sigue activo: el equipo debe apuntar al **dominio** de la plataforma, no a su IP. |

## Pantalla: Configuración → Caseta y accesos → Equipos de acceso

`GET /configuracion/equipos-acceso` (`equipos-acceso.index`). Tarjeta de enlace en Configuración («Configurar en Equipos de acceso») para quien ve Configuración y la empresa tiene Accesos.

| Acción | Ruta | Permiso |
|---|---|---|
| Ver | `GET /configuracion/equipos-acceso` | `configuracion.ver` o `accesos.aprobar` (el jefe de seguridad que recibe el aviso), solo las sedes de su alcance |
| Alta (Guardar) | `POST /configuracion/equipos-acceso` | `configuracion.editar` en esa sede |
| Editar | `PUT /configuracion/equipos-acceso/{equipo}` | ídem (la marca no cambia) |
| Dar de baja / Reactivar | `PATCH …/{equipo}/estado` | ídem |
| Generar otro token | `POST …/{equipo}/token` | ídem (solo Hikvision) |
| Sincronizar | `POST …/{equipo}/sincronizar` | ídem (solo ZKTeco) |
| Horas sin conexión | `PUT /configuracion/equipos-acceso/ajustes` | `configuracion.editar` con alcance de empresa |

Fuera de su alcance o de otra empresa → 404; sin el permiso → 403. Número de serie repetido (aunque sea de otro cliente): «Ese número de serie ya está registrado…» sin decir de quién. Cada tarjeta muestra estado (EN LÍNEA, SIN RESPUESTA —ZKTeco más de 5 min—, SIN CONEXIÓN —más de las horas configuradas—, SIN CONECTAR, DE BAJA), última llamada, último marcaje, altas y bajas por entregar y rechazadas, «Cómo configurarlo» (pasos de su conector, con el token recién generado) y «Últimos marcajes» (10). «Sincronizar» está en la lista de exclusión de `DiezVerbosTest` (alcance aprobado por el dueño).

## Sin conexión (reloj y suplente)

`EquiposSinConexion` (motor «Reloj y suplente», módulo `accesos`, permiso `accesos.aprobar`): equipo activo cuya última llamada (o su alta, si nunca llamó) pasó de **X horas** (Configuración → Equipos de acceso; de fábrica **2 h**, 1 a 72, `empresas.preferencias.equipos_acceso.sin_conexion_horas`). Recordatorio al **jefe de seguridad de la sede** (`Escalamiento::jefesDeSeguridad`) a las X h y suplente a las 2X h. Renglón «Equipos de acceso sin conexión» en Mis pendientes. Corre **en proceso** con el motor (campana y corrida del despliegue): sin cron nuevo. Hikvision solo llama cuando alguien pasa o con su latido: hay que activar el latido o subir las horas.

## Lo que falta probar con un equipo real

Las pruebas simulan los dos protocolos con peticiones de su formato público, pero cada firmware varía. Con un equipo real (casos PI-EQ del plan de QA):

- ZKTeco: que el modelo acepte HTTPS y dominio; nombres de campo del alta (`Pri=` en unos firmwares, `Privilege=` en otros) y que la tarjeta en decimal abra; que `ATTLOGStamp=None` no reenvíe el historial cada vez; el estado (0–5) que manda con y sin teclas de estado; el `Return` de un `DATA DELETE` de un PIN que no existe.
- Hikvision: los `subEventType` que manda el modelo al pasar tarjeta o huella (si no son 1, 38 o 113, se ven como «Evento 5/NN» en «Últimos marcajes» y se agregan a `MENORES_ACEPTADOS`); el nombre del campo multipart; el latido; HTTPS con certificado válido.
- Los dos: la zona horaria del equipo contra la de la sede y la hora con el horario de verano de la sede.

## Fuentes

- ZKTeco, «General Introduction of PUSH SDK Protocol» (funciones del protocolo PUSH: carga de marcajes, comandos del servidor, HTTPS): https://cdn.tvc.mx/media/92189/General-Introduction-of-PUSH-SDK-Protocol.pdf
- Implementación de referencia y formatos de ATTLOG, `C:<id>:<comando>`, `ID=…&Return=…` y `DATA UPDATE/DELETE USERINFO`: https://github.com/s0x90/zkteco-adms
- Respuesta del saludo `GET OPTION FROM` y campos ATTLOG: https://deepwiki.com/saifulcoder/adms-server-ZKTeco/3-api-reference
- Hikvision, documentación ISAPI de control de acceso (open.hikvision.com): https://open.hikvision.com/hardware/v2/08%E5%8D%8F%E8%AE%AE%E9%80%8F%E4%BC%A0/%E6%98%8E%E7%9C%B8%E9%97%A8%E7%A6%81.html
- `httpHosts` (empujar eventos a un servidor), `majorEventType` 5 y subtipos de verificación: https://github.com/uchkunr/hikvision-best-practices
- Cliente ISAPI de referencia: https://github.com/Shaykhnazar/hikvision-isapi
