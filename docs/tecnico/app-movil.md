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
| `android/app/src/main/java/mx/smartcentinel/app/LectorNfcPlugin.java` | NFC del teléfono (ver «NFC en la app»): modo lector en primer plano, evento `lectura`, `disponible()`, `abrirAjustesNfc()`. |
| `public/js/nfc-app.js` (en la plataforma) | `NDEFReader` mínimo dentro de la app sobre `Capacitor.Plugins.LectorNfc`. Solo se incluye con el user-agent de la app. |

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
| **NFC** (botón de la antena del lector universal) | Lo lee la app (plugin nativo `LectorNfc`), sin permisos del navegador. Con la app abierta ninguna etiqueta sale de la app. | Web NFC (`NDEFReader`) no funciona en el WebView. Ver «NFC en la app». |

### Preparado para el siguiente paso (sin activar)

- **Avisos push (FCM):** el archivo `google-services.json` de Firebase va en `movil/android/app/google-services.json`; está en `.gitignore` (**nunca** se sube al repositorio). `app/build.gradle` ya aplica el plugin de Google Services solo si el archivo existe, y el `classpath` está en `android/build.gradle`. Faltará: `npm install @capacitor/push-notifications`, `npx cap sync android`, registrar el token por usuario en la plataforma y enviar desde `CentroNotificaciones` (misma idea que la fase 2 de `avisos-navegador.md`).
- **Biometría (entrar con huella):** permiso comentado en `AndroidManifest.xml`; se agrega junto con su plugin de Capacitor (p. ej. `@aparajita/capacitor-biometric-auth`), y la web lo usa por `Capacitor.Plugins` como hoy `AppNativa`. (El NFC ya está activo: ver «NFC en la app».)
- **App Links verificados** para las etiquetas `/e/…`: listos del lado de la app y del servidor; se encienden con la huella de la llave de firma (ver «NFC en la app → App Links»).

## NFC en la app

**Problema que resuelve:** en el teléfono con la app instalada, al acercar una etiqueta NFC con una dirección de la plataforma (`https://…/e/…`), Android la abría por su cuenta en el navegador y sacaba a la persona de la app; el botón de la antena decía «No se pudo activar el NFC…». Causa: el lector universal (`plataforma.js`, «NFC del celular») usa **Web NFC** (`NDEFReader`), que el WebView de Android **no** ofrece; y sin un lector en primer plano el sistema despacha la etiqueta a otra app.

### Cómo funciona

| Situación | Qué pasa | Pieza |
|---|---|---|
| **App abierta** (cualquier pantalla) | El NFC queda en **modo lector** (`NfcAdapter.enableReaderMode` en `handleOnResume`, se apaga en `handleOnPause`; banderas NFC-A/B/F/V, **sin** `FLAG_READER_SKIP_NDEF_CHECK` para leer el mensaje NDEF). **Ninguna** etiqueta abre el navegador ni otra app. | `LectorNfcPlugin.java` |
| App abierta, **lector esperando** (se tocó la antena) | La lectura llega al lector universal exactamente como con Web NFC: busca el registro por la dirección/texto o, al asignar una etiqueta, guarda el número de serie. | `nfc-app.js` → `plataforma.js` (sin cambios en su lógica) |
| App abierta, **nadie leyendo** | Si la etiqueta trae una dirección **del mismo origen** que la página, se abre dentro de la app (`location.assign`) → acción probable o ficha (`LectorController::ir`). Otras direcciones o etiquetas sin dirección se ignoran. | `nfc-app.js` |
| **App cerrada o en segundo plano** | Android entrega la etiqueta a la app por el `intent-filter` `NDEF_DISCOVERED` (https + host del servidor + `pathPrefix /e/`). `MainActivity.onNewIntent` (también en el arranque: Capacitor lo llama desde `load()`) carga esa dirección en el WebView **solo si es del propio servidor** (esquema https, mismo host y puerto que `server.url`). Sin sesión, la plataforma pide entrar y regresa a esa dirección. | `AndroidManifest.xml`, `MainActivity.java` |
| Teléfono **bloqueado** o NFC apagado | Android no lee etiquetas. | — |

