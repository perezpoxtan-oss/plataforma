# Pases de salida

Réplica de `modules/pases_salida` de SEGCAT (§4.27 del inventario funcional), rediseñada (v2) con un **circuito de aprobación profesional** y pantallas en **fichas**. Autoriza y rastrea la salida física de artículos de una sede. Menú **Operación → Caseta y Control → Pases de salida** (`/pases-salida`).

## Piezas

| Pieza | Archivo |
|---|---|
| Migraciones | `2026_10_10_000400_crear_pases_de_salida.php` (base), `2026_10_10_000410_circuito_de_aprobacion_de_pases_de_salida.php` (v2: circuito, bitácora, firma guardada; conserva los pases existentes) y `2026_10_23_000100_pases_salida_firmas_caseta_valor_y_rechazo.php` (ADR-0012: firmas de caseta por pase, valor por artículo, condición de valor por paso, firmas conservadas) |
| Modelos | `PaseSalida` (estados, pasos físicos), `PaseSalidaArticulo`, `PaseSalidaFirma` (inmutable), `PaseSalidaPaso` (+ `pases_salida_paso_usuarios`), `PaseSalidaAprobacion`, `PaseSalidaBitacora` (inmutable), `FirmaUsuario` |
| Reglas | `app/Services/PasesSalida/AdministradorPasesSalida.php` (alta, aprobar, rechazar, omitir, cancelar, reenviar, pasos de caseta, avisos, presentación), `CircuitoPasesSalida.php` (configuración: pasos, condición de valor, firmas en caseta y rechazo; quién firma cada paso) y `CampanaPasesSalida.php` (avisos en la campana y su resolución) |
| Controladores | `Seguridad/PaseSalidaController.php`, `Seguridad/CircuitoPasesSalidaController.php` |
| Vistas | `resources/views/seguridad/pases-salida/`: `index` (fichas y bandeja), `_ficha`, `_pasos` (indicador), `show` (ficha del pase), `_dialogos`, `_firma-propia`, `_formulario` (Nuevo / Corregir), `_articulo`, `imprimir`, `verificar`, `circuito`, `_paso-circuito`, `_config-resumen` (sección en Configuración) |
| Correo | `app/Mail/AvisoPaseSalida.php` + `resources/views/correos/pase-salida.blade.php`; envío con `AvisosCorreo::paseSalida()` |
| Comando | `plataforma:pases-vencidos --si-toca` (`app/Console/Commands/RecordarPasesVencidos.php`), llamado por `despliegue/desplegar.sh` |
| Inicio | Bloque "Pases de salida" en `PanelController` (aviso "N pases de salida esperan tu aprobación") |
| JS / CSS | bloques "Pases de salida" (formulario) y "Pases de salida v2" al final de `public/js/plataforma.js`, `public/css/plataforma.css` y `public/css/modos-pantalla.css` |
| Pruebas | `tests/Feature/Seguridad/PasesSalidaTest.php` y `PasesSalidaMejorasTest.php` (ADR-0012: omisión idéntica, firmas en caseta, hoja, valor, rechazo, campana, «Llegan a mi sede») |
| Demo | `CrearDatosDemo::pasesSalidaDemo()` |

## Tablas

**`pases_salida`** (SEGCAT: `pases_salida`): `empresa_id`, `sede_id` (origen), `folio_numero` / `folio` (`PS-000123`, consecutivo por empresa), `codigo_verificacion` (20 caracteres, único: lo lleva el QR de la hoja), `motivo`, `requiere_regreso`, `colaborador_id` (solicitante), `destino_tipo` (`sede` · `proveedor` · `colaborador`) con `sede_destino_id` / `proveedor_id` / `colaborador_destino_id`, dirección y teléfono, fechas de salida y regreso tentativo, `estado`, `ronda` (sube al reenviar), rechazo (`motivo_rechazo`, `rechazado_en`, `rechazado_por`), cancelación (`cancelado_en`, `cancelado_por`, `motivo_cancelacion`), fechas de cada paso (`aprobado_en`, `salio_en`, `recibido_destino_en`, `salio_regreso_en`, `regreso_en`), `cerrado_con_faltantes`, `recordatorio_vencido_en`, `firmas_caseta` (JSON `{salida|recepcion|salida_regreso|regreso: dos|seguridad|ninguna}`: copia de la configuración al enviar o reenviar; `null` en pases anteriores = dos firmas), autoría.

