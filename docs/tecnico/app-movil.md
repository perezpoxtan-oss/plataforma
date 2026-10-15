# App móvil (Android) y plataforma instalable (PWA)

Etapa 1, sin pagos: la plataforma se puede **instalar desde el navegador** (PWA) y hay una **app de Android de prueba** que envuelve la plataforma web (Capacitor). iOS se agrega después (ver al final).

## Parte 1 — Plataforma instalable (PWA)

| Pieza | Archivo | Notas |
|---|---|---|
| Manifest | `GET /manifest.webmanifest` → `Publico\PwaController::manifest` | `application/manifest+json`. Nombre y color de **Identidad**; si Identidad conserva sus valores por omisión, «Smart Centinel» / «Centinel» (`App\Support\AppMovil::NOMBRE`, `NOMBRE_CORTO`). `display: standalone`, `orientation: any`, `start_url` y `scope` = `/`. |
| Íconos | `public/iconos/app-192.png`, `app-512.png` (any), `app-maskable-192.png`, `app-maskable-512.png` (maskable, escudo dentro de la zona segura del 80 %), `apple-touch-icon.png` (180) | Generados con la marca (escudo blanco sobre el azul de Identidad). Para cambiarlos basta con reemplazar los PNG con las mismas medidas. |
| Service worker | código en `public/js/sw.js`, servido en `GET /sw.js` → `PwaController::trabajador` | En la raíz para que su alcance sea `/`. `Cache-Control: no-cache` (el navegador siempre revisa si cambió), `Service-Worker-Allowed: /`. `__VERSION__` se sustituye por la huella del archivo: al cambiar el código, la caché vieja se borra sola. |
| Sin conexión | `GET /sin-conexion` → vista `publico/sin-conexion.blade.php` | Página propia sin datos de nadie; botón **Reintentar** = enlace a `/` (sin JS). |
| Registro | `public/js/pwa.js` (cargado con `defer` en `layouts/partes/cabeza.blade.php` y en la página pública de planes) | Sin JS en línea. No registra el service worker dentro de la app de Android. |
| Cabeceras HTML | `cabeza.blade.php` y `publico/planes/plantilla.blade.php` | `<link rel="manifest">`, `<link rel="apple-touch-icon">`; `theme-color` ya existía (color principal de Identidad). |

Las tres rutas van **sin el grupo `web`** (`Route::withoutMiddleware('web')` en `routes/web.php`): no abren sesión, no envían cookies, no pasan por el cierre por inactividad ni por «solo consulta». Sí pasan por los middleware globales (`VerificarHost`, `CabecerasSeguridad`). Son GET: la prueba «toda ruta que cambia datos exige CSRF» no se afecta.

### Qué guarda el service worker (y qué no)

- **Nunca** guarda pantallas (navegación), respuestas de formularios, consultas (`/notificaciones/…`, latido), descargas ni `/storage`. Todo lo que no es GET del propio sitio ni se toca.
- **Pantallas:** siempre a la red. Solo si la red falla muestra la copia de `/sin-conexion`.
- **Cascarón** (`/css/`, `/js/`, `/vendor/`, `/iconos/`): red primero; si no hay red, la copia guardada. Se guarda sin `?v=…` para no acumular copias.
- En caseta compartida no hay riesgo: las pantallas con sesión siguen con `Cache-Control: no-store` y el service worker no las conserva.

### CSP

`CabecerasSeguridad::CSP` **no cambia**: ya tenía `worker-src 'self' blob:`; `manifest-src` no se declara y hereda `default-src 'self'`. Ícono y manifest son del propio sitio.

### Verificado en navegador (Playwright/Chromium)

Manifest sin errores (`Page.getAppManifest`) y sin errores de instalabilidad; el service worker queda activo con alcance `/`; sin red, una pantalla muestra «Sin conexión»; la caché solo contiene `/sin-conexion`, íconos, css, js y fuentes; sin errores de JS ni de CSP. Con el user-agent de la app el service worker no se registra.

## Parte 2 — App de Android (carpeta `movil/`)

Capacitor **8.5.3** (estable), solo Android por ahora. La app abre la plataforma desde el servidor (`server.url`): no hay copia del código web dentro de la app, así que cada entrega de la plataforma se ve en la app sin publicar otra versión.

