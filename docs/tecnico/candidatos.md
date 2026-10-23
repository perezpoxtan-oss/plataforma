# Candidatos (ficha / CV, etapas, contratar y kiosco)

Módulo nuevo (no existía en SEGCAT). Decisiones: [ADR-0007](../decisiones/ADR-0007-recepcion-kiosco-y-autorizaciones.md), [ADR-0008](../decisiones/ADR-0008-postulaciones-y-que-pase-de-rh.md) (postulaciones) y [ADR-0009](../decisiones/ADR-0009-entrevistas-canalizar-y-elegir.md) (fase 2: etapas nuevas, evaluaciones, canalizar y elegir) y [ADR-0011](../decisiones/ADR-0011-documentos-contrato-plazos-y-caducidad.md) (fase 3: documentos para contratar, contrato, plazos, caducidad y expediente). El panel de Recepción, las notificaciones y las autorizaciones están en [recepcion-y-autorizaciones.md](recepcion-y-autorizaciones.md).

## Piezas

| Pieza | Archivo |
|---|---|
| Migración | `database/migrations/2026_10_14_000200_crear_candidatos_y_autorizaciones.php`, `2026_10_19_000100_crear_postulaciones.php` (fase 1), `2026_10_20_000100_etapas_de_candidatos_fase_2.php`, `2026_10_20_000200_crear_evaluaciones_candidato.php`, `2026_10_20_000300_permiso_evaluar_candidatos.php`, `2026_10_20_000400_vacantes_jefe_ve_cv.php` (fase 2), `2026_10_22_000100_etapas_simplificadas_con_rh.php` y `2026_10_22_000200_solicitud_corta_candidatos.php` (ADR-0010), `2026_10_22_000300_candidatos_fase_3_documentos_plazos_y_caducidad.php` (fase 3, ADR-0011; corre después de las de ADR-0010), `2026_11_08_000100_evaluacion_por_delegacion.php` |
| Modelos | `Candidato` (ficha), `Postulacion`, `EvaluacionCandidato`, `CandidatoDocumento`, `CandidatoEvento`, `EnlaceKiosco`, `DocumentoParaContratar` (fase 3) |
| Reglas | `app/Services/Candidatos/AdministradorCandidatos.php` (alta, ficha única, CV, etapas manuales, contratar, eliminar), `Postulaciones.php` (postulación activa y espejo), `Entrevistas.php` (fase 2: evaluaciones, canalizar, reprogramar, no se presentó, Entrevistar, elegir y plazas, avisos), `DocumentosCandidato.php` (archivos privados), `Kiosco.php`, `CambioNoPermitido.php`; fase 3: `DocumentosParaContratar.php` (lista por empresa, estado del expediente, recibido en papel, contrato firmado, paso al expediente), `PlazosCandidatos.php` (plazos por empresa, recordatorios únicos, color de Mis pendientes, reloj de la ficha, «si toca») y `CaducidadCandidatos.php` (archivar, avisar, conservar y borrar) |
| Comando | `app/Console/Commands/CandidatosPendientes.php` (`plataforma:candidatos-pendientes --si-toca`, en proceso desde `despliegue/desplegar.sh`) |
| Controladores | `app/Http/Controllers/RecursosHumanos/CandidatoController.php`, `EntrevistaController.php` (pantalla Entrevistar), `KioscoController.php` (público), `ExpedienteController.php` (fase 3: expediente del colaborador) |
| Vistas | `resources/views/rh/candidatos/` (`index`, `show`, `configuracion` (fase 3), `_cv`, `_fila-*`, `_evaluacion`, `_evaluar-campos`), `resources/views/rh/entrevistas/` (`index`, `show`), `resources/views/organizacion/colaboradores/expediente.blade.php` (fase 3), `resources/views/correos/entrevista-candidato.blade.php`, `resources/views/kiosco/` |
| Pruebas | `tests/Feature/Seguridad/CandidatosYAutorizacionesTest.php`, `CandidatosFase1Test.php`, `CandidatosFase2Test.php` (con `AyudasEntrevistas`), `CandidatosFase3Test.php` |

## Tablas

### `candidatos`
`empresa_id`, `sede_id`, `persona_id` (Padrón de personas), `acceso_id` (registro en caseta), `departamento_id`, `puesto_id`, `vacante` (texto si no está en el catálogo), contacto (`nombre_completo`, `telefono`, `correo`, `fecha_nacimiento`, `ciudad`), CV (`escolaridad`, `experiencia`, `referencias` en JSON —máximo 6 renglones—, `habilidades`, `idiomas`, `disponibilidad`, `disponibilidad_notas`, `pretension`), `notas_rh`, `etapa`, `motivo_descarte`, `origen` (caseta | rh | kiosco), `autocaptura_pendiente`/`autocaptura_en`, privacidad (`privacidad_aceptada_en`, `privacidad_ip`, `privacidad_version` = SHA-256 del texto, `privacidad_medio`), tiempos (`llegada_en`, `avisado_rh_en`, `revision_en`, `aprobado_rh_en`, `enviado_departamento_en`, `respuesta_departamento_en`, `entrevista_en`, `decision_en`/`decision_por`, `contratado_en`), `colaborador_id`, auditoría.