Detalles:
- **Evento al JS:** `lectura` con `serialNumber` (id de la etiqueta en hex minúsculas separado por «:», como Web NFC) y `records[]` con `recordType` (`url`, `absolute-url`, `text`, `mime`, `smart-poster`, `empty`, tipo externo o `unknown`), `mediaType` (solo `mime`), `encoding` y `lang` (solo `text`), `datos` (base64) y `texto` (decodificado). La URI se entrega **completa con su prefijo** (`NdefRecord.toUri()`); el texto se entrega ya decodificado y **siempre en UTF-8** (aunque la etiqueta esté en UTF-16), con su idioma en `lang`. Etiquetas sin NDEF (gafetes Mifare, por ejemplo) llegan con `records` vacío: el lector usa el número de serie. Si la etiqueta se aleja a media lectura: evento `lecturaError` → `onreadingerror`.
- Se lee primero el mensaje que Android ya trae en caché (`Ndef.getCachedNdefMessage()`); si no hay, `connect()` + `getNdefMessage()` en el hilo del lector (nunca en el de la pantalla). Los avisos a la página salen en el hilo de la pantalla.
- **Métodos JS** (`Capacitor.Plugins.LectorNfc`): `disponible()` → `{ nfc, encendido }`; `abrirAjustesNfc()` (ajustes de NFC; si el teléfono no los tiene, los de conexiones inalámbricas).
- **Polyfill** (`public/js/nfc-app.js`): define `window.NDEFReader` dentro de la app (aunque el WebView declare uno que no funciona). `scan({signal})` resuelve si hay NFC encendido; si no, rechaza con `NotAllowedError` «Enciende el NFC del teléfono.» (`codigo: 'nfc-apagado'`), `NotSupportedError` «Este teléfono no tiene NFC.» (`sin-nfc`) o «Actualiza la app para leer etiquetas NFC.» (`sin-plugin`, una app instalada antes de este cambio). `onreading` / `onreadingerror` / `addEventListener('reading')` con `{serialNumber, message: {records: [{recordType, mediaType, data (DataView), encoding, lang}]}}`. El `abort()` de la señal deja de entregar lecturas a ese lector.
  - Se incluye en `layouts/partes/cabeza.blade.php` **solo** cuando `AppMovil::es()` y **sin `defer`**: debe existir antes de que `plataforma.js` revise `'NDEFReader' in window`. Archivo propio, sin JS en línea: la CSP no cambia.
  - En `plataforma.js` solo cambió el `catch` de `leerNfc`: si el error trae `codigo`, se muestra su mensaje; con `nfc-apagado`, «Enciende el NFC del teléfono y vuelve a tocar el botón de NFC.» con el botón **Abrir ajustes de NFC**. En el navegador el mensaje genérico no cambia.
- **Grabar etiquetas (`write()`): decisión — no se implementa.** La plataforma no graba etiquetas en ninguna pantalla (el lector solo usa `scan()`), y escribir NDEF desde la app (con `NdefFormatable`) arriesga dejar etiquetas a medio formatear. `write()` y `makeReadOnly()` rechazan con `NotSupportedError` «Para grabar etiquetas usa una app de grabación de NFC: esta app solo las lee.». Si algún día se necesita, se agrega `escribir()` al plugin con `Ndef.writeNdefMessage`.
- **Efecto secundario:** mientras la app está en primer plano el modo lector también apaga el pago con el teléfono (emulación de tarjeta). Al salir de la app vuelve.

### La dirección de las etiquetas en el manifest

El `intent-filter` usa los marcadores `${servidorHost}` y `${servidorRutaEtiquetas}`. `app/build.gradle` los calcula de `server.url` leyendo `src/main/assets/capacitor.config.json`, el archivo que **`npx cap sync android` escribe** con la dirección que resolvió `capacitor.config.js` (desde `app.config.json` / `SERVIDOR`). Es decir: **la misma dirección de la app, sin escribirla dos veces**. Si la dirección tuviera una carpeta (`https://dominio/plataforma`), la ruta queda `/plataforma/e/`. Sin ese archivo (no se hizo el `sync`) la app compila con un host de relleno (`sin-configurar.invalid`) y avisa en Gradle: las etiquetas con la app cerrada abrirían el navegador. Cada **Run** vuelve a leer el archivo; después de cambiar `servidor` basta `npx cap sync android` y **Run**.

### App Links (preparado, apagado)

La app también declara un `intent-filter` `VIEW` con `android:autoVerify="true"` para `https://<host>/e/…`. Android solo lo considera **verificado** (abre la app sin preguntar al tocar un enlace `/e/…` en WhatsApp, correo, la cámara…) si el servidor publica `/.well-known/assetlinks.json` con la huella SHA-256 de la llave con la que se firmó la app.

