# Avisos del navegador (fase 1: Notification API)

Petición del dueño: «si lo usamos desde navegador, que el navegador emita un aviso que informe al usuario aun cuando no esté en el portal pero sí con sesión iniciada».

**Fase 1 (esta entrega):** avisos del sistema operativo mientras exista **al menos una pestaña de la plataforma abierta con sesión**, aunque esté en segundo plano o minimizada. Sin servicios externos, sin service worker, sin procesos permanentes (hospedaje compartido).
**Fase 2 (pendiente):** Web Push, para avisar con el navegador cerrado. Ver al final.

## Piezas

| Pieza | Archivo |
|---|---|
| Datos del aviso | `CentroNotificaciones::paraLista()` agrega `urgente` (bool) a cada renglón del resumen; `CentroNotificaciones::URGENTES` = `autorizacion_visita`, `autorizacion_candidato`, `autorizacion_recepcion`, `autorizacion_escalada`. El resto (`id`, `titulo`, `texto`, `leida`, `abrir`) ya existía. |
| Consulta en segundo plano | `GET /notificaciones/resumen` con la cabecera `X-Segundo-Plano: 1`. `ControlarInactividad` **no** actualiza `ultima_actividad` con esa cabecera: la consulta automática no alarga la sesión. El cierre por inactividad sigue aplicando (devuelve 401 y la pestaña va al aviso de sesión). |
| Interfaz | `resources/views/componentes/avisos-navegador.blade.php` (estado + **Activar avisos en este navegador** / **Desactivar avisos en este navegador**), incluido al final del panel de la campana y en Mi cuenta (`#avisos-navegador`). Oculto hasta que el JS pinta el estado (sin JS no se ve). |
| Ícono | `data-aviso-icono` en `componentes/campana.blade.php`: `favicon` de Identidad o, si no hay, `simbolo`. Sin ninguno, el navegador usa su ícono. |
| JS | `public/js/plataforma.js`, bloque «Recepción de candidatos y autorizaciones departamentales», sección «Avisos del navegador». Sin JS en línea; la CSP (`CabecerasSeguridad`) no cambia: la Notification API no la regula, el ícono es `'self'` y la consulta es `connect-src 'self'`. |

## Comportamiento (plataforma.js)

- **Reloj de la campana:** 30 s con la pestaña a la vista (igual que antes). Oculta: 60 s **solo si los avisos están activos**; si no, se pausa como antes (no añade carga al servidor). Los navegadores espacian más los relojes de pestañas ocultas (Chrome: hasta 1 por minuto tras 5 min ocultas), por eso el aviso puede tardar ~1 min.
- **Una sola pestaña consulta en segundo plano:** `localStorage['plataforma_campana_consulta_<usuario>']` guarda la hora de la última consulta; una pestaña oculta no consulta si otra lo hizo hace menos de 45 s. El número de no leídas se comparte en `plataforma_campana_no_leidas_<usuario>` (evento `storage`) para el contador de la campana y del título de las demás pestañas.
- **Sin repetidos:** `plataforma_aviso_ultimo_<usuario>` = id más alto ya avisado. Se avisan solo las **no leídas** con id mayor. La primera consulta en un navegador solo fija la referencia (no avisa lo viejo). Además cada aviso usa `tag = plataforma-<id>`, así que el navegador reemplaza en vez de duplicar si dos pestañas coinciden.
- **Cuándo no avisa:** pestaña visible y con foco, actividad en cualquier pestaña de la plataforma en los últimos 10 s (`plataforma_ultima_actividad`, la misma clave del control de sesión), avisos desactivados o sin permiso. En esos casos solo avanza la referencia.
- **Más de 3 nuevas de golpe:** un solo aviso «Tienes N notificaciones nuevas» que lleva a la página de notificaciones.
- **Urgentes:** `requireInteraction: true` (no se cierran solos).
- **Al tocar el aviso:** `window.focus()`, cierra el aviso y envía el formulario POST a `abrir` (marca leída y redirige al asunto, mismo camino que la campana).
- **Título de la pestaña:** «(N) título» con la pestaña oculta y N > 0; se restaura al volver.
- **Permiso:** nunca al cargar. Solo con el clic en **Activar avisos en este navegador** (`Notification.requestPermission`, admite la forma de callback de navegadores viejos). Al concederse se guarda `localStorage['plataforma_avisos_navegador'] = 'si'` (por navegador, no por usuario) y se muestra el aviso de prueba «Avisos activos». **Desactivar** guarda `'no'`; el permiso del navegador se queda (lo quita la persona en el candado si quiere).
- **Estados mostrados:** activos · bloqueados (`denied`, con instrucción: candado → Notificaciones → Permitir → recargar) · no compatible.
- **No compatible:** sin `Notification`, contexto no seguro (http) o **celular/tableta** (Android, iPhone, iPad, incluido iPad en modo escritorio). En Android Chrome `new Notification()` lanza error (exige `ServiceWorkerRegistration.showNotification`) y en iOS solo hay avisos por Web Push en la app instalada; ambos casos son fase 2. El texto lo dice honestamente y remite a la campana.