| Archivo | Para qué |
|---|---|
| `movil/app.config.json` | **El único lugar** con `appId`, `appName` y `servidor`. Lo leen `capacitor.config.js` y `android/app/build.gradle` (applicationId y nombre bajo el ícono). |
| `movil/capacitor.config.js` | `server.url` (de `servidor`), `cleartext: false`, `androidScheme: https`, `errorPath: sin-conexion.html`, `appendUserAgent: SmartCentinelApp/1`, `allowMixedContent: false`. |
| `movil/www/` | Solo la pantalla local «Sin conexión» de la app (`sin-conexion.html/.css/.js`, `icono.png`). |
| `movil/android/` | Proyecto de Android Studio (plantilla oficial de Capacitor 8.5.3 + ajustes). Va en el repositorio sin artefactos de compilación (`.gitignore`). |
| `android/app/src/main/java/mx/smartcentinel/app/MainActivity.java` | Botón «atrás», descargas, guardado de la sesión. |
| `android/app/src/main/java/mx/smartcentinel/app/AppNativaPlugin.java` | `imprimir()` y `reintentar()`, que la web llama por `Capacitor.Plugins.AppNativa`. |

### La dirección del servidor

`servidor` en `app.config.json` acepta `"qa"` (por omisión), `"produccion"` o una dirección completa `https://…`. `"qa"` y `"produccion"` se leen de `APP_URL` en `despliegue/env_qa.txt` y `despliegue/env_prod.txt`: la dirección **no se escribe a mano** en la app. La variable de entorno `SERVIDOR` manda sobre el archivo (útil para compilar una vez contra Producción sin cambiar nada). Una dirección que no empiece con `https://` detiene la compilación con un mensaje claro.

### Comportamiento

- **Navegación:** solo el propio dominio se abre dentro de la app; cualquier enlace a otro dominio (WhatsApp, mapas, páginas externas) se abre en el navegador del teléfono (comportamiento de Capacitor sin `allowNavigation`).
- **Botón «atrás» de Android:** regresa en la plataforma; en la primera pantalla pregunta «¿Salir de la app?» (**Salir** / **Cancelar**).
- **Sin internet:** pantalla local «Sin conexión» con **Reintentar** (vuelve a cargar la dirección del servidor).
- **Pantalla de arranque e ícono:** escudo de la marca sobre el azul de Identidad (`res/mipmap-*`, `res/drawable*/splash.png`, `values/ic_launcher_background.xml`). Ícono adaptable en Android 8+. Para cambiarlo: Android Studio → clic derecho en `res` → **New → Image Asset**.
- **Orientación libre** (vertical u horizontal).
- **La plataforma se sabe dentro de la app:** el user-agent termina en `SmartCentinelApp/1` (`App\Support\AppMovil::es()`; en JS, `pwa.js` agrega la clase `en-app` a `<html>`). Dentro de la app:
  - No se muestra «Activar avisos en este navegador» (ni en la campana ni el bloque de Mi cuenta): no aplica en el WebView y los avisos push de la app son el siguiente paso.
  - No se registra el service worker (la app tiene su propia pantalla sin conexión; además, un service worker interceptaría las pantallas antes de que Capacitor inyecte su puente).
- **Sesión:** la cookie de sesión es persistente (`expire_on_close = false`); la app la guarda en disco al pasar a segundo plano (`CookieManager.flush()`), así que no se pierde si Android cierra la app. **El cierre por inactividad no cambia**: lo deciden el servidor (`ControlarInactividad`) y `plataforma.js` igual que en el navegador (prueba `test_la_app_no_cambia_el_cierre_por_inactividad`).
- **Cámara:** permiso `CAMERA` declarado para el lector QR y las fotos; Android lo pregunta la primera vez.

### Lo que se comporta distinto dentro de la app

| Función | En la app | Motivo |
|---|---|---|
| Descargas (PDF, Excel) | Se bajan a **Descargas** del teléfono con la sesión de la app (DownloadManager con la cookie), con aviso «Descargando…» y notificación al terminar. | El WebView no descarga solo. Todas las exportaciones de la plataforma son GET, así que funcionan. |
| Descargas en Android 9 o anterior, o archivos creados en la página (`blob:`) | Se abren en el **navegador del sistema**; ahí puede pedir entrar otra vez (el navegador no comparte la sesión). | DownloadManager en Android ≤ 9 exige permiso de almacenamiento. |
| Ver un PDF en pantalla | Se descarga y se abre con el visor del teléfono. | El WebView no muestra PDF. |
| **Imprimir** | Abre el diálogo de impresión de Android (impresoras de la red o «Guardar como PDF»). | `window.print()` no hace nada en el WebView; `pwa.js` lo cambia por `AppNativa.imprimir()`. |
| Avisos del navegador | No se ofrecen (ver arriba). Las notificaciones siguen en la campana. | Siguiente paso: push (FCM). |
| Lector por cable (serial/HID) | No disponible. | Web Serial / WebHID no existen en el WebView. La cámara sí. |