### `postulaciones` (fases 1 y 2)
Cada vez que la persona aplica: `empresa_id`, `sede_id`, `candidato_id`, `vacante_id`, `departamento_id`, `puesto_id`, `vacante` (texto), `etapa`, `origen` (caseta | rh | kiosco | web), `motivo_descarte` (motivo de Considerar o Rechazar), fechas por etapa (`revision_en`, `entrevista_rh_en`, `canalizado_en`/`canalizado_por`, `evaluado_en`, `elegido_en`, `no_se_presento_en`, `decision_en`/`decision_por`, `contratado_en`; las del proceso anterior `aprobado_rh_en`, `entrevista_en`, `enviado_departamento_en`, `respuesta_departamento_en` quedan como historial), la cita con el departamento (`entrevistador_id`, `cita_en`, `cita_ahora`, `cita_lugar`, `numero_entrevista` = 1.ª, 2.ª…), `colaborador_id`, auditoría. Índices `(empresa_id, entrevistador_id, etapa)` y `(vacante_id, etapa)`. Una vacante tiene muchas postulaciones; una ficha, varias en el tiempo.

### `evaluaciones_candidato` (fase 2)
Una por entrevista evaluada: `empresa_id`, `sede_id`, `postulacion_id`, `candidato_id`, `tipo` (`rh` | `departamento`), `evaluador_id`, `entrevista_en`, `criterios` (JSON «nombre del criterio» → 1..5, con los criterios configurados ese día), `promedio` (2 decimales), `comentario` (obligatorio para considerar y rechazar), `resultado` (RR. HH.: `canalizar` | `considerar` | `rechazar`; departamento: `elegir` | `considerar` | `segunda_entrevista` | `rechazar`), `numero` (la entrevista 1.ª, 2.ª de la postulación), `por_delegacion_de` (migración `2026_11_08_000100_evaluacion_por_delegacion`, `down()` quita la llave y la columna: el entrevistador titular cuando evaluó su delegado; null = la evaluó él; `EvaluacionCandidato::quienEvaluo()` → «Ana (por delegación de Diego)» en la ficha, el historial, el expediente y el aviso a RR. HH.), `creado_por`/`actualizado_por`, timestamps. Los criterios se configuran por empresa en `empresas.preferencias['recepcion']['criterios']` (hasta 8; sin el dato, los cinco de `AjustesRecepcion::CRITERIOS_DEFECTO`); en el formulario cada criterio va como `criterios[<clave>]` (`AjustesRecepcion::claveCriterio()`).

**Qué columna manda.** La postulación. `candidatos.etapa`, `sede_id`, `vacante_id`, `departamento_id`, `puesto_id`, `vacante`, `motivo_descarte`, las fechas de etapa y `colaborador_id` son un **espejo de la postulación activa** (la de mayor `id` de la ficha) para que listas, filtros, contadores, el panel de Recepción, las métricas y la exportación sigan igual. Todo cambio de etapa o de fechas se escribe primero en la postulación (`Postulaciones::cambiar()`, condicionado a la etapa esperada) y se refleja en la ficha (`reflejar()`, solo si es la activa). La única escritura en sentido contrario es cuando RR. HH. edita en la ficha a qué aplica (`desdeFicha()`: departamento, puesto, vacante). Fichas sin postulación (anteriores a la migración y no alcanzadas por ella) reciben una al primer cambio (`asegurar()`, que traduce etapas viejas con `Candidato::ETAPAS_ANTERIORES`). **Fase 2: el espejo se conserva** (ver ADR-0009); lo nuevo (entrevistador, cita, número de entrevista, fechas de canalización, evaluación y elección) no se copia: la ficha, Entrevistar, Mis pendientes, las métricas y la tabla de la vacante lo leen de la postulación.

