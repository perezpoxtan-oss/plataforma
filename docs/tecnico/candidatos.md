# Candidatos (ficha / CV, etapas, contratar y kiosco)

Módulo nuevo (no existía en SEGCAT). Decisiones: [ADR-0007](../decisiones/ADR-0007-recepcion-kiosco-y-autorizaciones.md), [ADR-0008](../decisiones/ADR-0008-postulaciones-y-que-pase-de-rh.md) (postulaciones) y [ADR-0009](../decisiones/ADR-0009-entrevistas-canalizar-y-elegir.md) (fase 2: etapas nuevas, evaluaciones, canalizar y elegir). El panel de Recepción, las notificaciones y las autorizaciones están en [recepcion-y-autorizaciones.md](recepcion-y-autorizaciones.md).

## Piezas

| Pieza | Archivo |
|---|---|
| Migración | `database/migrations/2026_10_14_000200_crear_candidatos_y_autorizaciones.php`, `2026_10_19_000100_crear_postulaciones.php` (fase 1), `2026_10_20_000100_etapas_de_candidatos_fase_2.php`, `2026_10_20_000200_crear_evaluaciones_candidato.php`, `2026_10_20_000300_permiso_evaluar_candidatos.php`, `2026_10_20_000400_vacantes_jefe_ve_cv.php` (fase 2) |
| Modelos | `Candidato` (ficha), `Postulacion`, `EvaluacionCandidato`, `CandidatoDocumento`, `CandidatoEvento`, `EnlaceKiosco` |
| Reglas | `app/Services/Candidatos/AdministradorCandidatos.php` (alta, ficha única, CV, etapas manuales, contratar, eliminar), `Postulaciones.php` (postulación activa y espejo), `Entrevistas.php` (fase 2: evaluaciones, canalizar, reprogramar, no se presentó, Entrevistar, elegir y plazas, avisos), `DocumentosCandidato.php` (archivos privados), `Kiosco.php`, `CambioNoPermitido.php` |
| Controladores | `app/Http/Controllers/RecursosHumanos/CandidatoController.php`, `EntrevistaController.php` (pantalla Entrevistar), `KioscoController.php` (público) |
| Vistas | `resources/views/rh/candidatos/` (`index`, `show`, `_cv`, `_fila-*`, `_evaluacion`, `_evaluar-campos`), `resources/views/rh/entrevistas/` (`index`, `show`), `resources/views/correos/entrevista-candidato.blade.php`, `resources/views/kiosco/` |
| Pruebas | `tests/Feature/Seguridad/CandidatosYAutorizacionesTest.php`, `CandidatosFase1Test.php`, `CandidatosFase2Test.php` (con `AyudasEntrevistas`) |

## Tablas

### `candidatos`
`empresa_id`, `sede_id`, `persona_id` (Padrón de personas), `acceso_id` (registro en caseta), `departamento_id`, `puesto_id`, `vacante` (texto si no está en el catálogo), contacto (`nombre_completo`, `telefono`, `correo`, `fecha_nacimiento`, `ciudad`), CV (`escolaridad`, `experiencia`, `referencias` en JSON —máximo 6 renglones—, `habilidades`, `idiomas`, `disponibilidad`, `disponibilidad_notas`, `pretension`), `notas_rh`, `etapa`, `motivo_descarte`, `origen` (caseta | rh | kiosco), `autocaptura_pendiente`/`autocaptura_en`, privacidad (`privacidad_aceptada_en`, `privacidad_ip`, `privacidad_version` = SHA-256 del texto, `privacidad_medio`), tiempos (`llegada_en`, `avisado_rh_en`, `revision_en`, `aprobado_rh_en`, `enviado_departamento_en`, `respuesta_departamento_en`, `entrevista_en`, `decision_en`/`decision_por`, `contratado_en`), `colaborador_id`, auditoría.