## Verbos (criterios UX, regla 4)

«Activar» / «Desactivar» no están en la lista de diez verbos: el texto «Activar avisos en este navegador» lo pidió el dueño literalmente; «Desactivar» es su contrario. Excepción anotada aquí conforme al punto 4 de «Cómo se aplica».

## Pruebas

- `tests/Feature/Seguridad/AvisosNavegadorTest.php`: el resumen trae `id`, `texto`, `abrir`, `leida`, `urgente`; los tipos urgentes existen en `TIPOS`; con `X-Segundo-Plano: 1` no cambia `ultima_actividad` y sin ella sí; con la sesión vencida la consulta en segundo plano recibe 401 y cierra la sesión; la campana y Mi cuenta muestran el botón.
- Navegador (Playwright/Chromium, verificación manual): `Notification` simulada, pestaña oculta simulada y reloj adelantado: activar → aviso de prueba; con la pestaña a la vista no avisa; oculta → un aviso por notificación nueva (urgente con `requireInteraction`), sin repetidos, «(N)» en el título; tocar el aviso abre la notificación; desactivar detiene la consulta en segundo plano; sin errores de JS ni de CSP.

## Fase 2: Web Push (con el navegador cerrado) — no implementada

Lo que haría falta, compatible con hospedaje compartido sin `exec`/`proc_open`:

1. **Service worker** `public/sw.js` (archivo propio, sin JS en línea), registrado desde `plataforma.js` con alcance `/`. Maneja `push` (`self.registration.showNotification(titulo, {body, icon, tag, requireInteraction, data: {url}})`) y `notificationclick` (`clients.matchAll` → enfocar una pestaña existente o `clients.openWindow(url)`). La URL debe ser un GET (hoy `abrir` es POST): agregar `GET /notificaciones/{id}/ir` firmado o que marque leída al llegar.
2. **Claves VAPID** (par de claves P-256) generadas una vez con un comando artisan propio (en PHP puro, `openssl_pkey_new`), guardadas en `.env`/configuración; la pública se entrega al JS para `pushManager.subscribe({userVisibleOnly: true, applicationServerKey})`.
3. **Tabla `suscripciones_push`** (InnoDB, `empresa_id`, `user_id`, `endpoint` único, `p256dh`, `auth`, `navegador`, `ultimo_envio_en`, `creado_por`/`actualizado_por`, timestamps) con `down()`; rutas `POST/DELETE /notificaciones/suscripcion` (sin permiso de módulo, como la campana).
4. **Biblioteca PHP** de Web Push que cifre el mensaje (RFC 8291) y firme el JWT VAPID en proceso, p. ej. `minishlink/web-push` (usa `openssl` y Guzzle/cURL, sin `exec`). Requiere `composer require` en el equipo de integración (aquí no se toca `vendor`).
5. **Envío:** desde `CentroNotificaciones::avisar()` encolar el envío; como no hay procesos permanentes, usar la cola `database` despachada **en proceso** por la tarea programada existente (o `dispatchAfterResponse` para pocos destinatarios). Borrar suscripciones con respuesta 404/410.
6. **CSP:** `worker-src 'self'` en `CabecerasSeguridad`; `connect-src` no cambia (el navegador habla con su servicio de push, no la página).
7. **iPhone/iPad:** Web Push solo funciona con la plataforma **instalada en la pantalla de inicio** (iOS/iPadOS 16.4+), lo que exige además un `manifest.webmanifest` con `display: standalone`.
8. **Privacidad:** el texto del aviso viaja cifrado por el servicio de push del navegador (Google, Mozilla, Apple); conviene enviar solo el título y no datos personales en el cuerpo.
9. **Manual:** sustituir en Mi cuenta la pregunta «¿Me llegan avisos con el navegador cerrado?».

Relacionado: [recepcion-y-autorizaciones.md](recepcion-y-autorizaciones.md) (campana), [usuarios.md](usuarios.md) (Mi cuenta).