**Una ficha por persona.** `AdministradorCandidatos::buscarFicha(persona_id, teléfono normalizado, CURP)` dentro de la empresa activa. Caseta: persona del padrón y el teléfono que tenga en el padrón; si viene a una cita (entrevista, documentos, firma), también nombre exacto (`fichaDeLaVisita()`). RR. HH. («Nuevo candidato», `crearOLigar()`): teléfono o CURP; si existe, no crea otra (solo completa campos vacíos) y abre una postulación si no tiene una en proceso; si la abierta es de una sede fuera de su alcance, error. Internet (`BolsaTrabajo::postular()` y `postularCorta()`): teléfono o CURP. **Seguridad:** un envío sin sesión nunca modifica una ficha existente (ni nombre, correo, domicilio, firma, aviso aceptado, `solicitud_corta` ni `autocaptura_pendiente`; solo `llegada_en`): se le suma la postulación y el evento del historial añade «Los datos … no se aplicaron a su ficha …» y, si el nombre o el correo escritos difieren (comparación sin acentos ni mayúsculas, `datosDistintos()`), «Escribió distinto: nombre «…», correo «…»». La auditoría `candidatos.postulacion` guarda `datos_no_aplicados` (solo las claves) y el aviso a RR. HH. añade «Ojo: escribió un nombre o correo distinto…». El enlace del kiosco solo va al correo que ya tenía la ficha. La página de gracias es la misma con o sin ficha previa (`enlace_enviado` depende solo de que haya correo configurado), para no revelar si el teléfono existía. El CV adjunto se agrega como documento nuevo (origen `web`; no reemplaza nada). En todos los casos: con postulación abierta se usa esa; sin ella se crea otra. Eventos del historial: «Volvió a la caseta: …», «Se volvió a postular por internet …».

### `candidato_documentos`
`tipo` (40 caracteres: `cv`, `otro`, `contrato` —fijos— o la clave de un renglón de `documentos_para_contratar`; los de antes `ine` y `comprobante` coinciden con la lista estándar), `nombre_original`, `ruta` (disco privado `candidatos/<empresa>/<candidato>/<uuid>.<ext>`; **null** cuando se recibió en papel), `mime`, `bytes`, `origen` (rh | kiosco | web | papel), `recibido_en` (fecha de entrega del papel), `colaborador_id` (fase 3: al contratar, todos los documentos de la ficha quedan ligados al colaborador y se ven en su expediente).

### `documentos_para_contratar` (fase 3)
Lista de cada empresa: `empresa_id`, `clave` (snake_case, única por empresa; es el `tipo` del documento subido), `nombre`, `obligatorio`, `orden`, `activo` (un renglón quitado queda inactivo para que los documentos ya subidos conserven su nombre), auditoría. Sin renglones se usa `DocumentosParaContratar::DEFECTO` (INE, CURP, NSS, RFC, acta de nacimiento, comprobante de domicilio, certificado de estudios, cartas de recomendación; todos obligatorios). Máximo 15.

### Fase 3 en `postulaciones` y `candidatos`
`postulaciones`: `contrato_firmado_en`, `contrato_recibido_por`, `contrato_documento_id` (el archivo, opcional), los sellos de recordatorio `aviso_evaluacion_en`, `aviso_documentos_en`, `aviso_por_atender_en`, `aviso_no_se_presento_en` (un recordatorio por plazo y por día) y `archivada_en`. `candidatos`: `archivada_en` (espejo), `aviso_solicitud_en`, `aviso_borrado_en`, `borrar_en`, `conservar_hasta`. Los sellos y el archivo **no tocan `updated_at`**: la caducidad cuenta desde el último movimiento real.

### `candidato_eventos`
Historial: `evento`, `etapa_anterior`, `etapa_nueva`, `comentario`, `user_id` (null = el candidato en el kiosco).

### `enlaces_kiosco`
`token_hash` (SHA-256; el token nunca se guarda), `codigo` (6 caracteres sin 0/O ni 1/I/L), `expira_en`, `usos_maximos`, `usos`, `ultimo_uso_en`, `revocado_en`.

## Etapas (fase 2, simplificadas en ADR-0010)

```
registrado (Por atender) ─Atender / «Que pase y lo atiendo»▶ con_rh ─(evaluación RR. HH. «canalizar»)─ Canalizar al departamento ▶ canalizado
canalizado ─(el entrevistador evalúa)▶ elegido (Elegir) | canalizado n+1 sin cita (Segunda entrevista) | evaluado (Considerar · Rechazar)
evaluado ─Segunda entrevista (RR. HH.)▶ canalizado (numero_entrevista + 1)      elegido ─Contratar▶ contratado
canalizado ─Reprogramar▶ canalizado      canalizado ─No se presentó▶ no_se_presento ─Reprogramar▶ canalizado
Laterales: considerar (comentario) y rechazado (motivo) desde casi todas; considerar ─Atender▶ con_rh; rechazado ─Atender▶ con_rh
```

| Etapa | Etiqueta | Siguientes (`Candidato::TRANSICIONES`) |
|---|---|---|
| `registrado` | Por atender | con_rh, considerar, rechazado |
| `con_rh` | Con RR. HH. | canalizado, considerar, rechazado |
| `canalizado` | Entrevista con el departamento | canalizado (reprogramar / segunda), evaluado, elegido, no_se_presento, considerar, rechazado |
| `evaluado` | Evaluado por el departamento | canalizado (segunda entrevista), considerar, rechazado |
| `elegido` | Elegido | contratado, rechazado |
| `no_se_presento` | No se presentó | canalizado, considerar, rechazado |
| `considerar` | Considerar / cartera | con_rh, rechazado |
| `rechazado` | Rechazado | con_rh |
| `contratado` | Contratado | — |

