# Recepción de RR. HH., notificaciones y autorizaciones departamentales

Decisión: [ADR-0007](../decisiones/ADR-0007-recepcion-kiosco-y-autorizaciones.md). La ficha del candidato y el kiosco están en [candidatos.md](candidatos.md).

## Piezas

| Pieza | Archivo |
|---|---|
| Modelos | `Notificacion`, `Autorizacion`, `DepartamentoResponsable`, `Delegacion` (+ `Acceso::postulacion()`, `candidatoRecepcion()`, `autorizacionDepartamento()`) |
| Notificaciones | `app/Services/Notificaciones/CentroNotificaciones.php`, `NotificacionController`, `resources/views/componentes/campana.blade.php` (incluida en `layouts/app.blade.php`) |
| Recepción | `app/Services/Recepcion/` (`PanelRecepcion`, `RecepcionEnCaseta`, `AjustesRecepcion`, `Destinatarios`, `AvisosInicio`), `RecepcionController`, `resources/views/rh/recepcion/` |
| Autorizaciones | `app/Services/Autorizaciones/` (`Autorizaciones`, `Delegaciones`), `AutorizacionController`, `resources/views/rh/autorizaciones/` |
| Correo | `App\Mail\AvisoRecepcion` + `correos/recepcion.blade.php`, `AvisosCorreo::recepcion()` |
| JS / CSS | bloque «Recepción de candidatos…» al final de `public/js/plataforma.js`, `public/css/plataforma.css` y `public/css/modos-pantalla.css` |

## Tablas

- **`notificaciones`**: `empresa_id`, `user_id`, `tipo`, `titulo`, `texto`, `url`, `icono`, `nivel` (info | alerta | exito), `referencia_tipo`/`referencia_id`, `acciones` (JSON: `[{etiqueta, url, campos, estilo}]`), `leida_en`. Índice `(user_id, leida_en)`.
- **`autorizaciones`**: `sede_id`, `departamento_id` (null en las de tipo `recepcion`), `tipo` (visita | candidato | recepcion), `acceso_id` o `candidato_id`, `estado` (pendiente → autorizada | entrevista | rechazada | cancelada), `solicitada_en`, `respondida_en`/`respondida_por`, `respuesta_medio` (plataforma | correo | caseta | rh | sistema), `comentario`, `avisados` (ids de usuario). Las de tipo `candidato` son del proceso anterior a la fase 2 de candidatos: ya no se crean ni se responden (`RESPUESTAS['candidato'] = []`); las pendientes se cancelaron con medio `sistema` (ADR-0009).
- **`departamento_responsables`**: `departamento_id`, `user_id` (único por departamento), `sede_id` (null = todas las sedes del departamento), `es_suplente`.
- **`delegaciones`**: `user_id` (responsable), `delegado_id`, `desde`, `hasta` (UTC; se capturan en hora local), `motivo`, `cancelada_en`/`cancelada_por`. Activa = sin cancelar y `desde ≤ ahora ≤ hasta`. Máximo 90 días; no se cruzan dos del mismo responsable.
- **`accesos`** (columnas nuevas): `foto_persona`, `foto_identificacion` (disco privado), `autorizacion` (null | esperando | espera | autorizada | rechazada; `espera` = RR. HH. pidió que espere), `viene_a` (busca_empleo | entrevista | documentos | firma | informes | tramite; `Acceso::VIENE_A`) y `postulacion_id` (la postulación a la que se ligó la visita).
- **`empresas.preferencias.recepcion`**: `visitas_requieren_autorizacion`, `rh_autoriza_paso` (sin el dato = encendido; la migración de postulaciones lo dejó en `false` en las empresas que ya existían), `aviso_privacidad`, `kiosco_horas`, `kiosco_usos`.

## Flujos