### Preparado para el siguiente paso (sin activar)

- **Avisos push (FCM):** el archivo `google-services.json` de Firebase va en `movil/android/app/google-services.json`; está en `.gitignore` (**nunca** se sube al repositorio). `app/build.gradle` ya aplica el plugin de Google Services solo si el archivo existe, y el `classpath` está en `android/build.gradle`. Faltará: `npm install @capacitor/push-notifications`, `npx cap sync android`, registrar el token por usuario en la plataforma y enviar desde `CentroNotificaciones` (misma idea que la fase 2 de `avisos-navegador.md`).
- **NFC (gafetes) y biometría (entrar con huella):** permisos comentados en `AndroidManifest.xml`; se agregan junto con su plugin de Capacitor (p. ej. uno de NFC de la comunidad y `@aparajita/capacitor-biometric-auth`), y la web los usa por `Capacitor.Plugins` como hoy `AppNativa`.

### Excepciones de vocabulario (criterios UX, regla 4)

«Reintentar» (pantallas sin conexión) y «Salir» / «Cancelar» (diálogo nativo de Android) no están en los diez verbos: son textos de sistema fuera de las pantallas de la plataforma. Anotado aquí conforme al punto 4 de «Cómo se aplica».

## Guía paso a paso para el dueño (Windows o Mac)

### 1. Instala los programas (una sola vez)

1. **Git**: <https://git-scm.com/downloads> (en Mac ya viene; si no, el sistema lo ofrece al escribir `git` en Terminal).
2. **Node.js LTS** (versión 22 o más nueva): <https://nodejs.org> → botón **LTS**. Acepta todo lo que propone el instalador.
3. **Android Studio**: <https://developer.android.com/studio>. Al abrirlo la primera vez elige **Standard**: descarga el SDK de Android y Java (tarda; déjalo terminar).

Comprueba en una terminal (Windows: **PowerShell**; Mac: **Terminal**): `git --version` y `node --version` deben responder con un número.

### 2. Descarga el proyecto y prepara la app

```
git clone <dirección del repositorio>
cd <carpeta del repositorio>
git checkout develop
cd movil
npm install
npx cap sync android
```

`npm install` baja Capacitor (solo dentro de `movil/`); `npx cap sync android` deja lista la carpeta `android` con la configuración (dirección del servidor, nombre, appId). **Repite `npx cap sync android` cada vez que cambies `app.config.json`** o actualices el repositorio.

### 3. Abre el proyecto en Android Studio

`npx cap open android`, o en Android Studio: **Open** → elige la carpeta `movil/android`. Espera a que termine **Gradle sync** (barra de abajo; la primera vez tarda varios minutos).

### 4. Conecta tu celular

1. En el celular: **Ajustes → Acerca del teléfono** → toca 7 veces **Número de compilación** (se activan las «Opciones de desarrollador»).
2. **Ajustes → Sistema → Opciones de desarrollador** → activa **Depuración por USB**.
3. Conecta el cable USB; en el celular acepta **Permitir depuración USB** (marca «Permitir siempre desde esta computadora»).
4. En Android Studio, arriba, aparece el nombre de tu celular en la lista de dispositivos.

### 5. Instala y abre la app

Toca **▶ Run** (triángulo verde). La app se instala y se abre en el celular con la plataforma de la dirección de QA.

### 6. Genera un APK de prueba para compartir

**Build → Build App Bundle(s) / APK(s) → Build APK(s)**. Al terminar, toca **locate** en el aviso: el archivo es `movil/android/app/build/outputs/apk/debug/app-debug.apk`. Pásalo por WhatsApp, correo o USB; en el otro celular hay que permitir **Instalar apps desconocidas** para la app con la que se abre. Es una versión de prueba (firmada con la llave de depuración de tu computadora): no sirve para Google Play.

### 7. Cambiar la dirección (QA / Producción) o el appId

Edita `movil/app.config.json`:

```json
{ "appId": "mx.smartcentinel.app", "appName": "Smart Centinel", "servidor": "produccion" }
```

y ejecuta `npx cap sync android`. Para una sola compilación sin tocar el archivo: `SERVIDOR=produccion npx cap sync android` (Mac) o `$env:SERVIDOR="produccion"; npx cap sync android` (PowerShell).