`revision` y `entrevista_rh` ya no existen: la migración `2026_10_22_000100_etapas_simplificadas_con_rh` los traduce a `con_rh` en `postulaciones`, `candidatos` (espejo) y `candidato_eventos` (idempotente). Su `down()` regresa `con_rh` a `entrevista_rh` si la postulación tiene `entrevista_rh_en` y a `revision` si no; el espejo sigue a su postulación más reciente y el historial vuelve a `revision`. Las columnas `revision_en` («RR. HH. lo atendió») y `entrevista_rh_en` (fecha de la primera evaluación de RR. HH.) se conservan.

`Candidato::ETAPAS`, `COLORES`, `RUTA`, `LATERALES`, `ABIERTAS`, `POR_ENTREVISTAR` (`con_rh`) y `MANUALES` (lo que acepta `PATCH /candidatos/{c}/etapa`: con_rh, considerar, rechazado; considerar y rechazado piden comentario). `ETAPAS_ANTERIORES` incluye `revision`/`entrevista_rh → con_rh`. Lo demás tiene su formulario y su servicio:

- **Atender en el «Que pase»** (`Autorizaciones::responder(..., $atender)`): desde el panel de Recepción el botón «Que pase y lo atiendo» manda `atender=1` y, si el candidato sigue `registrado`, lo pasa a `con_rh` con el mismo actor. «Que pase» a secas, la campana y el correo no lo atienden.
- **Evaluación de RR. HH.** (`Entrevistas::evaluarRh`): solo con `con_rh`; no cambia de etapa (anota `entrevista_rh_en` la primera vez). Considerar → considerar y Rechazar → rechazado con el comentario como motivo, **sin avisar al departamento**. Canalizar deja lista la canalización (y abre su diálogo).
- **Canalizar al departamento** (`Entrevistas::canalizar`): desde con_rh (exige la última evaluación de RR. HH. con «canalizar»), evaluado (segunda entrevista: `numero_entrevista + 1`), canalizado o no_se_presento (reprogramar). La vista muestra vacante y departamento de la postulación como texto, con «cambiar vacante o departamento» (`<details>`) que abre los selectores (se abre solo si no hay departamento o hay error en esos campos). Pide entrevistador (`Entrevistas::elegibles`), cuándo (`ahora` o fecha y hora locales, no en el pasado ni a más de 90 días), lugar o notas y la casilla «Avisar al candidato por correo».
- **No se presentó** (`Entrevistas::noSePresento`): RR. HH. con la cita ya pasada; el propio entrevistador (`POST /entrevistas/{p}/no-se-presento`, `$porEntrevistador`) solo con `cita_ahora` y si `puedeEvaluar`; en ese caso se resuelve su aviso y se avisa a RR. HH.
- **Evaluación del departamento** (`Entrevistas::evaluarDepartamento`): solo canalizado y solo el entrevistador asignado o su delegado activo. Elegir → elegido; **Segunda entrevista → sigue `canalizado` con el mismo entrevistador, `numero_entrevista + 1`, sin cita** (la ficha ofrece «Ponle fecha (reprogramar)» y RR. HH. recibe «Pide segunda entrevista: ponle fecha a …»); Considerar o Rechazar → evaluado.
- **Corregir la evaluación** (`Entrevistas::puedeCorregir`, `corregirEvaluacion`, `MINUTOS_CORRECCION = 15`): quien la hizo, dentro de 15 minutos, si no hay evaluación posterior y la postulación sigue como la dejó (elegido / canalizado n+1 sin cita / evaluado). Si cambia el resultado se acomoda la postulación; una «Elegir» solo corrige estrellas y comentario. Evento `evaluacion_corregida`, auditoría `candidatos.evaluacion_corregida` y aviso a RR. HH.
- **Plazas** (`Entrevistas::cubrirPlazas`): al elegir, si elegidos + contratados de la vacante ≥ `vacantes.plazas`, las demás postulaciones de esa vacante en canalizado o evaluado pasan a considerar con «Se eligió a otra persona para esta vacante», se cancela su cita y RR. HH. recibe «Vacante cubierta» (referencia `vacante`) con el botón **Cerrar como cubierta** (`PATCH /vacantes/{v}/estado`, `cierre_motivo=cubierta`); `AdministradorVacantes::cambiarEstado` resuelve los avisos de la vacante al publicarla o cerrarla.

## «¿A qué viene?» en la caseta (ADR-0010)