**`pases_salida_articulos`**: `cantidad`, `equipo`, `marca`, `modelo`, `serie`, `descripcion`, `equipo_id` (padrón de Equipos), `valor_unitario` (decimal 12,2, opcional: el `costo` del equipo del padrón si lo tiene; si no, lo que capturó el solicitante), `verificado_salida_en`, `verificado_con_lector`, `cantidad_regresada`.

**`pases_salida_pasos`** (circuito de la empresa): `orden`, `nombre`, `tipo` (`permiso` · `rol` · `usuarios`), `rol_id`, `departamento` (`cualquiera` · `solicitante` · `especifico`) + `departamento_id`, `obligatorio`, `motivos` (JSON; vacío = todos), `valor_minimo` / `valor_maximo` (decimal 14,2; null = sin condición), `activo`. **`pases_salida_paso_usuarios`**: los usuarios de un paso "usuarios específicos".

**`pases_salida_aprobaciones`** (copia del circuito en cada pase): `ronda`, `orden`, `paso_id`, `nombre`, `tipo`, `rol_id`, `departamento_id` (ya resuelto: el del solicitante o el fijo), `usuarios` (JSON), `obligatorio`, `valor_minimo` / `valor_maximo` (copia de la condición), `estado` (`pendiente` · `aprobado` · `omitido` · `rechazado`), `resuelto_por`, `resuelto_en`, `comentario`, `firma_id`, `conservada_de_ronda` (al retomar: la firma viene de esa ronda; mismo `firma_id`). Única por `(pase, ronda, orden)`.

**`empresas.preferencias['pases_salida']`** (sin tabla nueva, como los plazos de Candidatos): `rechazo` (`retomar`; ausente = `inicio`) y `firmas_caseta` (`{movimiento: {todos: modo, motivos: {motivo: modo}}}`). Solo se guarda lo distinto de la omisión; sin la clave, todo es como siempre.

**`pases_salida_bitacora`** (inmutable): `evento`, `titulo`, `comentario`, `detalle` (JSON: persona, artículos verificados, lo que regresa), `user_id`, `usuario_nombre`, `ip`, fechas.

**`pases_salida_firmas`** (inmutable): `bitacora_id`, `grupo` (`aprobacion`, `salida`, `recepcion`, `salida_regreso`, `regreso`; o el grupo de SEGCAT en pases anteriores), `rol`, `nombre_firma`, `user_id` (null si firmó una persona sin cuenta), `cargo`, `firma_ruta` (disco privado). Ya no es única por rol: hay rondas y regresos parciales.

**`firmas_usuarios`**: la firma guardada de cada usuario (`unique(empresa_id, user_id)`, disco privado).

## Máquina de estados

```
pendiente_aprobacion --aprobar el último paso--> aprobado --salida--> salio
pendiente_aprobacion --rechazar (motivo)--> rechazado --corregir y reenviar--> pendiente_aprobacion (ronda + 1)
pendiente_aprobacion | rechazado --cancelar--> cancelado
salio (destino sede, con regreso)        --recepcion-->       en_destino
en_destino                                --salida_regreso-->  en_transito_regreso
en_transito_regreso | salio (proveedor o colaborador) --regreso--> regresado | regreso_parcial
regreso_parcial                            --regreso-->         regresado | regreso_parcial
salio (venta / traspaso definitivo)        = cerrado ("Salió — Cerrado")
```

- **Aprobaciones**: en orden; solo se firma el primer paso pendiente de la ronda (`CircuitoPasesSalida::actual()`). El formulario manda `aprobacion_id`: si ya no es el que toca responde «Este pase ya no está en el punto correcto del circuito para esta firma.»
- **Quién firma un paso** (`CircuitoPasesSalida::motivoNoPuede()`):
  1. «Aprobar» de Pases de salida con alcance en la sede de **origen** (y "propios" solo en los suyos);
  2. no es el solicitante (usuario ligado a ese colaborador);
  3. cumple la regla del paso (rol / usuario / departamento). Si **nadie** la cumple, firma cualquiera con «Aprobar» en la sede (el pase no se atora);
  4. no firmó otro paso de la misma ronda, salvo que nadie más pueda.