### `postulaciones` (fases 1 y 2)
Cada vez que la persona aplica: `empresa_id`, `sede_id`, `candidato_id`, `vacante_id`, `departamento_id`, `puesto_id`, `vacante` (texto), `etapa`, `origen` (caseta | rh | kiosco | web), `motivo_descarte` (motivo de Considerar o Rechazar), fechas por etapa (`revision_en`, `entrevista_rh_en`, `canalizado_en`/`canalizado_por`, `evaluado_en`, `elegido_en`, `no_se_presento_en`, `decision_en`/`decision_por`, `contratado_en`; las del proceso anterior `aprobado_rh_en`, `entrevista_en`, `enviado_departamento_en`, `respuesta_departamento_en` quedan como historial), la cita con el departamento (`entrevistador_id`, `cita_en`, `cita_ahora`, `cita_lugar`, `numero_entrevista` = 1.ª, 2.ª…), `colaborador_id`, auditoría. Índices `(empresa_id, entrevistador_id, etapa)` y `(vacante_id, etapa)`. Una vacante tiene muchas postulaciones; una ficha, varias en el tiempo.

### `evaluaciones_candidato` (fase 2)
Una por entrevista evaluada: `empresa_id`, `sede_id`, `postulacion_id`, `candidato_id`, `tipo` (`rh` | `departamento`), `evaluador_id`, `entrevista_en`, `criterios` (JSON «nombre del criterio» → 1..5, con los criterios configurados ese día), `promedio` (2 decimales), `comentario` (obligatorio para considerar y rechazar), `resultado` (RR. HH.: `canalizar` | `considerar` | `rechazar`; departamento: `elegir` | `considerar` | `segunda_entrevista` | `rechazar`), `numero` (la entrevista 1.ª, 2.ª de la postulación), `creado_por`/`actualizado_por`, timestamps. Los criterios se configuran por empresa en `empresas.preferencias['recepcion']['criterios']` (hasta 8; sin el dato, los cinco de `AjustesRecepcion::CRITERIOS_DEFECTO`); en el formulario cada criterio va como `criterios[<clave>]` (`AjustesRecepcion::claveCriterio()`).

**Qué columna manda.** La postulación. `candidatos.etapa`, `sede_id`, `vacante_id`, `departamento_id`, `puesto_id`, `vacante`, `motivo_descarte`, las fechas de etapa y `colaborador_id` son un **espejo de la postulación activa** (la de mayor `id` de la ficha) para que listas, filtros, contadores, el panel de Recepción, las métricas y la exportación sigan igual. Todo cambio de etapa o de fechas se escribe primero en la postulación (`Postulaciones::cambiar()`, condicionado a la etapa esperada) y se refleja en la ficha (`reflejar()`, solo si es la activa). La única escritura en sentido contrario es cuando RR. HH. edita en la ficha a qué aplica (`desdeFicha()`: departamento, puesto, vacante). Fichas sin postulación (anteriores a la migración y no alcanzadas por ella) reciben una al primer cambio (`asegurar()`, que traduce etapas viejas con `Candidato::ETAPAS_ANTERIORES`). **Fase 2: el espejo se conserva** (ver ADR-0009); lo nuevo (entrevistador, cita, número de entrevista, fechas de canalización, evaluación y elección) no se copia: la ficha, Entrevistar, Mis pendientes, las métricas y la tabla de la vacante lo leen de la postulación.

**Una ficha por persona.** `AdministradorCandidatos::buscarFicha(persona_id, teléfono normalizado, CURP)` dentro de la empresa activa. Caseta: persona del padrón y el teléfono que tenga en el padrón; si viene a una cita (entrevista, documentos, firma), también nombre exacto (`fichaDeLaVisita()`). RR. HH. («Nuevo candidato», `crearOLigar()`): teléfono o CURP; si existe, no crea otra (solo completa campos vacíos) y abre una postulación si no tiene una en proceso; si la abierta es de una sede fuera de su alcance, error. Internet (`BolsaTrabajo::postular()`): teléfono o CURP; actualiza la ficha con lo que el candidato acaba de escribir y firmar (lo vacío no borra). En todos los casos: con postulación abierta se usa esa; sin ella se crea otra. Eventos del historial: «Volvió a la caseta: …», «Se volvió a postular por internet …».