`Acceso::OPCIONES_CASETA`: `busca_empleo`, `cita` («Ya tiene cita») y `tramite`. Con `cita`, `AdministradorCandidatos::desdeAcceso` busca la ficha (persona, teléfono o nombre exacto) y `deducirCita()` guarda en `accesos.viene_a` lo deducido de la postulación abierta: `canalizado → entrevista`, `elegido → documentos` (o `firma` si ya tiene todos los obligatorios de «Documentos para contratar» de su empresa: `AdministradorCandidatos::documentosCompletos()` usa `DocumentosParaContratar::estado()['faltan'] === []`, subidos o recibidos en papel), lo demás → `cita` («Cita con RR. HH.»). Sin ficha queda `busca_empleo` con nota en el historial. **Decisión:** los accesos existentes **no se migran**; `Acceso::VIENE_A` conserva `entrevista`, `documentos`, `firma` e `informes` con su etiqueta, y la caseta sigue aceptando esos valores si un formulario viejo los manda (`informes` ya no, porque no abre ficha y el enlace a las vacantes se muestra siempre).

## Postulación corta por internet (ADR-0010)

`BolsaTrabajo::postularCorta`: nombre completo, teléfono, correo (obligatorio), sede y CV opcional + aviso de privacidad. Crea o reutiliza la ficha por teléfono, con `candidatos.solicitud_corta = true` (migración `2026_10_22_000200`, `down()` quita la columna) salvo que la ficha ya tuviera su solicitud completa. `Kiosco::enviarEnlacePorCorreo` genera un enlace del kiosco de `HORAS_ENLACE_CORREO = 72` horas (anula el anterior) y manda `AvisoSolicitudCandidato`; sin correo configurado solo lo anota en el historial. La marca se quita al guardar en el kiosco o con «Editar solicitud». Desde la ficha, `POST /candidatos/{c}/enlace/correo` (`candidatos.editar`) lo reenvía. `BolsaTrabajo::postular` (solicitud completa) se conserva para los datos demo y pruebas. Referencias personales mínimas de la solicitud completa: `AdministradorCandidatos::REFERENCIAS_MINIMAS = 1` (constante; no hay un ajuste natural por empresa).

## Criterios de entrevista

Pantalla `GET /candidatos/criterios` (`candidatos.configurar`; guarda con alcance de empresa) que envía a `PUT /rh/recepcion/ajustes` solo `criterios[]` (parte «criterios» de `AjustesRecepcion::guardar`). Se quitaron de Recepción → Ajustes.

Cada cambio es un `UPDATE … WHERE etapa = <la esperada>` (doble clic o dos personas: el segundo recibe «Otra persona acaba de cambiar…»).

**Contratar** (solo «Elegido», permisos `candidatos.contratar` y `colaboradores.crear`): pide número de empleado y confirma nombre/apellidos (se sugieren partiendo el nombre), sede, departamento y puesto; llama a `AdministradorColaboradores::crear()` (mismas validaciones: número único, sede en el alcance…) y deja el candidato «Contratado» con `colaborador_id`. Todo en una transacción.

## Entrevistar (el jefe)

Pantalla propia (`EntrevistaController`), con permiso `candidatos.evaluar` (sin `candidatos.ver`: el jefe no ve la lista ni las fichas). `Entrevistas::limitar()` deja ver solo las postulaciones donde el usuario es el entrevistador (o su delegado activo) o que él evaluó, dentro de sus sedes de `candidatos.evaluar`; cualquier otra → **404** (también a RR. HH. y al Administrador). Evaluar exige además `puedeEvaluar()` (asignado o delegado) → si no, 404. Muestra un **resumen** (vacante, escolaridad máxima, experiencia, disponibilidad, rolar turnos, pretensión, idiomas, habilidades; nunca CURP, RFC, NSS, domicilio, teléfono ni correo), las evaluaciones de la postulación y, solo si la vacante tiene `jefe_ve_cv`, el CV en PDF (`CandidatoDocumento` tipo cv con mime application/pdf) por `GET /entrevistas/{p}/cv` desde el disco privado.

## Avisos (fase 2)

| Cuándo | A quién | Cómo |
|---|---|---|
| Canalizar / segunda entrevista | Entrevistador o su delegado activo | Campana `entrevista_asignada` (referencia `entrevista` = postulación), Mis pendientes «Entrevistas por evaluar», correo `AvisoRecepcion` (aviso `entrevista_departamento`) con resumen sin datos oficiales, evaluación de RR. HH. y botón firmado `entrevistas.correo` (72 h) |
| Reprogramar | El anterior («Ya no entrevistas a …», `entrevista_cambio`) y el nuevo (o el mismo: «Se reprogramó la entrevista») | Igual |
| Considerar / Rechazar desde canalizado, no se presentó, vacante cubierta | Entrevistador («Se canceló la entrevista de …») | Campana y correo; los avisos anteriores de esa entrevista se resuelven |
| La caseta registra «Entrevista» de alguien canalizado | Entrevistador o delegado | `entrevista_llegada`: «Juan Pérez ya está en recepción para su entrevista de las 11:00» |
| El departamento evalúa o elige; vacante cubierta | Recursos Humanos de la sede (`candidatos.editar`) | Campana `evaluacion_departamento` y correo (aviso `evaluacion_departamento`) |
| Canalizar o reprogramar con la casilla marcada | El candidato (si tiene correo, la plataforma tiene correo configurado y hay cita) | `AvisoEntrevistaCandidato`: fecha, hora, lugar, a quién buscar y el nombre de la empresa. Nunca resultados. Queda en el historial (`correo_candidato`) |