- **Pasos de caseta** (`pases_salida.firmar`, sede de origen salvo recepción y salida de regreso, que firma la sede **destino**): verifican artículos (siempre) y guardan las firmas que el movimiento pide en ESE pase (`PaseSalida::modoFirma()`): `dos` (omisión) = la de la persona que entrega o se lleva el equipo y la de Seguridad (usuario en sesión); `seguridad` = solo la de Seguridad (nombre de la persona opcional); `ninguna` = sin firmas (quedan usuario, hora, IP y artículos verificados en la bitácora, con `detalle.firmas`). Botones: **Registrar Salida**, **Confirmar llegada a esta sede**, **Autorizar salida de regreso**, **Registrar Regreso** (solo cambió el texto; el flujo es el mismo).
  - Salida: cada artículo marcado o escaneado (lector universal, tipo `equipo`); `verificado_con_lector` solo para los del padrón.
  - Recepción y salida de regreso: si falta marcar algo, el comentario es obligatorio.
  - Regreso: cantidad por artículo; si faltan, `regreso_parcial` (comentario obligatorio) o "cerrar con faltantes" (`regresado` + `cerrado_con_faltantes`).
- Todo cambio de estado va en una transacción con el pase bloqueado (`lockForUpdate`).
- **Vencido**: fuera de la propiedad, espera regreso y `fecha_tentativa_regreso` < hoy en la zona de la sede de origen. La insignia "Vencido — Debió Regresar" manda sobre todas.

## Circuito de aprobación (Configuración → Pases de salida)

- Pantalla `/pases-salida/circuito` (`pases_salida.configurar`). Solo la cambia quien tiene «Configurar» con **alcance de empresa** (como los Avisos); con alcance de sede se consulta. Enlace desde la lista (botón **Circuito**) y desde la sección "Pases de salida" de `/configuracion`.
- Máximo 8 pasos; cada motivo necesita al menos un paso obligatorio. Guardar reemplaza el circuito; **los pases en curso conservan su copia**; los nuevos y los reenviados usan el vigente.
- **Restablecer**: borra la configuración y vuelve a la cadena de SEGCAT: Jefe de Departamento (del departamento del solicitante), Contraloría y Gerencia.
- Auditoría: `pases_salida.circuito_actualizado` / `pases_salida.circuito_restablecido` con el resumen antes y después.

## Condición de valor, firmas en caseta y rechazo (ADR-0012)

- **Valor total del pase** (`PaseSalida::valorTotal()`) = Σ cantidad × `valor_unitario`; `null` si algún artículo no tiene valor. `PaseSalidaPaso::aplicaA($motivo, $valor)`: el paso aplica si el motivo coincide y `valor ≥ valor_minimo` (si hay) y `valor < valor_maximo` (si hay); **sin valor conocido, aplica** (por seguridad). Al guardar el circuito, cada motivo necesita al menos un paso obligatorio **sin** condición de valor. El formulario del pase muestra «Valor por pieza» solo si algún paso vigente tiene condición (`CircuitoPasesSalida::usaValor()`).
- **Firmas en caseta**: `CircuitoPasesSalida::firmasCasetaPara($motivo)` (excepción del motivo o «todos») se copia a `pases_salida.firmas_caseta` al **crear** y al **reenviar** (aún no llega a caseta). Venta y traspaso definitivo solo tienen salida. La hoja impresa muestra solo los renglones de firma que aplican (`rolesDeFirma()`); sin firmas, «Sin firma: se registra en la plataforma» con quién y cuándo.
- **Rechazo** (`CircuitoPasesSalida::rechazo()`, vigente al reenviar): `inicio` (omisión) crea la ronda nueva toda pendiente. `retomar`: se recalculan los pasos con el motivo y el valor corregidos (circuito vigente); cada paso que aplica y que en la ronda anterior quedó **aprobado u omitido** (se reconoce por su **nombre**, porque guardar el circuito recrea los ids) se copia aprobado con la misma firma, quién y cuándo, y `conservada_de_ronda`; el que rechazó y los que ahora aplican sin firma quedan pendientes, en orden. Si no queda ninguno pendiente, el pase queda aprobado («Todas las firmas que aplican ya estaban»). Bitácora: evento `firma` «Se conservan las firmas de la ronda anterior: …». Las firmas conservadas cuentan para «una persona, un paso por ronda».
- **Restablecer** borra los pasos **y** los ajustes (vuelve todo a la omisión).

## Avisos en la campana

`CampanaPasesSalida` (además del correo; la campana no depende de Configuración → Avisos por correo):