**Recursos Humanos en caseta («¿A qué viene?», fase 1).** Personal externo → Recursos Humanos → `viene_a`: Busca empleo (+ «¿A qué vacante?»: vacante publicada y vigente de la sede o «No sabe / otra»; puesto y departamento salen de la vacante), Entrevista, Entrega de documentos, Firma de contrato, Informes / ver vacantes u Otro trámite. Sin `viene_a` (formularios anteriores) se toma `es_candidato` → busca_empleo, si no tramite. `RecepcionEnCaseta::preparar()` valida y `despues()` guarda fotos y `viene_a`; las cuatro primeras llaman a `AdministradorCandidatos::desdeAcceso()` (ficha única: ver [candidatos.md](candidatos.md)); entrevista/documentos/firma sin ficha encontrada se registran como busca_empleo con nota en el historial y en el aviso. Informes y trámite no crean ficha. La caseta solo ve «Candidato · Entrevista» (`_ficha-acceso`): nunca etapas ni CV.

**«Que pase» de RR. HH. (tipo `recepcion`).** Si `rh_autoriza_paso` y hay destinatarios (`Autorizaciones::destinatariosRecepcion()`: usuarios activos con `candidatos.editar` o `recepcion_rh.ver` que alcancen la sede, sin quien registró), el acceso nace PENDIENTE con `autorizacion = esperando` («ESPERANDO A RR. HH.») y `solicitarRecepcion()` crea la `Autorizacion` (sin departamento) y manda UN aviso (`autorizacion_recepcion`, «Llegó un candidato / Volvió a la caseta / En caseta para RR. HH.: …», con **Que pase / Que espere / No puede pasar**) y el correo con botones firmados (aviso por correo `candidato_llegada`). Sin destinatarios o con el ajuste apagado: pasa directo y se avisa como antes (`candidato_llegada`). Respuestas (`Autorizacion::RESPUESTAS['recepcion']`): `pase` → autorizada (EN SITIO, `autorizado_por`); `no_pasa` → rechazada (FINALIZADO, «NO AUTORIZADO por Recursos Humanos»); `espere` no cierra la solicitud: `accesos.autorizacion = espera`, la caseta ve «RR. HH. PIDE QUE ESPERE» y recibe aviso, y el aviso de RR. HH. conserva sus botones (`autorizaciones.espera` en la auditoría). La tarjeta de caseta compara con `data-estado-esperado` (`pendiente|esperando` o `pendiente|espera`) y se recarga al cambiar. «Confirmar Autorización» del supervisor funciona igual que con las visitas (`resueltaEnCaseta`, medio `caseta`).

**Visita a departamento.** Motivo «Visita a Departamento» (o «Visita a Colaborador»: se usa el departamento del colaborador). Si `visitas_requieren_autorizacion` y el departamento tiene responsable en esa sede → el acceso nace PENDIENTE con `autorizacion = esperando`, se crea la `Autorizacion` y se avisa (campana con botones **Autorizar ingreso / Rechazar** y correo con botones firmados). La caseta ve «ESPERANDO AUTORIZACIÓN» y su tarjeta se actualiza sola (`GET /accesos/autorizaciones-estado?ids=` cada 15 s; con un diálogo abierto no recarga, avisa). Autorizada → EN SITIO (`autorizado_por` = quien respondió); rechazada → FINALIZADO «NO AUTORIZADO» (gafete libre); la caseta recibe aviso en su campana. Si la caseta confirma con su botón de siempre, la solicitud queda «respondida por caseta».

**Candidato al departamento (fase 2).** Ya no pasa por esta tabla: RR. HH. evalúa su entrevista y **canaliza** al candidato con cita y entrevistador (`App\Services\Candidatos\Entrevistas`, ver [candidatos.md](candidatos.md) y ADR-0009). Se reutilizan los **responsables por departamento** (entrevistador por omisión), las **delegaciones** («No molestar»: el aviso le llega al delegado y puede evaluar), la campana y el correo con botón firmado. `autorizaciones.responder` sigue siendo el permiso de las **visitas**; entrevistar pide `candidatos.evaluar`.