## Aviso de privacidad

Obligatorio (`acepta_privacidad`) para guardar el CV desde el kiosco y para la captura de RR. HH. si aún no se había aceptado. Texto por empresa en Recepción → Ajustes (o Configuración). El texto de fábrica (`AjustesRecepcion::PRIVACIDAD_BORRADOR`) dice **«Borrador — validar con su abogado»**.

## Kiosco

- Lo genera quien tiene `candidatos.editar` (sus sedes) o la caseta con `accesos.crear` (solo candidatos registrados en caseta de sus sedes, sin ver su CV): `POST /rh/recepcion/kiosco/{candidato}`. Generar otro anula el anterior. En la ficha hay un solo botón, **QR para que llene su solicitud**, que manda `reusar=1`: si ya hay uno vigente lo muestra en lugar de crear otro. «Anular enlace» queda como acción discreta si hay uno vigente. El panel de Recepción ya no tiene botón QR.
- La tableta («Modo kiosco») muestra el QR con `/k?codigo=XXXXXX`. El candidato confirma el código (`POST /k`), recibe un token nuevo para ese enlace y llega a `/k/{token}`.
- Lo que envía: datos del CV (no el departamento ni el puesto del catálogo), aviso de privacidad, hasta 3 documentos. Cada envío cuenta un uso (actualización condicionada: no se pasa del máximo aunque lleguen dos a la vez). La ficha queda **«Por revisar»** y RR. HH. recibe aviso (campana y correo).
- Vencido, usado, anulado o inventado → página «Este enlace ya venció / ya se usó / no existe» con estado 404.

## Fase 3: documentos para contratar, contrato, plazos, caducidad y expediente

**Documentos.** `DocumentosParaContratar::estado(ficha, postulación)` devuelve obligatorios, cuántos tiene, qué falta, opcionales que faltan y si el contrato está firmado; `faltantesParaContratar()` es lo que «Contratar» advierte. «Recibido en papel» (`recibidoEnPapel()`) crea un `CandidatoDocumento` sin archivo (origen `papel`, `recibido_en`, `creado_por` = quien lo recibió). El selector «Agregar documento» ofrece CV + la lista de la empresa + Otro; el contrato solo entra por «Contrato firmado» (`contratoFirmado()`: solo desde `elegido`, fecha local no futura, archivo opcional en el disco privado). `AdministradorCandidatos::contratar()` exige expediente completo salvo `forzar=1` con `motivo_forzar` (≤ 300): el motivo va al historial y a la auditoría (`forzado`, `faltantes`, `motivo`). Al contratar, `pasarAlExpediente()` pone `colaborador_id` a todos los documentos de la ficha.

**Plazos** (`PlazosCandidatos`): por empresa en `empresas.preferencias['recepcion']['plazos']` (`DEFECTO`: solicitud 1 día, «Por atender» o «Con RR. HH.» sin movimiento 2 días (`ETAPAS_RH` = registrado y con_rh; aviso «Sin atender: …» o «Sin canalizar: …»), evaluación 2 días hábiles, documentos 5 días, cita vencida 24 h, archivar 6 meses, borrar 12 meses; `LIMITES` acota cada uno). `correr()` recorre las empresas activas; por cada plazo vencido sin sello de hoy (nunca, o hace más de un día) manda **un aviso al día** (el anterior de la campana queda leído y sin botones) (campana `plazo_candidato` + correo `AvisoRecepcion` si el aviso `plazo_candidato` de la empresa está encendido) a RR. HH. de la sede (`candidatos.editar` con alcance) o al entrevistador y su delegado (`Entrevistas::aQuienAvisar()`), y lo audita sin datos personales (`candidatos.plazo`, usuario null). La cita vencida sin `Acceso` ligado solo **propone** «Marcar como no se presentó» (botón de la campana a `candidatos.no-se-presento`). `nivelesPara()` da el color de cada renglón de Mis pendientes (`ambar` al pasar el plazo, `roja` al doble) y `relojDe()` el de la ficha. `correrSiToca(empresa)` corre con candado de caché de 60 s cuando `toca()` (consultas de existencia); lo llaman la lista de Candidatos y la consulta periódica de la campana (`MisPendientes::para(reciente: false)`) de quien edita candidatos, por si el cron del hosting no corre; el pintado de cada pantalla no lo dispara. `nivelesPara()` solo se calcula para los renglones de candidatos con pendientes (`RENGLONES`).

