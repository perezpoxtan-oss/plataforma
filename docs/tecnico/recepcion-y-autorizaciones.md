# Recepción de RR. HH., notificaciones y autorizaciones departamentales

Decisión: [ADR-0007](../decisiones/ADR-0007-recepcion-kiosco-y-autorizaciones.md). La ficha del candidato y el kiosco están en [candidatos.md](candidatos.md).

## Piezas

| Pieza | Archivo |
|---|---|
| Modelos | `Notificacion`, `Autorizacion`, `DepartamentoResponsable`, `Delegacion` (+ `Acceso::candidatoRecepcion()`, `autorizacionDepartamento()`) |
| Notificaciones | `app/Services/Notificaciones/CentroNotificaciones.php`, `NotificacionController`, `resources/views/componentes/campana.blade.php` (incluida en `layouts/app.blade.php`) |
| Recepción | `app/Services/Recepcion/` (`PanelRecepcion`, `RecepcionEnCaseta`, `AjustesRecepcion`, `Destinatarios`, `AvisosInicio`), `RecepcionController`, `resources/views/rh/recepcion/` |
| Autorizaciones | `app/Services/Autorizaciones/` (`Autorizaciones`, `Delegaciones`), `AutorizacionController`, `resources/views/rh/autorizaciones/` |
| Correo | `App\Mail\AvisoRecepcion` + `correos/recepcion.blade.php`, `AvisosCorreo::recepcion()` |
| JS / CSS | bloque «Recepción de candidatos…» al final de `public/js/plataforma.js`, `public/css/plataforma.css` y `public/css/modos-pantalla.css` |

## Tablas

- **`notificaciones`**: `empresa_id`, `user_id`, `tipo`, `titulo`, `texto`, `url`, `icono`, `nivel` (info | alerta | exito), `referencia_tipo`/`referencia_id`, `acciones` (JSON: `[{etiqueta, url, campos, estilo}]`), `leida_en`. Índice `(user_id, leida_en)`.
- **`autorizaciones`**: `sede_id`, `departamento_id`, `tipo` (visita | candidato), `acceso_id` o `candidato_id`, `estado` (pendiente → autorizada | entrevista | rechazada | cancelada), `solicitada_en`, `respondida_en`/`respondida_por`, `respuesta_medio` (plataforma | correo | caseta | rh), `comentario`, `avisados` (ids de usuario).
- **`departamento_responsables`**: `departamento_id`, `user_id` (único por departamento), `sede_id` (null = todas las sedes del departamento), `es_suplente`.
- **`delegaciones`**: `user_id` (responsable), `delegado_id`, `desde`, `hasta` (UTC; se capturan en hora local), `motivo`, `cancelada_en`/`cancelada_por`. Activa = sin cancelar y `desde ≤ ahora ≤ hasta`. Máximo 90 días; no se cruzan dos del mismo responsable.
- **`accesos`** (columnas nuevas): `foto_persona`, `foto_identificacion` (disco privado) y `autorizacion` (null | esperando | autorizada | rechazada).
- **`empresas.preferencias.recepcion`**: `visitas_requieren_autorizacion`, `aviso_privacidad`, `kiosco_horas`, `kiosco_usos`.

## Flujos

**Candidato en caseta.** Personal externo → Recursos Humanos → «Viene como candidato» (+ puesto, departamento, vacante). `RecepcionEnCaseta::preparar()` valida y `despues()` guarda fotos, crea la ficha (`AdministradorCandidatos::desdeAcceso`) y avisa a quien tiene `candidatos.editar` en esa sede (campana + correo `candidato_llegada`).

**Visita a departamento.** Motivo «Visita a Departamento» (o «Visita a Colaborador»: se usa el departamento del colaborador). Si `visitas_requieren_autorizacion` y el departamento tiene responsable en esa sede → el acceso nace PENDIENTE con `autorizacion = esperando`, se crea la `Autorizacion` y se avisa (campana con botones **Autorizar ingreso / Rechazar** y correo con botones firmados). La caseta ve «ESPERANDO AUTORIZACIÓN» y su tarjeta se actualiza sola (`GET /accesos/autorizaciones-estado?ids=` cada 15 s; con un diálogo abierto no recarga, avisa). Autorizada → EN SITIO (`autorizado_por` = quien respondió); rechazada → FINALIZADO «NO AUTORIZADO» (gafete libre); la caseta recibe aviso en su campana. Si la caseta confirma con su botón de siempre, la solicitud queda «respondida por caseta».

**Candidato al departamento.** RR. HH. «Aprobar y enviar al departamento» → `Autorizaciones::solicitarCandidato()`: resumen (puesto, escolaridad máxima, años de experiencia) a los responsables; ellos responden **Bajar a entrevistar** (candidato → Entrevista) o **Rechazar** (→ En cartera) y RR. HH. recibe aviso (`autorizacion_respuesta`). Sin responsable: queda anotado en el historial y RR. HH. lo pasa a entrevista.

**Responder.** Exige `autorizaciones.responder` y ser responsable (titular o suplente) de ese departamento en esa sede, o su delegado activo. El primero que responde gana (`UPDATE … WHERE estado = pendiente`); a los demás se les quitan los botones (`CentroNotificaciones::resolver`).