**Responder.** Departamentales: exige `autorizaciones.responder` y ser responsable (titular o suplente) de ese departamento en esa sede, o su delegado activo. Recepción: `candidatos.editar` o `recepcion_rh.ver` con alcance en la sede (`atiendeRecepcion()`). `Autorizaciones::limitar()` deja ver las de recepción a quien atiende Recepción en sus sedes; la bandeja «Autorizaciones» no las lista (se atienden en Recepción). El primero que responde gana (`UPDATE … WHERE estado = pendiente`); a los demás se les quitan los botones (`CentroNotificaciones::resolver`).

**Delegación.** «No molestar / delegar» en la bandeja: del responsable a otro usuario con `autorizaciones.responder`. Mientras está activa, `aQuienAvisar()` manda el aviso al delegado. Quien tiene `autorizaciones.configurar` delega por otros y cancela cualquiera. Se avisa al delegado y se audita.

## Mis pendientes (fase 1)

Solo para quien puede `candidatos.editar` (en sus sedes): **Esperando en Recepción** (`PanelRecepcion::porAtender()`: accesos de RR. HH. con `autorizacion` esperando/espera + candidatos «Esperando» de hoy cuyo acceso no está entre esos) → Recepción; **Solicitudes por revisar** (`autocaptura_pendiente`) → `candidatos.index?revisar=1`; fase 2: **Por entrevistar (RR. HH.)** (revision + entrevista_rh) → `?etapa=por_entrevistar`, **Evaluaciones del departamento** (evaluado) → `?etapa=evaluado`, **Elegidos por contratar** (elegido) → `?etapa=elegido`. Quien entrevista (`candidatos.evaluar` y es o fue entrevistador o es responsable de un departamento): **Entrevistas por evaluar · próxima: …** (`Entrevistas::pendientes()`, propias y por delegación) → `entrevistas.index`. La campana trae el mismo total (`/notificaciones/resumen` → `pendientes`). El Inicio muestra además «N entrevistas esperan tu evaluación».

## Tiempos (SLA) y métricas

Candidato: `llegada_en` (entrada en caseta) → `avisado_rh_en` → `revision_en` (RR. HH. lo atiende) → `entrevista_rh_en` → `canalizado_en` → `evaluado_en` → `elegido_en` → `contratado_en` (las cuatro del medio viven en la postulación). Visita: `entrada_at` → `solicitada_en` → `respondida_en` → `autorizado_at`. `GET /rh/recepcion/metricas?desde=&hasta=&sede=` (fechas locales; por defecto los últimos 30 días): promedio y máximo de respuesta por departamento, por día, candidatos por etapa y promedios de caseta→aviso, caseta→atención, canalizado→evaluación del departamento y caseta→entrevista de RR. HH. Contadores del panel: esperando, revision (revision + entrevista_rh), departamento (canalizado) y evaluado.

## Endpoints