**Caducidad** (`CaducidadCandidatos`): `archivar()` marca `archivada_en` en postulaciones en considerar/rechazado con `decision_en` (o `updated_at`) de hace ≥ archivar_meses, refleja a la ficha y anota el historial; la lista las excluye (filtro `archivadas=1`). `avisarBorrado()` toma fichas sin contratación (ni `colaborador_id`, ni postulación contratada) sin movimiento en ficha, postulaciones ni documentos desde hace ≥ borrar_meses − 15 días, fija `borrar_en` (último movimiento + meses, nunca antes de 15 días desde el aviso) y avisa a RR. HH. de la sede (campana `caducidad_candidato` con botón «Conservar 6 meses más» → `candidatos.conservar`, correo). `conservar()` pone `conservar_hasta`, limpia el aviso y resuelve la campana; al vencer vuelve a avisar. `borrar()` elimina ficha, documentos y firma cuando `borrar_en` ya pasó, el aviso tiene ≥ 15 días y no hay `conservar_hasta` vigente; auditoría `candidatos.borrado_por_caducidad` con conteos y sede, sin nombre. Filtro `caducar=1` = fichas con `borrar_en`.

**Expediente del colaborador** (`ExpedienteController`, `/colaboradores/{c}/expediente`): `colaboradores.ver` en alcance; documentos solo con `colaboradores.datos_personales` en alcance; «Su paso por Candidatos» con `candidatos.ver`; cada resumen (accesos, llaves en uso, pases, resguardos, procedimientos por leer del usuario vinculado) solo si el usuario puede ver ese módulo. Los archivos salen por `/colaboradores/{c}/expediente/{d}` (datos personales; los de papel responden 404).

**Tarea programada.** `plataforma:candidatos-pendientes --si-toca` en `desplegar.sh` (en proceso, sin `exec`): recordatorios, «no se presentó» propuesto, archivar, avisar y borrar. `--si-toca` solo escribe cuando hizo algo.

## Endpoints

| Método y ruta | Permiso | Qué hace |
|---|---|---|
| `GET /candidatos?q=&etapa=&sede=&departamento=&vacante=&revisar=1` | `candidatos.ver` | Lista (una tarjeta por ficha) con conteo por etapa; `etapa=en_proceso` y `etapa=por_entrevistar` (con_rh); `vacante` busca en todas sus postulaciones; `revisar=1` = «Solicitudes por revisar» (`autocaptura_pendiente`) |
| `GET /candidatos/duplicado?campo=telefono\|curp&valor=` | `candidatos.crear` | Aviso de duplicado en vivo (`parecido` + `abrir` = su ficha si está en sus sedes) |
| `POST /candidatos` | `candidatos.crear` | Captura de RR. HH. (pide aviso de privacidad; si la persona ya tiene ficha, usa la suya) |
| `GET /candidatos/exportar` | `candidatos.exportar` | CSV (`App\Support\Csv`, sin contacto; con promedio de RR. HH. y del departamento) |
| `GET /candidatos/{c}` | `candidatos.ver` | Ficha |
| `PUT /candidatos/{c}` | `candidatos.editar` | Editar CV y notas |
| `PATCH /candidatos/{c}/etapa` | `candidatos.editar` | Atender, Entrevistar, Considerar, Rechazar (`Candidato::MANUALES`; `volver=recepcion` regresa al panel; «Entrevistar» abre la evaluación) |
| `POST /candidatos/{c}/evaluacion-rh` | `candidatos.editar` | Evaluación de RR. HH. (`criterios[clave]`, `resultado`, `comentario`, `entrevista_en`) |
| `POST /candidatos/{c}/canalizar` | `candidatos.editar` | Canalizar, segunda entrevista o reprogramar (`vacante_id`, `departamento_id`, `entrevistador_id`, `cuando`, `fecha`, `hora`, `lugar`, `avisar_candidato`) |
| `POST /candidatos/{c}/no-se-presento` | `candidatos.editar` | No se presentó (cita ya pasada) |
| `GET /entrevistas` | `candidatos.evaluar` | Mis entrevistas: por evaluar (con su cita) y ya evaluadas |
| `GET /entrevistas/{p}` · `POST /entrevistas/{p}` | `candidatos.evaluar` + asignado o delegado | Pantalla Entrevistar y su evaluación (`throttle:60,1`) |
| `GET /entrevistas/{p}/cv` | `candidatos.evaluar` + vacante con «El jefe puede ver el CV» | CV en PDF (disco privado) |
| `GET /entrevistas/{p}/correo` | `candidatos.evaluar` + firma vigente | Botón del correo: valida la firma y abre la entrevista |
| `GET /vacantes/{v}/candidatos` | `vacantes.ver` + (`candidatos.ver` o `candidatos.evaluar`) | Tabla «Candidatos de esta vacante» |
| `POST /candidatos/{c}/revisado` | `candidatos.editar` | Quita «Por revisar» |
| `POST /candidatos/{c}/contratar` | `candidatos.contratar` + `colaboradores.crear` | Alta como colaborador (fase 3: `forzar`, `motivo_forzar` si falta expediente) |
| `GET /candidatos/documentos-para-contratar` · `PUT …` · `PUT /candidatos/plazos` | `candidatos.configurar` (guardar: alcance de empresa) | Pantalla «Documentos y plazos» (fase 3) |
| `POST /candidatos/{c}/contrato` | `candidatos.editar` | Contrato firmado (`fecha`, `archivo`) desde Elegido |
| `POST /candidatos/{c}/documentos/papel` | `candidatos.editar` | Recibido en papel (`tipo`, `fecha`) |
| `POST /candidatos/{c}/conservar` | `candidatos.editar` | Conservar 6 meses más (caducidad) |
| `GET /colaboradores/{c}/expediente` · `GET …/expediente/{d}` | `colaboradores.ver` · `colaboradores.datos_personales` | Expediente del colaborador y sus documentos |
| `DELETE /candidatos/{c}` | `candidatos.eliminar` | Borra ficha y archivos (derechos ARCO) |
| `POST /candidatos/{c}/documentos` · `GET|DELETE …/documentos/{d}` | `editar` · `ver` | Documentos privados (PDF se descarga) |
| `POST /candidatos/{c}/enlace/revocar` | `candidatos.editar` | Anula el enlace del kiosco |
| `GET /k?codigo=` · `POST /k` · `GET|POST /k/{token}` | **sin sesión** | Kiosco. Limitadores con nombre (`AppServiceProvider::limitadoresPublicos`), cada uno con su contador porque en recepción todas las tabletas salen por la misma IP: `kiosco-ver` 120/min por IP + enlace, `kiosco-canjear` 10/min por IP, `kiosco-guardar` 10/min por IP + enlace y 60/min por IP. La bolsa pública usa `empleos-ver` (120/min) y `empleos-postular` (6/min) |