**Delegación.** «No molestar / delegar» en la bandeja: del responsable a otro usuario con `autorizaciones.responder`. Mientras está activa, `aQuienAvisar()` manda el aviso al delegado. Quien tiene `autorizaciones.configurar` delega por otros y cancela cualquiera. Se avisa al delegado y se audita.

## Tiempos (SLA) y métricas

Candidato: `llegada_en` (entrada en caseta) → `avisado_rh_en` → `revision_en` (RR. HH. lo atiende) → `aprobado_rh_en` → `enviado_departamento_en` → `respuesta_departamento_en` → `entrevista_en` → `decision_en` → `contratado_en`. Visita: `entrada_at` → `solicitada_en` → `respondida_en` → `autorizado_at`. `GET /rh/recepcion/metricas?desde=&hasta=&sede=` (fechas locales; por defecto los últimos 30 días): promedio y máximo de respuesta por departamento, por día, candidatos por etapa y promedios de caseta→aviso, caseta→atención, aprobado→respuesta y caseta→entrevista.

## Endpoints

| Método y ruta | Permiso | Qué hace |
|---|---|---|
| `GET /notificaciones?filtro=no_leidas` | (sesión; solo las propias) | Lista |
| `GET /notificaciones/resumen` | (sesión) | JSON de la campana |
| `POST /notificaciones/{n}/abrir` | (dueño) | Marca leída y lleva al asunto (solo direcciones de la plataforma) |
| `POST /notificaciones/leer-todas` | (sesión) | Marca todas |
| `GET /rh/recepcion` · `GET /rh/recepcion/datos` | `recepcion_rh.ver` | Panel y su JSON (HTML ya escapado + contadores) |
| `GET /rh/recepcion/metricas` | `recepcion_rh.ver` | Tiempos de espera |
| `GET /rh/recepcion/kiosco` · `POST /rh/recepcion/kiosco/{c}` | `candidatos.editar` o `accesos.crear` | Modo kiosco / generar QR |
| `GET|PUT /rh/recepcion/ajustes` | `candidatos.configurar` (empresa) · `autorizaciones.configurar` (interruptor de visitas) | Aviso de privacidad, kiosco, visitas con autorización |
| `GET /accesos/{a}/foto-persona` · `/foto-identificacion` | `accesos.ver` (su sede) · `candidatos.ver` (candidato de sus sedes) · `recepcion_rh.ver` y `autorizaciones.ver` (solo la de la persona) | Fotos privadas |
| `GET /accesos/autorizaciones-estado?ids=` | `accesos.ver` | Estado en vivo para la caseta |
| `GET /autorizaciones?estado=` | `autorizaciones.ver` | Bandeja «Por responder», delegaciones e historial |
| `GET /autorizaciones/{a}` | `autorizaciones.ver` + alcance | Detalle (resumen del candidato, sin contacto ni documentos) |
| `GET /autorizaciones/{a}/confirmar?respuesta=&signature=` | `autorizaciones.responder` + firma | Botón del correo: confirmar |
| `POST /autorizaciones/{a}/responder` | `autorizaciones.responder` + responsable/delegado | Autorizar / Rechazar / Bajar a entrevistar |
| `GET /autorizaciones/responsables` · `PUT …/responsables/{departamento}` | `autorizaciones.configurar` (empresa para guardar) | Responsables por departamento |
| `POST /autorizaciones/delegaciones` · `PATCH …/delegaciones/{d}/cancelar` | `autorizaciones.responder` | Delegación |

## Permisos y plantillas

Módulos nuevos en el área Recursos Humanos (menú Recursos Humanos → «Recepción y candidatos»): `recepcion_rh` (ver), `candidatos` (ver, crear, editar, eliminar, exportar, contratar, configurar), `autorizaciones` (ver, responder, configurar). Acciones nuevas del catálogo: `contratar`, `responder`. `RolesPlantillaSeeder::RECEPCION` hace las excepciones: Director (recepción y responder, sin CV), Jefe de seguridad y Supervisor (responder en su sede). En una base existente, la migración da los permisos a los roles con `colaboradores.crear` (todo) y con `accesos.aprobar` (ver y responder).

## Avisos por correo (Configuración)

`candidato_llegada`, `autorizacion_departamento`, `autorizacion_respuesta` (`Empresa::AVISOS`, encendidos por defecto).

## Qué se corrigió respecto a SEGCAT

- La caseta llamaba por teléfono para avisar; ahora el aviso llega solo (campana y correo) y la respuesta regresa a la caseta sin llamadas.
- No había forma de saber cuánto esperó una visita ni quién la autorizó: ahora cada paso tiene hora y responsable, con métricas.
- Sin websockets ni procesos en segundo plano: funciona en el hosting compartido.

## Ronda 8: «No molestar» no se delega en uno mismo

El diálogo ya no ofrece al propio usuario como delegado (quien tiene `autorizaciones.configurar` puede elegirse, marcado «(tú)», para delegar por otro titular) y el servidor lo rechaza antes que cualquier otra regla: «No puedes delegar en ti mismo: elige a otra persona que responda por ti.» (o «…en la misma persona que delega» cuando quien configura elige al titular).