| Tipo | Cuándo | A quién | Se resuelve |
|---|---|---|---|
| `pase_por_firmar` «Te toca firmar el pase PS-…» (botón **Abrir pase**: `acciones: [{etiqueta, abrir: true}]` lleva a `notificaciones.abrir`) | Se abre un paso | `CircuitoPasesSalida::aprobadores()` | Al aprobar, omitir o rechazar ese paso, o cancelar el pase (`referencia: pase_salida_aprobacion`, id de la aprobación) |
| `pase_aprobado` / `pase_rechazado` (con motivo) | Último paso aprobado / rechazo | Quien lo registró y usuarios del solicitante, menos quien resolvió | — |
| `pase_vencido` «Pase PS-… vencido — debió regresar el …» | Recordatorio diario (`plataforma:pases-vencidos`) | Dueños + «Aprobar» en la sede de origen | El del día anterior queda leído; todos al quedar `regresado` (`pase_salida_vencido`) |
| `pase_llega` «Un pase viene a tu sede» | Registrar Salida hacia otra sede (espera regreso) | «Firmar» con alcance en la sede destino, menos quien registró | Al confirmar la llegada (`pase_salida_llegada`) |

El recordatorio de vencidos ahora recorre todas las empresas activas: campana siempre, correo solo si `pase_salida_vencido` está encendido; cuenta los pases recordados.

## Avisos por correo

Claves en `Empresa::AVISOS` (se encienden o apagan en Configuración → Avisos por correo; activos por defecto, y solo si el correo de la plataforma está configurado):

| Clave | Cuándo | A quién |
|---|---|---|
| `pase_salida_aprobacion` | Se abre un paso (alta, reenvío, aprobación u omisión del anterior) | Quien puede firmar ese paso |
| `pase_salida_resultado` | El pase queda aprobado o se rechaza | Quien lo registró y los usuarios ligados al solicitante |
| `pase_salida_vencido` | Recordatorio diario | Dueños del pase y quien tiene «Aprobar» en la sede de origen |

- Se envían **después de responder** (`defer`).
- El recordatorio de vencidos **no** depende de la web: lo envía `php artisan plataforma:pases-vencidos --si-toca`, que `desplegar.sh` llama en cada corrida del cron (cada 5 minutos), igual que el respaldo diario. Corre en proceso (sin `exec`). Máximo un recordatorio por pase y por día local de su sede, solo después de las 8:00 (`--si-toca`). Queda en la bitácora del pase.

## Firma guardada

- Al firmar, el usuario elige **Usar mi firma guardada** o **Firmar ahora** (con la casilla "Guardar mi firma para usarla la próxima vez").
- Se guarda en `firmas/<empresa>/usuarios/…` (disco privado). Solo la ve su dueño en `GET /pases-salida/mi-firma`.
- Al usarla se **copia** al pase (re-dibujada con `ImagenSegura`): si después la borra o la cambia, el pase no cambia.
- Auditoría: `pases_salida.firma_guardada` / `pases_salida.firma_borrada`.

## Endpoints

| Método y ruta | Permiso | Qué hace |
|---|---|---|
| `GET /pases-salida?filtro=&q=&sede=` | `ver` | Fichas (20 por página). Filtros: `todos`, `mi_firma`, `pendientes`, `aprobados`, `fuera`, `espera_regreso`, `vencidos`, `cerrados`, `rechazados`, `llegan` («Llegan a mi sede»: destino sede, `aprobado` o `salio` con regreso, `sede_destino_id` en las sedes con «Firmar»; la píldora solo se muestra a quien tiene «Firmar»). Búsqueda por folio, solicitante, número de empleado, artículo, serie o código/URL del QR. `?pase=ID` redirige a la ficha; `?nuevo=1` abre el Nuevo Pase |
| `GET /pases-salida/mis-pendientes` | `ver` | Bandeja de firmas (lo que el usuario puede firmar ahora) |
| `POST /pases-salida` | `crear` | Alta; `siguiente=1` = "Registrar y capturar siguiente" |
| `GET /pases-salida/{id}` | `ver` | Ficha del pase |
| `PUT /pases-salida/{id}` | `crear` (dueño) | Corregir y reenviar un pase rechazado |
| `POST /pases-salida/{id}/firmas` | `aprobar` si `paso=aprobacion`; si no, `firmar` | Aprobar el paso que toca o registrar el paso de caseta. Límite 60/min |
| `POST /pases-salida/{id}/rechazar` | `aprobar` | Rechazo con motivo (vuelve al solicitante) |
| `POST /pases-salida/{id}/omitir` | `aprobar` | Omitir un paso opcional con comentario |
| `POST /pases-salida/{id}/cancelar` | `crear` o `editar` (dueño, o `editar` de empresa) | Cancelar antes de aprobarse |
| `GET /pases-salida/{id}/imprimir` | `imprimir` | Hoja carta con logo, QR, artículos y todas las firmas |
| `GET /pases-salida/{id}/firmas/{firma}` | `ver` (o `imprimir`) | Imagen de una firma del pase |
| `GET /pases-salida/verificar/{codigo}` | `ver` + alcance | Página del QR: "Pase auténtico", folio y estado. Límite 60/min |
| `GET`/`DELETE /pases-salida/mi-firma` | `aprobar` o `firmar` | Ver / borrar la firma guardada propia |
| `GET`/`PUT`/`DELETE /pases-salida/circuito` | `configurar` (cambiar: alcance de empresa) | Circuito de aprobación (`pasos[i][…, valor_minimo, valor_maximo]`, `rechazo`, `firmas_caseta[mov][todos|motivos[motivo]]`; sin `rechazo` ni `firmas_caseta` los ajustes no se tocan) |
| `GET /pases-salida/equipos/{equipo}` | `crear` + `equipos.ver` | Datos del equipo escaneado (JSON; `valor` = costo del padrón o null) |