Otra empresa u otra sede fuera del alcance → **404**.

## Auditoría

`candidatos.creado`, `candidatos.actualizado`, `candidatos.etapa`, `candidatos.evaluado_rh` y `candidatos.evaluado_departamento` (registro `EvaluacionCandidato`, sin el comentario), `candidatos.canalizado`, `candidatos.reprogramado`, `candidatos.no_se_presento`, `candidatos.correo_candidato`, `candidatos.postulacion_creada` (registro `Postulacion`), `candidatos.visita_ligada` (la caseta ligó otra visita a su ficha), `candidatos.contratado`, `candidatos.eliminado`, `candidatos.documento_agregado`/`_eliminado`, `candidatos.enlace_kiosco`, `candidatos.enlace_revocado`, `candidatos.autocaptura` (sin usuario, con IP), `candidatos.configurado`, y `visitantes.creado` cuando RR. HH. da de alta a la persona en el padrón. Fase 3: `candidatos.documentos_configurados`, `candidatos.plazos_configurados` (registro `Empresa`), `candidatos.contrato_firmado`, `candidatos.documento_papel`, `candidatos.contratado` con `forzado`/`faltantes`/`motivo` cuando se forzó, y sin usuario: `candidatos.plazo`, `candidatos.archivada` (registro `Postulacion`), `candidatos.aviso_borrado`, `candidatos.conservada` (con usuario) y `candidatos.borrado_por_caducidad` (solo conteos, sede, etapa y origen). **La foto de auditoría no guarda el CV ni el contacto.** «Editar solicitud» (`actualizar()`) también deja el evento `solicitud_editada` en el historial con las partes que cambiaron, agrupadas y sin valores (`partesCambiadas()`: nombre, teléfono o correo, domicilio, datos oficiales, …) y «El candidato firmó en pantalla» si cambió la firma; guardar sin cambios no agrega renglón.

## Referencias registradas

`AdministradorColaboradores::REFERENCIAS['candidatos'] = 'colaborador_id'` y `AltasPorVerificar::REFERENCIAS['personas'][] = ['candidatos', 'persona_id']` (unir duplicados mueve al candidato).

## Qué se corrigió respecto a SEGCAT

SEGCAT no tenía candidatos: el guardia escribía «Recursos Humanos» como motivo y RR. HH. no se enteraba hasta que alguien llamaba por teléfono; el CV se pedía en papel; no había constancia del aviso de privacidad ni de los tiempos de espera.

## Lección 36: solicitud de empleo y vacantes

La ficha y el kiosco llevan la solicitud de empleo formal (datos oficiales, domicilio, referencias, declaración y firma, hoja impresa): ver [solicitud-empleo.md](solicitud-empleo.md). Origen nuevo `web` (bolsa de trabajo) y `vacante_id`: ver [vacantes.md](vacantes.md).
