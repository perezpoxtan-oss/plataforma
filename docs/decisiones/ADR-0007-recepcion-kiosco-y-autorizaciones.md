# ADR-0007 — Recepción de candidatos, kiosco y autorizaciones departamentales

**Fecha:** 2026-10-06 · **Estado:** aceptada

## Contexto

El dueño del proyecto pidió dos módulos nuevos que SEGCAT no tenía (áreas Recursos Humanos y Seguridad):

- **Módulo 2 — Recepción y control de acceso (Seguridad → RR. HH.):** que Recursos Humanos vea en (casi) tiempo real a quien la caseta registró y viene con RR. HH., que se le avise en el momento en que llega un candidato, una ficha / CV digital con etapas hasta «Contratar», un kiosco para que el candidato llene su CV en su celular, fotos de evidencia en caseta y un aviso de privacidad digital.
- **Módulo 3 — Notificaciones y autorizaciones departamentales:** responsables por departamento, visitas que esperan la autorización de su responsable, el filtro de RR. HH. y luego del departamento para los candidatos, notificaciones con botones de acción, tiempos (SLA) y delegación («No molestar»).

Restricciones: hosting compartido Neubox (sin websockets, sin procesos en segundo plano), CSP estricta (sin JavaScript en línea), multiempresa con alcance por sede y datos personales (el CV) que solo deben ver Recursos Humanos y, en resumen, el responsable del departamento.

## Decisión

1. **Un solo flujo.** La Bitácora de accesos sigue siendo la entrada: «Personal externo → Recursos Humanos → *Viene como candidato*» crea la ficha del candidato (`candidatos`) ligada al acceso y al Padrón de personas (categoría «Prospecto de RR. HH.»). «Personal externo → *Visita a Departamento*» (o a un colaborador) puede esperar autorización. La integración vive en `App\Services\Recepcion\RecepcionEnCaseta` para no mezclarla con las reglas de SEGCAT de `RegistroAccesos` (tres líneas delimitadas).
2. **Centro de notificaciones propio** (`notificaciones`, por usuario y empresa) en lugar de las notificaciones de Laravel: la campana pide un JSON pequeño cada 30 s (15 s en el panel de Recepción y en la caseta) y se pausa con la pestaña oculta. Sin websockets ni colas: funciona en hosting compartido. Las notificaciones pueden traer **botones** (formularios POST al módulo, que vuelve a revisar permiso y alcance). Cuando alguien responde, `resolver()` quita los botones a todos.
3. **Correo con botones firmados.** Los botones del correo son direcciones `URL::temporarySignedRoute` (24 h) que **piden iniciar sesión y confirmar**: nunca responden con un solo clic desde el correo (un escáner de correo o un reenvío no puede autorizar a nadie). La firma se revisa después de comprobar empresa y alcance (otra empresa → 404, no 403).
4. **Etapas del candidato con transiciones explícitas** (`Candidato::TRANSICIONES`) y cada cambio condicionado a la etapa esperada (doble clic o dos personas: el segundo recibe aviso). «Contratar» solo desde «Seleccionado» y reutiliza `AdministradorColaboradores::crear()` (mismas validaciones de Colaboradores). La respuesta del departamento mueve «Aprobado por RR. HH.» a «Entrevista» o a «En cartera»; si RR. HH. decide otra cosa antes, la solicitud se cancela.
5. **Kiosco sin sesión** con enlace temporal de **un solo candidato**: token aleatorio de 48 caracteres (en la base solo su SHA-256), vence en 2 h y se puede **enviar** 3 veces (configurable por empresa), un enlace vigente por candidato, código corto de 6 caracteres para teclear, límite de peticiones por equipo (10/min al enviar o canjear código), `noindex`, sin JavaScript en línea y con token CSRF. Es la **única excepción** a «toda ruta exige sesión» (la prueba `SuperficieDeRutasTest` la lista explícitamente). La tableta de la sala de espera («Modo kiosco») sí tiene sesión y muestra el QR (que lleva solo el código, no el token).
6. **Aviso de privacidad configurable** por empresa (`empresas.preferencias.recepcion`). El texto por defecto está marcado «Borrador — validar con su abogado»: la plataforma **no** redacta un texto legal definitivo. Cada aceptación guarda fecha, IP, medio (kiosco o RR. HH.) y la huella SHA-256 del texto aceptado.
7. **Responsables por departamento** en una tabla propia (`departamento_responsables`: titular y suplentes, todas las sedes o una) y **delegaciones** con fecha y hora de inicio y fin (`delegaciones`). Mientras una delegación está activa, el aviso va al delegado y él puede responder. Responder exige el permiso `autorizaciones.responder` **y** ser responsable (o delegado) de ese departamento en esa sede: el permiso dice *qué* puede hacer; la tabla, *de qué departamento*.
8. **Visitas que esperan autorización**: interruptor por empresa (apagado por defecto). Si está encendido y el departamento tiene responsable, el acceso nace PENDIENTE con `accesos.autorizacion = esperando`. Autorizada → EN SITIO; rechazada → FINALIZADO sin ingresar (el gafete queda libre). La caseta puede confirmar por su cuenta con el botón de siempre (la solicitud queda «respondida por caseta»).
9. **Fotos y documentos privados**: disco `local`, nombres aleatorios, imágenes re-dibujadas con `ImagenSegura` (sin EXIF ni código pegado) y reducidas a 1280 px; PDF revisado por su firma `%PDF-`; se entregan solo por el controlador con permiso y alcance.
10. **Permisos nuevos** (área Recursos Humanos): `recepcion_rh.ver`; `candidatos.ver|crear|editar|eliminar|exportar|contratar|configurar`; `autorizaciones.ver|responder|configurar`. Plantillas: Recursos Humanos todo; Director ve Recepción y métricas y responde, **sin** ver los CV; Jefe de seguridad y Supervisor responden en su sede; el Agente registra candidatos y visitas y toma fotos desde Accesos, sin ver los CV.

## Consecuencias

- Hay una ruta pública más (`/k`, `/k/{token}`). Se aceptó con las protecciones del punto 5.
- El sondeo cada 15–30 s agrega peticiones ligeras (un `COUNT` indexado). El cierre por inactividad lo sigue decidiendo el navegador (el sondeo no cuenta como actividad del usuario en el reloj del navegador).
- El CV nunca va a la bitácora de auditoría: se audita etapa, sede, departamento y puesto.
- Pendiente para una versión futura: borrado automático de candidatos «Descartados» después de N meses (política de conservación de datos personales) y un recordatorio si una autorización lleva más de X minutos sin respuesta (requiere tarea programada).