Todo responde **404** si el pase es de otra empresa o está fuera de su alcance; **403** si le falta el permiso o la regla del circuito no lo deja firmar (la ficha explica por qué en un recuadro amarillo).

## Permisos y alcance

- **Empresa**: todo. **Sede**: los pases que **salen de** o **van a** sus sedes. **Propios**: además, solo los que registró.
- Plantillas: **Agente** registra y firma la caseta de su sede; no aprueba. **Director** aprueba en toda la empresa; no registra ni firma la caseta. **Asistente de seguridad** registra e imprime. **Jefe de seguridad** y **Supervisor**: todo en su sede (consultan el circuito). **Administrador**: todo, incluido configurar el circuito.
- La migración v2 da `pases_salida.configurar` a los roles **Administrador** existentes con el alcance de su `pases_salida.editar`.

## Auditoría

`pases_salida.creado`, `.firmado` (cada aprobación), `.aprobado`, `.paso_omitido`, `.rechazado`, `.reenviado`, `.cancelado`, `.salida_registrada`, `.recibido_en_destino`, `.salida_de_regreso`, `.regreso_parcial`, `.regresado`, `.circuito_actualizado`, `.circuito_restablecido` (con pasos y ajustes antes/después), `.ajustes_actualizados` (firmas en caseta y rechazo; sujeto: la empresa), `.firma_guardada`, `.firma_borrada`. `.reenviado` guarda `al_reenviar`; los pasos de caseta, `firmas`. Además, la bitácora propia del pase (pestaña **Firmas y bitácora**) con quién, cuándo, comentario, firmas e IP.

## Pases registrados antes de v2

La migración `000410` los conserva: les da código de verificación, crea sus 3 aprobaciones (Jefe de Departamento, Contraloría, Gerencia) marcadas con las firmas de SEGCAT que ya tenían (o el rechazo), arma su bitácora con esas firmas y marca la salida/regreso de sus artículos. Las firmas de caseta antiguas se ven en la bitácora y en la hoja ("Firmas registradas con el circuito anterior"). Un pase a medio firmar en caseta continúa con el paso nuevo.

## Qué se corrigió respecto a SEGCAT