### `candidato_documentos`
`tipo` (cv, ine, comprobante, otro), `nombre_original`, `ruta` (disco privado `candidatos/<empresa>/<candidato>/<uuid>.<ext>`), `mime`, `bytes`, `origen` (rh | kiosco).

### `candidato_eventos`
Historial: `evento`, `etapa_anterior`, `etapa_nueva`, `comentario`, `user_id` (null = el candidato en el kiosco).

### `enlaces_kiosco`
`token_hash` (SHA-256; el token nunca se guarda), `codigo` (6 caracteres sin 0/O ni 1/I/L), `expira_en`, `usos_maximos`, `usos`, `ultimo_uso_en`, `revocado_en`.

## Etapas (fase 2)

```
registrado (Esperando) ─Atender▶ revision ─Entrevistar▶ entrevista_rh ─(evaluación RR. HH. «canalizar»)─ Canalizar al departamento ▶ canalizado
canalizado ─(el entrevistador evalúa)▶ elegido (Elegir) | evaluado (Considerar · Segunda entrevista · Rechazar)
evaluado ─Segunda entrevista▶ canalizado (numero_entrevista + 1)      elegido ─Contratar▶ contratado
canalizado ─Reprogramar▶ canalizado      canalizado ─No se presentó (ya pasó la cita)▶ no_se_presento ─Reprogramar▶ canalizado
Laterales: considerar (comentario) y rechazado (motivo) desde casi todas; considerar ─Atender▶ revision; rechazado ─Atender▶ revision
```

| Etapa | Etiqueta | Siguientes (`Candidato::TRANSICIONES`) |
|---|---|---|
| `registrado` | Esperando | revision, considerar, rechazado |
| `revision` | En revisión RR. HH. | entrevista_rh, considerar, rechazado |
| `entrevista_rh` | Entrevista RR. HH. | canalizado, considerar, rechazado |
| `canalizado` | Entrevista con el departamento | canalizado (reprogramar), evaluado, elegido, no_se_presento, considerar, rechazado |
| `evaluado` | Evaluado por el departamento | canalizado (segunda entrevista), considerar, rechazado |
| `elegido` | Elegido | contratado, rechazado |
| `no_se_presento` | No se presentó | canalizado, considerar, rechazado |
| `considerar` | Considerar / cartera | revision, rechazado |
| `rechazado` | Rechazado | revision |
| `contratado` | Contratado | — |

`Candidato::ETAPAS`, `COLORES`, `RUTA` (avance de la ficha), `LATERALES`, `ABIERTAS` (en proceso: de registrado a elegido, más no_se_presento), `POR_ENTREVISTAR` (revision y entrevista_rh) y `MANUALES` (lo que acepta `PATCH /candidatos/{c}/etapa`: revision, entrevista_rh, considerar, rechazado; considerar y rechazado piden comentario). Lo demás tiene su formulario y su servicio:

- **Evaluación de RR. HH.** (`Entrevistas::evaluarRh`): desde revision o entrevista_rh (desde revision la pasa sola a entrevista_rh). Considerar → considerar y Rechazar → rechazado con el comentario como motivo, **sin avisar al departamento**. Canalizar deja lista la canalización (y abre su diálogo).
- **Canalizar al departamento** (`Entrevistas::canalizar`): desde entrevista_rh (exige la última evaluación de RR. HH. con «canalizar»), evaluado (segunda entrevista: `numero_entrevista + 1`), canalizado o no_se_presento (reprogramar). Pide vacante (publicada o en pausa, o la que ya tenía), departamento (activo en la sede), entrevistador (`Entrevistas::elegibles`: responsables del departamento en esa sede con `candidatos.evaluar`, y las demás personas activas con `candidatos.evaluar` en la sede que no atienden candidatos —`candidatos.editar`—), cuándo (`ahora` o fecha y hora locales, no en el pasado ni a más de 90 días), lugar o notas y la casilla «Avisar al candidato por correo». Avisos: ver abajo.
- **No se presentó** (`Entrevistas::noSePresento`): solo canalizado con la cita ya pasada (o «ahora»).
- **Evaluación del departamento** (`Entrevistas::evaluarDepartamento`): solo canalizado y solo el entrevistador asignado o su delegado activo (`Delegacion::activas()`, el mismo «No molestar» de las autorizaciones) con `candidatos.evaluar` en la sede. Elegir → elegido; lo demás → evaluado (Recursos Humanos cierra el contacto).
- **Plazas** (`Entrevistas::cubrirPlazas`): al elegir, si elegidos + contratados de la vacante ≥ `vacantes.plazas` (por omisión 1), las demás postulaciones de esa vacante en canalizado o evaluado pasan a considerar con «Se eligió a otra persona para esta vacante», se cancela su cita y se avisa a RR. HH. («Vacante cubierta»).

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