- Ruta `GET /.well-known/assetlinks.json` → `PwaController::assetlinks` (sin el grupo `web`: sin sesión ni cookies). `config/app_movil.php`: `APP_MOVIL_PAQUETE` (por omisión `mx.smartcentinel.app`, el `appId`) y `APP_MOVIL_HUELLAS_SHA256` (**vacía por omisión → 404**; varias separadas por coma; con o sin «:»; las que no miden 32 bytes se ignoran).
- `public/.htaccess` ya deja pasar `/.well-known/` (no fuerza https para la validación del certificado y no lo bloquea como archivo oculto); como el archivo no existe en disco, lo atiende Laravel.
- **Cuándo encenderlo:** al tener la llave de firma de publicación. Con Google Play App Signing la huella está en Play Console → **Prueba y lanzamiento → Configuración → Integridad de la app → Firma de apps** («Certificado de la clave de firma de apps», SHA-256). Para la app de prueba (llave de depuración de la computadora): `keytool -list -v -keystore %USERPROFILE%\.android\debug.keystore -alias androiddebugkey -storepass android -keypass android` (SHA256). Escríbela en el `.env` del servidor (`APP_MOVIL_HUELLAS_SHA256=AA:BB:…`), limpia la caché de configuración y reinstala la app (Android verifica al instalar). Revisar: `adb shell pm get-app-links mx.smartcentinel.app` debe decir `verified`.
- Mientras no esté verificado: en Android 12+ los enlaces `/e/…` siguen abriendo el navegador (la persona puede activarlo a mano en **Ajustes → Apps → Smart Centinel → Abrir de forma predeterminada**); en Android 11 o anterior, Android puede preguntar con qué abrirlo. **Las etiquetas NFC no dependen de esto** (usan `NDEF_DISCOVERED`).

### Samsung (y otros teléfonos)

- El NFC debe estar encendido: **Ajustes → Conexiones → NFC y pagos sin contacto** (en Samsung es un solo interruptor que enciende el NFC; «Pagos sin contacto» dentro de él es solo para pagar y no afecta la lectura).
- La pantalla debe estar **desbloqueada**: Android no lee etiquetas con el teléfono bloqueado.
- La antena está a la mitad de la parte trasera (cerca de la cámara en la mayoría); fundas gruesas o con metal estorban.

### Cómo probarlo

1. En Android Studio: `npx cap sync android`, **Run** en el teléfono.
2. **App abierta:** en **Préstamo de llaves → Prestar llave**, toca la antena del campo de la llave y acerca la etiqueta (o el gafete): se elige el registro y no se abre el navegador.
3. **App abierta sin tocar la antena:** acerca una etiqueta con dirección `/e/…`: se abre la acción o la ficha dentro de la app.
4. **NFC apagado:** apágalo, toca la antena: «Enciende el NFC del teléfono…» con **Abrir ajustes de NFC** → abre los ajustes.
5. **App cerrada:** cierra la app (deslízala desde Recientes), acerca la etiqueta con el teléfono desbloqueado: se abre la app en esa dirección (no el navegador).
6. Para ver lo que llega: **Logcat** filtrado por `LectorNfc` y `Capacitor`; en Chrome de la computadora `chrome://inspect` (con la app de depuración) → consola de la página.

### Verificado aquí (sin teléfono)

- Java (`LectorNfcPlugin`, `MainActivity`, `AppNativaPlugin`) compilado con `javac --release 21` contra `android.jar` de la API 36 y con clases de Capacitor 8.5.3 reducidas a las firmas reales usadas (no hay SDK de Android completo en el equipo de desarrollo: la compilación real es la de Android Studio).
- Navegador (Playwright/Chromium, user-agent de la app, `Capacitor` simulado como lo inyecta la app): el botón de la antena aparece; una lectura simulada con `https://…/e/<código>` elige la llave en **Prestar llave** sin salir de la pantalla; NFC apagado → aviso y **Abrir ajustes de NFC** llama a `abrirAjustesNfc()`; sin NFC → «Este teléfono no tiene NFC.»; registro de texto con idioma y «ñ»; `write()` rechaza; sin lector activo, la dirección propia se abre y la de otro sitio no; sin errores de JS ni de CSP; en el navegador normal `nfc-app.js` no se carga.