- `appId` es el identificador de la app en Android y Google Play. **Se puede cambiar libremente mientras sea de prueba; una vez publicada en Google Play es permanente.** Al cambiarlo, el teléfono la trata como otra app (desinstala la anterior). El paquete interno del código (`mx.smartcentinel.app` en `java/`) no necesita cambiar.
- `appName` es el nombre bajo el ícono.

### Problemas comunes

| Síntoma | Qué hacer |
|---|---|
| «SDK location not found» / «Android SDK not found» | Android Studio → **Settings → Languages & Frameworks → Android SDK**: instala la versión que pide (API 36) y vuelve a abrir el proyecto. Si sigue, crea `movil/android/local.properties` con `sdk.dir=` y la ruta del SDK que muestra esa pantalla (en Windows con `\\`). |
| «JAVA_HOME is not set» o versión de Java incorrecta (se necesita 21) | Usa el Java que trae Android Studio: **Settings → Build, Execution, Deployment → Build Tools → Gradle → Gradle JDK** = «jbr-21» (Embedded JDK). Desde terminal: Windows `C:\Program Files\Android\Android Studio\jbr`; Mac `/Applications/Android Studio.app/Contents/jbr/Contents/Home`. |
| El celular no aparece o dice «unauthorized» | Desconecta y conecta; acepta el aviso en el celular; prueba otro cable (algunos solo cargan); en Windows instala el controlador USB del fabricante. En Opciones de desarrollador: **Revocar autorizaciones de depuración USB** y vuelve a conectar. |
| Gradle tarda mucho o falla al descargar | La primera vez descarga mucho; usa una red estable y espera. Si falla: **File → Invalidate Caches → Invalidate and Restart** y vuelve a hacer **Sync Project with Gradle Files**. |
| «Could not find … capacitor-android» o «capacitor-cordova-android-plugins» | Faltó `npm install` y `npx cap sync android` dentro de `movil/`. |
| La app abre en blanco o «Sin conexión» | El celular no tiene internet o la dirección de `app.config.json` está mal; revisa y repite `npx cap sync android`. |
| La dirección del servidor «debe empezar con https://» | La app no acepta conexiones sin cifrar. Usa la dirección `https://`. |

### Para publicar en Google Play (después)

1. **Cuenta de desarrollador de Google Play** (pago único). Las cuentas **personales** nuevas deben hacer una **prueba cerrada con al menos 12 testers durante 14 días** antes de publicar; las cuentas de **organización** no (requieren datos de la empresa, p. ej. número D-U-N-S).
2. **Firma:** crea una llave (keystore) en **Build → Generate Signed App Bundle or APK**. Guárdala junto con sus contraseñas **fuera del repositorio** y con respaldo: sin ella no se pueden publicar actualizaciones (Google Play App Signing ayuda a recuperarla). El `.gitignore` ya excluye `*.jks`, `*.keystore` y `keystore.properties`.
3. Google Play pide **AAB** (Android App Bundle), no APK: **Build → Generate Signed App Bundle**.
4. Antes de publicar: decidir el **appId definitivo**, subir `versionCode`/`versionName` en `android/app/build.gradle` en cada versión, ficha de la tienda (capturas, descripción, política de privacidad) y cuestionario de seguridad de datos.
5. Para Producción: `"servidor": "produccion"`.

### iOS (después)

Requiere una **Mac con Xcode** y cuenta de **Apple Developer** (pago anual). En la Mac: `cd movil && npm install @capacitor/ios && npx cap add ios && npx cap open ios`; los ajustes equivalentes (botón atrás no existe en iPhone; descargas e impresión usan sus propias APIs) se hacen en esa etapa. Apple revisa con más rigor las apps que solo envuelven un sitio: conviene llegar con funciones nativas (push, NFC, biometría).

## Pruebas

- `tests/Feature/Nucleo/AppMovilPwaTest.php`: manifest con su tipo, íconos con sus medidas, nombre y color de Identidad, sin cookies; service worker en la raíz con `no-cache`, `Service-Worker-Allowed`, versión sustituida, solo GET y sin guardar pantallas, sin cookies aun con sesión; «Sin conexión» sin sesión; CSP sin cambios; cabeceras HTML con manifest, ícono y `pwa.js` sin JS en línea; dentro de la app no se ofrecen los avisos del navegador; la app no cambia el cierre por inactividad.
- Navegador (Playwright): ver «Verificado en navegador».
- La app de Android no se compila en la integración continua (requiere el SDK de Android).