## Endpoints

| Método y ruta | Permiso | Qué hace |
|---|---|---|
| `GET /candidatos?q=&etapa=&sede=&departamento=&vacante=&revisar=1` | `candidatos.ver` | Lista (una tarjeta por ficha) con conteo por etapa; `etapa=en_proceso` y `etapa=por_entrevistar` (revision + entrevista_rh); `vacante` busca en todas sus postulaciones; `revisar=1` = «Solicitudes por revisar» (`autocaptura_pendiente`) |
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
| `POST /candidatos/{c}/contratar` | `candidatos.contratar` + `colaboradores.crear` | Alta como colaborador |
| `DELETE /candidatos/{c}` | `candidatos.eliminar` | Borra ficha y archivos (derechos ARCO) |
| `POST /candidatos/{c}/documentos` · `GET|DELETE …/documentos/{d}` | `editar` · `ver` | Documentos privados (PDF se descarga) |
| `POST /candidatos/{c}/enlace/revocar` | `candidatos.editar` | Anula el enlace del kiosco |
| `GET /k?codigo=` · `POST /k` · `GET|POST /k/{token}` | **sin sesión** | Kiosco. Limitadores con nombre (`AppServiceProvider::limitadoresPublicos`), cada uno con su contador porque en recepción todas las tabletas salen por la misma IP: `kiosco-ver` 120/min por IP + enlace, `kiosco-canjear` 10/min por IP, `kiosco-guardar` 10/min por IP + enlace y 60/min por IP. La bolsa pública usa `empleos-ver` (120/min) y `empleos-postular` (6/min) |

Otra empresa u otra sede fuera del alcance → **404**.

## Auditoría

`candidatos.creado`, `candidatos.actualizado`, `candidatos.etapa`, `candidatos.evaluado_rh` y `candidatos.evaluado_departamento` (registro `EvaluacionCandidato`, sin el comentario), `candidatos.canalizado`, `candidatos.reprogramado`, `candidatos.no_se_presento`, `candidatos.correo_candidato`, `candidatos.postulacion_creada` (registro `Postulacion`), `candidatos.visita_ligada` (la caseta ligó otra visita a su ficha), `candidatos.contratado`, `candidatos.eliminado`, `candidatos.documento_agregado`/`_eliminado`, `candidatos.enlace_kiosco`, `candidatos.enlace_revocado`, `candidatos.autocaptura` (sin usuario, con IP), `candidatos.configurado`, y `visitantes.creado` cuando RR. HH. da de alta a la persona en el padrón. **La foto de auditoría no guarda el CV ni el contacto.**

## Referencias registradas

`AdministradorColaboradores::REFERENCIAS['candidatos'] = 'colaborador_id'` y `AltasPorVerificar::REFERENCIAS['personas'][] = ['candidatos', 'persona_id']` (unir duplicados mueve al candidato).

## Qué se corrigió respecto a SEGCAT

SEGCAT no tenía candidatos: el guardia escribía «Recursos Humanos» como motivo y RR. HH. no se enteraba hasta que alguien llamaba por teléfono; el CV se pedía en papel; no había constancia del aviso de privacidad ni de los tiempos de espera.

## Lección 36: solicitud de empleo y vacantes

La ficha y el kiosco llevan la solicitud de empleo formal (datos oficiales, domicilio, referencias, declaración y firma, hoja impresa): ver [solicitud-empleo.md](solicitud-empleo.md). Origen nuevo `web` (bolsa de trabajo) y `vacante_id`: ver [vacantes.md](vacantes.md).