### Excepciones de vocabulario (criterios UX, regla 4)

«Reintentar» (pantallas sin conexión) y «Salir» / «Cancelar» (diálogo nativo de Android) no están en los diez verbos: son textos de sistema fuera de las pantallas de la plataforma. «Abrir ajustes de NFC» (aviso del lector universal, solo dentro de la app con el NFC apagado) tampoco: abre los ajustes del teléfono, no es una acción de la plataforma. Anotado aquí conforme al punto 4 de «Cómo se aplica».

## Guía paso a paso para el dueño (Windows primero; Mac al final de cada paso)

Rutas usadas en esta guía (Windows). Son **cortas y sin acentos, espacios ni símbolos** a propósito: Gradle y el SDK de Android fallan con rutas como la carpeta de usuario `C:\Users\Marc´l` (el «´» rompe la compilación). Por eso **nada** de la app vive dentro de la carpeta de usuario:

| Qué | Dónde |
|---|---|
| Proyecto | `C:\dev\plataforma` |
| Android SDK | `C:\Android\Sdk` |
| Caché de Gradle | `C:\gradle` (variable de usuario `GRADLE_USER_HOME`) |

### 1. Instala los programas (una sola vez)

1. **GitHub Desktop** (incluye Git): <https://desktop.github.com>. Entra con tu cuenta.
2. **Node.js 24 LTS**: <https://nodejs.org> → botón **LTS** (24.x). Acepta todo lo que propone el instalador.
3. **Android Studio**: <https://developer.android.com/studio>. Al abrirlo la primera vez elige **Custom** (no Standard) y, en la pantalla **SDK Components Setup**, cambia **Android SDK Location** a `C:\Android\Sdk` (crea la carpeta si no existe). Deja que descargue todo.
   - Si ya lo habías instalado con el SDK en tu carpeta de usuario: **File → Settings → Languages & Frameworks → Android SDK** → **Edit** junto a «Android SDK Location» → `C:\Android\Sdk` → Next (lo descarga ahí).
4. **Variable `GRADLE_USER_HOME`** (para que Gradle no use tu carpeta de usuario):
   1. Crea la carpeta `C:\gradle`.
   2. Menú Inicio → escribe **variables de entorno** → **Editar las variables de entorno de esta cuenta**.
   3. En «Variables de usuario» → **Nueva…** → Nombre `GRADLE_USER_HOME`, Valor `C:\gradle` → Aceptar.
   4. Agrega también `ANDROID_HOME` = `C:\Android\Sdk`.
   5. **Cierra y vuelve a abrir** PowerShell y Android Studio para que tomen el cambio.

Comprueba en **PowerShell**: `node --version` (debe decir v24…), `npm --version` y `echo $env:GRADLE_USER_HOME` (debe decir `C:\gradle`).

*Mac:* Node LTS desde nodejs.org, Android Studio con la opción Standard; las rutas de usuario en Mac no suelen dar problema.

### 2. Descarga el proyecto (GitHub Desktop)

1. GitHub Desktop → **File → Clone repository** → elige el repositorio de la plataforma.
2. En **Local path** escribe `C:\dev` (GitHub Desktop crea `C:\dev\plataforma`). Toca **Clone**.
3. Arriba, en **Current branch**, elige `develop` (o la rama que te indiquen).

### 3. Prepara la app

En PowerShell:

```
cd C:\dev\plataforma\movil
npm install
npx cap sync android
```

`npm install` baja Capacitor (solo dentro de `movil/`); `npx cap sync android` deja lista la carpeta `android` con la configuración (dirección del servidor, nombre, appId). **Repite `npx cap sync android` cada vez que cambies `app.config.json`** o traigas cambios con **Fetch/Pull** en GitHub Desktop.

### 4. Abre el proyecto en Android Studio

`npx cap open android`, o en Android Studio: **Open** → `C:\dev\plataforma\movil\android`. Espera a que termine **Gradle sync** (barra de abajo; la primera vez tarda varios minutos).

Android Studio crea solo el archivo `movil\android\local.properties` con `sdk.dir=C\:\\Android\\Sdk`. Ese archivo es **de tu equipo**: está en `.gitignore` y **no se sube** al repositorio (si GitHub Desktop lo muestra como cambio, no lo incluyas).

### 5. Conecta tu celular