- **Circuito fijo y anónimo**: SEGCAT pedía 3 firmas de aprobación que cualquiera con `editar` podía dibujar, en cualquier orden, poniendo cualquier nombre. Ahora el circuito es **configurable por empresa**, se firma **en orden**, cada paso lo firma solo quien dice la regla, con su usuario (nombre y cargo reales), el solicitante no se aprueba a sí mismo y una persona no firma dos pasos.
- **Rechazo final**: un rechazo mataba el pase. Ahora vuelve al solicitante, que lo **corrige y reenvía** (ronda nueva) o lo **cancela**.
- **Caseta sin verificar**: la salida y el regreso eran solo firmas. Ahora se **verifica cada artículo** (escaneado con el lector universal o marcado) y el regreso admite **regresos parciales** y cierre con faltantes.
- **Sin avisos**: nadie sabía que tenía algo por firmar. Ahora hay **bandeja de firmas**, aviso en **Inicio** y **correos** (siguiente aprobador, resultado y vencidos).
- **Sin historial**: ahora cada paso queda en una **bitácora inmutable** con IP.
- **Hoja sin validez**: la hoja impresa ahora lleva **QR de verificación** que confirma en la caseta que el pase es auténtico y su estado al momento.
- **Permisos**: SEGCAT usaba `pases_salida.editar` para todo; ahora `aprobar`, `firmar` y `configurar`, cada uno con alcance en la sede que corresponde (antes la sede destino ni siquiera podía ver el pase que recibía).
- **Firmas**: se guardaban en base64 dentro de la tabla; ahora van al disco privado, se validan y se sirven solo con permiso y alcance. La firma guardada del usuario se copia, nunca se comparte.
- **Folio**: era el id global de la tabla; ahora es consecutivo por empresa, en una sola transacción.
- **Carreras**: el pase se bloquea en cada firma.
- **Destino obligatorio**, **vencidos con la fecha local de la sede**, **búsqueda** por número de empleado, artículo, serie y QR, lista paginada.

## Firmas que tardan: reloj, color y escalamiento (ADR-0013)

Criterio 3, como las autorizaciones de la caseta (`App\Services\Autorizaciones\Escalamiento`) pero en horas o días.

- **Servicio**: `app/Services/PasesSalida/EsperaPasesSalida.php`. Comando `plataforma:escalar-pases --si-toca` (en `despliegue/desplegar.sh`, en proceso, sin exec).
- **Reloj**: `relojDe()` calcula desde cuándo espera el paso actual: el `resuelto_en` más reciente de los pasos anteriores de la ronda o el `created_at` del paso (al enviar o reenviar). Se muestra siempre en la lista (`relojes`, una lectura de ajustes por pantalla) y en la ficha.
- **Ajustes**: `empresas.preferencias['pases_salida']['espera'] = {recordar_horas, escalar_horas}` (1 a 720; la pantalla los pide en horas o días y los guarda en horas). Se guardan con el circuito (`espera[recordar|recordar_unidad|escalar|escalar_unidad]`); `escalar` debe ser mayor que `recordar`. Sin ellos (omisión) no hay color ni avisos nuevos. «Restablecer la configuración original» también los borra.
- **Color**: `normal` (verde) antes de `recordar`, `ambar` desde `recordar`, `roja` desde `escalar`; sin ajustes, sin clase de color (se ve como hoy).
- **Recordatorio** (una vez por paso, `pases_salida_aprobaciones.recordatorio_en`): campana `pase_firma_recordatorio` a `CircuitoPasesSalida::aprobadores()` del paso, con referencia `pase_salida_aprobacion` (se resuelve al firmar, omitir o rechazar ese paso, como «Te toca firmar»), correo con el aviso `pase_salida_espera`, renglón «Recordatorio» en la bitácora del pase y auditoría `pases_salida.recordatorio`.
- **Escalamiento** (una vez por paso, `escalado_en` y `escalado_a`): a los aprobadores del **siguiente paso pendiente** de la ronda que no sean los del paso actual; si no hay (último paso o mismas personas), a quien tiene `pases_salida.configurar` en la sede de origen. Campana `pase_firma_escalada`, correo `pase_salida_espera`, bitácora («Se avisó a …») y auditoría `pases_salida.escalada`. El reloj dice «Se avisó a: …».
- **Auditoría del ajuste**: `pases_salida.tiempos_espera` (aparte de `ajustes_actualizados`, que sigue cubriendo firmas en caseta y rechazo).
- **Migración**: `2026_10_24_000100_incidentes_turno_y_espera_de_pases` agrega `recordatorio_en`, `escalado_en` y `escalado_a` a `pases_salida_aprobaciones`.

## Criterios de experiencia de uso (excepciones anotadas)

- **Regla 4 (verbos):** los botones de caseta de la sede destino se llaman **Confirmar llegada a esta sede** y **Autorizar salida de regreso** por indicación del dueño del proyecto (crítica de uso, octubre de 2026), para que el agente de la sede destino entienda qué hace. Solo cambió el texto.
- **Regla 3 (reloj, color y suplente):** resuelta en ADR-0013 (ver «Firmas que tardan»). El color y los avisos dependen de las horas que configure la empresa; sin ellas solo se ve el reloj (decisión del dueño: por omisión nada cambia).