| Método y ruta | Permiso | Qué hace |
|---|---|---|
| `GET /notificaciones?filtro=no_leidas` | (sesión; solo las propias) | Lista |
| `GET /notificaciones/resumen` | (sesión) | JSON de la campana |
| `POST /notificaciones/{n}/abrir` | (dueño) | Marca leída y lleva al asunto (solo direcciones de la plataforma) |
| `POST /notificaciones/leer-todas` | (sesión) | Marca todas |
| `GET /rh/recepcion` · `GET /rh/recepcion/datos` | `recepcion_rh.ver` | Panel y su JSON (HTML ya escapado + contadores). Cada fila: «Viene a» (busca empleo — primera vez / ya vino antes, entrevista, documentos, firma, informes, trámite) y botones **Que pase** (si espera a RR. HH.), **Atender** y **Abrir ficha** |
| `GET /rh/recepcion/metricas` | `recepcion_rh.ver` | Tiempos de espera |
| `GET /rh/recepcion/kiosco` · `POST /rh/recepcion/kiosco/{c}` | `candidatos.editar` o `accesos.crear` | Modo kiosco / generar QR (`reusar=1`: muestra el vigente) |
| `GET|PUT /rh/recepcion/ajustes` | `candidatos.configurar` (empresa) · `autorizaciones.configurar` (interruptor de visitas) | Aviso de privacidad, «La caseta espera a que RR. HH. diga «Que pase»» (`rh_autoriza_paso`), kiosco, criterios de las entrevistas (`criterios[]`, hasta 8), visitas con autorización |
| `GET /accesos/{a}/foto-persona` · `/foto-identificacion` | `accesos.ver` (su sede) · `candidatos.ver` (candidato de sus sedes) · `recepcion_rh.ver` y `autorizaciones.ver` (solo la de la persona) | Fotos privadas |
| `GET /accesos/autorizaciones-estado?ids=` | `accesos.ver` | Estado en vivo para la caseta |
| `GET /autorizaciones?estado=` | `autorizaciones.ver` | Bandeja «Por responder», delegaciones e historial |
| `GET /autorizaciones/{a}` | `autorizaciones.ver` (o, las de recepción, `candidatos.editar`/`recepcion_rh.ver`) + alcance | Detalle (en las de candidato del proceso anterior, el resumen sin contacto ni documentos) |
| `GET /autorizaciones/{a}/confirmar?respuesta=&signature=` | `autorizaciones.responder` (o, recepción, `candidatos.editar`/`recepcion_rh.ver`) + firma | Botón del correo: confirmar |
| `POST /autorizaciones/{a}/responder` | igual + responsable/delegado o quien atiende Recepción en la sede | Autorizar / Rechazar / Que pase / Que espere / No puede pasar (`volver=recepcion` regresa al panel) |
| `GET /autorizaciones/responsables` · `PUT …/responsables/{departamento}` | `autorizaciones.configurar` (empresa para guardar) | Responsables por departamento |
| `POST /autorizaciones/delegaciones` · `PATCH …/delegaciones/{d}/cancelar` | `autorizaciones.responder` | Delegación |

## Permisos y plantillas

Módulos nuevos en el área Recursos Humanos (menú Recursos Humanos → «Recepción y candidatos»): `recepcion_rh` (ver), `candidatos` (ver, crear, editar, eliminar, exportar, contratar, configurar), `autorizaciones` (ver, responder, configurar). Acciones nuevas del catálogo: `contratar`, `responder`. `RolesPlantillaSeeder::RECEPCION` hace las excepciones: Director (recepción y responder, sin CV), Jefe de seguridad y Supervisor (responder en su sede). En una base existente, la migración da los permisos a los roles con `colaboradores.crear` (todo) y con `accesos.aprobar` (ver y responder).

## Avisos por correo (Configuración)

`candidato_llegada` (también el aviso con botones del «Que pase»), `autorizacion_departamento` (visitas), y desde la fase 2 `entrevista_departamento` (al entrevistador) y `evaluacion_departamento` (a RR. HH.), que reemplazó a `autorizacion_respuesta` (`Empresa::AVISOS`, encendidos por defecto). En las métricas, las de recepción se agrupan como «Recursos Humanos (caseta)».

## Qué se corrigió respecto a SEGCAT

- La caseta llamaba por teléfono para avisar; ahora el aviso llega solo (campana y correo) y la respuesta regresa a la caseta sin llamadas.
- No había forma de saber cuánto esperó una visita ni quién la autorizó: ahora cada paso tiene hora y responsable, con métricas.
- Sin websockets ni procesos en segundo plano: funciona en el hosting compartido.

## Ronda 8: «No molestar» no se delega en uno mismo

El diálogo ya no ofrece al propio usuario como delegado (quien tiene `autorizaciones.configurar` puede elegirse, marcado «(tú)», para delegar por otro titular) y el servidor lo rechaza antes que cualquier otra regla: «No puedes delegar en ti mismo: elige a otra persona que responda por ti.» (o «…en la misma persona que delega» cuando quien configura elige al titular).