1. En el celular: **Ajustes → Acerca del teléfono** → toca 7 veces **Número de compilación** (se activan las «Opciones de desarrollador»).
2. **Ajustes → Sistema → Opciones de desarrollador** → activa **Depuración por USB**.
3. Conecta el cable USB; en el celular acepta **Permitir depuración USB** (marca «Permitir siempre desde esta computadora»).
4. En Android Studio, arriba, aparece el nombre de tu celular en la lista de dispositivos.

### 6. Instala y abre la app

Toca **▶ Run** (triángulo verde). La app se instala y se abre en el celular con la plataforma de la dirección de QA.

### 7. Genera un APK de prueba para compartir

**Build → Build App Bundle(s) / APK(s) → Build APK(s)**. Al terminar, toca **locate** en el aviso: el archivo es `C:\dev\plataforma\movil\android\app\build\outputs\apk\debug\app-debug.apk`. Pásalo por WhatsApp, correo o USB; en el otro celular hay que permitir **Instalar apps desconocidas** para la app con la que se abre. Es una versión de prueba (firmada con la llave de depuración de tu computadora): no sirve para Google Play.

### 8. Cambiar la dirección (QA / Producción) o el appId

Edita `movil\app.config.json`:

```json
{ "appId": "mx.smartcentinel.app", "appName": "Smart Centinel", "servidor": "produccion" }
```

y ejecuta `npx cap sync android`. Para una sola compilación sin tocar el archivo (PowerShell): `$env:SERVIDOR="produccion"; npx cap sync android` (en Mac: `SERVIDOR=produccion npx cap sync android`). Cierra PowerShell después para que la variable no se quede.

- `appId` es el identificador de la app en Android y Google Play. **Se puede cambiar libremente mientras sea de prueba; una vez publicada en Google Play es permanente.** Al cambiarlo, el teléfono la trata como otra app (desinstala la anterior). El paquete interno del código (`mx.smartcentinel.app` en `java/`) no necesita cambiar.
- `appName` es el nombre bajo el ícono.

### Problemas comunes

| Síntoma | Qué hacer |
|---|---|
| Errores raros de Gradle o del SDK con rutas como `C:\Users\Marc´l\…` («Could not create service», «Invalid path», «illegal character», archivos que «no existen» aunque sí estén) | Son las **rutas con acentos, «´», «ñ» o espacios**: Gradle, el SDK y algunas herramientas de Java no las aceptan. Revisa que nada esté bajo tu carpeta de usuario: proyecto en `C:\dev\plataforma`, SDK en `C:\Android\Sdk`, `GRADLE_USER_HOME=C:\gradle` (paso 1). Después: cierra Android Studio, borra la carpeta `movil\android\.gradle` y vuelve a abrir. Si el error menciona `AppData\Local\Temp`, agrega también la variable de usuario `TMP` y `TEMP` = `C:\tmp` (crea la carpeta). |
| «SDK location not found» / «Android SDK not found» | **File → Settings → Languages & Frameworks → Android SDK**: confirma `C:\Android\Sdk` e instala la versión que pide (API 36). Si sigue, revisa que `movil\android\local.properties` diga `sdk.dir=C\:\\Android\\Sdk` (con esas barras dobles) y no lo subas al repositorio. |
| «JAVA_HOME is not set» o versión de Java incorrecta (se necesita 21) | Usa el Java que trae Android Studio: **File → Settings → Build, Execution, Deployment → Build Tools → Gradle → Gradle JDK** = «jbr-21» (Embedded JDK). Para la terminal: variable de usuario `JAVA_HOME` = `C:\Program Files\Android\Android Studio\jbr` (Mac: `/Applications/Android Studio.app/Contents/jbr/Contents/Home`). |
| El celular no aparece o dice «unauthorized» | Desconecta y conecta; acepta el aviso en el celular; prueba otro cable (algunos solo cargan); instala el controlador USB del fabricante. En Opciones de desarrollador: **Revocar autorizaciones de depuración USB** y vuelve a conectar. |
| Gradle tarda mucho o falla al descargar | La primera vez descarga mucho; usa una red estable y espera. Si falla: **File → Invalidate Caches → Invalidate and Restart** y vuelve a hacer **Sync Project with Gradle Files**. Si un antivirus revisa `C:\gradle` y `C:\dev`, agrégalas como exclusión (acelera mucho). |
| «npx no se reconoce» o «la ejecución de scripts está deshabilitada» en PowerShell | Cierra y abre PowerShell tras instalar Node. Si dice que los scripts están deshabilitados: `Set-ExecutionPolicy -Scope CurrentUser RemoteSigned` y responde «S». |
| «Could not find … capacitor-android» o «capacitor-cordova-android-plugins» | Faltó `npm install` y `npx cap sync android` dentro de `movil`. |
| La app abre en blanco o «Sin conexión» | El celular no tiene internet o la dirección de `app.config.json` está mal; revisa y repite `npx cap sync android`. |
| La dirección del servidor «debe empezar con https://» | La app no acepta conexiones sin cifrar. Usa la dirección `https://`. |
| Aviso de Gradle «Falta src/main/assets/capacitor.config.json» | Faltó `npx cap sync android` en `movil`: hazlo y vuelve a **Run**. Sin él, las etiquetas NFC con la app cerrada abren el navegador. |
| «Attribute data@host at AndroidManifest.xml requires a placeholder substitution but no value for <servidorHost> is provided» | El `app/build.gradle` no es el del repositorio (se perdió la línea `manifestPlaceholders`). Trae la versión del repositorio (GitHub Desktop → descartar cambios en ese archivo). |
| Al acercar una etiqueta con la app abierta se abre el navegador | La app instalada es anterior al NFC: vuelve a instalarla con **Run** (o el APK nuevo). Revisa que el NFC esté encendido. |
| El botón de la antena dice «Actualiza la app para leer etiquetas NFC.» | La plataforma ya es la nueva pero la app instalada no: instala la versión nueva. |

### Para publicar en Google Play (después)

1. **Cuenta de desarrollador de Google Play** (pago único). Las cuentas **personales** nuevas deben hacer una **prueba cerrada con al menos 12 testers durante 14 días** antes de publicar; las cuentas de **organización** no (requieren datos de la empresa, p. ej. número D-U-N-S).
2. **Firma:** crea una llave (keystore) en **Build → Generate Signed App Bundle or APK**. Guárdala junto con sus contraseñas **fuera del repositorio** y con respaldo: sin ella no se pueden publicar actualizaciones (Google Play App Signing ayuda a recuperarla). El `.gitignore` ya excluye `*.jks`, `*.keystore` y `keystore.properties`.
3. Google Play pide **AAB** (Android App Bundle), no APK: **Build → Generate Signed App Bundle**.
4. Antes de publicar: decidir el **appId definitivo**, subir `versionCode`/`versionName` en `android/app/build.gradle` en cada versión, ficha de la tienda (capturas, descripción, política de privacidad) y cuestionario de seguridad de datos.
5. Para Producción: `"servidor": "produccion"`.

### iOS (después)

Requiere una **Mac con Xcode** y cuenta de **Apple Developer** (pago anual). En la Mac: `cd movil && npm install @capacitor/ios && npx cap add ios && npx cap open ios`; los ajustes equivalentes (botón atrás no existe en iPhone; descargas e impresión usan sus propias APIs) se hacen en esa etapa. Apple revisa con más rigor las apps que solo envuelven un sitio: conviene llegar con funciones nativas (push, NFC, biometría).

## Pruebas

- `tests/Feature/Nucleo/AppMovilPwaTest.php`: `assetlinks.json` 404 sin huella (o con huellas inválidas) y JSON correcto con huellas normalizadas, sin cookies; `nfc-app.js` solo con el user-agent de la app, sin `defer` y antes de `plataforma.js`; `NDEFReader` de la app ejecutado con Node y el plugin simulado (lectura con la forma de Web NFC, apertura solo de direcciones propias, NFC apagado / sin NFC, `write()` rechaza); manifest con su tipo, íconos con sus medidas, nombre y color de Identidad, sin cookies; service worker en la raíz con `no-cache`, `Service-Worker-Allowed`, versión sustituida, solo GET y sin guardar pantallas, sin cookies aun con sesión; «Sin conexión» sin sesión; CSP sin cambios; cabeceras HTML con manifest, ícono y `pwa.js` sin JS en línea; dentro de la app no se ofrecen los avisos del navegador; la app no cambia el cierre por inactividad.
- Navegador (Playwright): ver «Verificado en navegador» y «NFC en la app → Verificado aquí».
- La app de Android no se compila en la integración continua (requiere el SDK de Android).
