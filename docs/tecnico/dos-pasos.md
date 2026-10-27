# Verificación en dos pasos (TOTP)

Etapa 3 aprobada por el dueño. Segundo factor con contraseñas de un solo uso basadas en el tiempo (RFC 6238), compatible con Google Authenticator, Microsoft Authenticator y cualquier app TOTP. Sin dependencias nuevas: el algoritmo está escrito en PHP (`App\Services\DosPasos\Totp`) y el QR se dibuja con `bacon/bacon-qr-code` (ya instalado).

## Piezas

| Pieza | Archivo |
|---|---|
| TOTP / HOTP, base32, URI `otpauth://` | `app/Services/DosPasos/Totp.php` |
| Reglas de la cuenta (activar, códigos, desactivar, restablecer, política, recordar dispositivo) | `app/Services/DosPasos/VerificacionDosPasos.php` |
| Segundo paso del inicio de sesión | `app/Http/Controllers/Auth/DosPasosController.php` + `SesionController::completarInicio()` |
| Mi cuenta → Verificación en dos pasos | `app/Http/Controllers/Cuenta/DosPasosController.php`, `resources/views/cuenta/dos-pasos.blade.php`, tarjeta en `cuenta/mi-cuenta.blade.php` |
| Obligatoria (Super Administrador o política) | `app/Http/Middleware/ExigirDosPasos.php` (después de `ExigirCambioContrasena`) |
| Restablecer desde Usuarios | `UsuarioController::restablecerDosPasos` (`PATCH /usuarios/{usuario}/dos-pasos`) |
| Política por empresa | `ConfiguracionController::dosPasos` (`PUT /configuracion/dos-pasos`), tarjeta `_dos-pasos.blade.php` en Cuentas y seguridad |
| Consola | `php artisan plataforma:dos-pasos {usuario|correo} [--forzar]` |

## Datos (migración `2026_11_04_000100_verificacion_en_dos_pasos`)

Columnas nuevas en `users`:

- `two_factor_secret` (text): clave base32 de 160 bits **cifrada** con `APP_KEY` (cast `encrypted`). Oculta en `toArray()`/JSON.
- `two_factor_recovery_codes` (text, JSON): **huellas** HMAC-SHA256 (llave = `APP_KEY`) de los 10 códigos de respaldo `XXXX-XXXX` (alfabeto sin 0/O/1/I). Los códigos en claro solo se muestran una vez (flash de sesión).
- `two_factor_confirmado_en` (timestamp): activa si no es nula y hay clave.
- `two_factor_ultimo_paso` (bigint): último paso de 30 s aceptado; un código del mismo paso o anterior se rechaza (anti-reutilización; la actualización es condicional para que dos pestañas no usen el mismo código).

La exportación de empresa ya excluye columnas `*secret` y `*recovery_codes` (`ExportacionEmpresa::PATRON_COLUMNA_SENSIBLE`).

`down()` quita las cuatro columnas (sin índices ni llaves foráneas: seguro en MariaDB).

> **Traslado a otro servidor:** la clave va cifrada con `APP_KEY`. Si el servidor nuevo usa otra `APP_KEY`, las verificaciones activas dejan de servir: restablécelas con `plataforma:dos-pasos` o conserva la misma llave.

## Algoritmo

- HOTP (RFC 4226) con HMAC-SHA1, truncado dinámico, 6 dígitos; TOTP con pasos de 30 s desde la época Unix (`now()`, en UTC, así las pruebas viajan en el tiempo).
- Ventana de ±1 paso (relojes desfasados hasta ~30 s).
- Probado con los vectores del apéndice B del RFC 6238 (`DosPasosTest`).

## Flujos

**Activar (Mi cuenta).** `GET /mi-cuenta/dos-pasos` genera la clave y la guarda **solo en la sesión** (`dos_pasos.nueva`) hasta confirmar; muestra QR (SVG en línea), la clave en grupos de 4 y, en celular/app, el enlace `otpauth://`. `POST` valida el código, guarda la clave cifrada, el paso usado y las huellas de los 10 códigos; redirige a la misma pantalla con los códigos (una sola vez). Auditoría `cuenta.dos_pasos_activada` y campana `cuenta_dos_pasos` (no silenciable).

**Al entrar.** `SesionController::iniciar` valida la contraseña con el `Autenticador` de siempre (bloqueos incluidos). Con verificación activa y sin dispositivo recordado, **no** abre la sesión: regenera la sesión y guarda `dos_pasos.pendiente` (id + hora) y lleva a `GET /login/verificacion`. El código (o uno de respaldo) se valida en `POST /login/verificacion`:

- Pendiente vence a los **10 minutos** (`MINUTOS_PENDIENTE`).
- **5 intentos** por cuenta cada 15 minutos (`RateLimiter` `dos-pasos:{id}`), además de `throttle:10,1` por equipo. Al agotarlos se olvida la cuenta pendiente; volver a poner la contraseña no da más intentos.
- Cada fallo se audita: `cuenta.dos_pasos_fallido` (origen con IP recortada).
- Un código de respaldo se consume (`cuenta.dos_pasos_respaldo_usado`) y se avisa cuántos quedan (aviso `aviso` en la sesión; Inicio, `panel.index`, incluye `administracion.partes.avisos` para pintarlo).

**Recordar este dispositivo 30 días.** Cookie `recordar_dos_pasos_{id}` (HttpOnly, SameSite=Lax, cifrada por `EncryptCookies`) con `id|expira|firma`, donde la firma es HMAC-SHA256 (llave `APP_KEY`) de id, expiración, `two_factor_confirmado_en` y el hash de la contraseña. Deja de valer al vencer, al desactivar/restablecer/volver a activar la verificación y al **cambiar la contraseña**.

**Obligatoria.** `VerificacionDosPasos::exigida()`:
- Super Administrador: **siempre**.
- Política de su empresa (`empresas.preferencias.dos_pasos`): `nadie` (original) · `administradores` (quien tenga `usuarios.crear`, `usuarios.editar`, `roles.editar`, `permisos.editar` o `configuracion.editar`) · `todos`.

Si al entrar la cuenta la necesita y no la tiene, `completarInicio` pone la marca de sesión `dos_pasos.exigir` y lleva a `mi-cuenta.dos-pasos`. `ExigirDosPasos` solo deja abiertas Mi cuenta, la activación, salir, el latido y el resumen de la campana; las peticiones JSON reciben 403. La marca se quita al activarla. (Se decide al entrar, no en cada petición: cambiar la política aplica en el siguiente inicio de sesión.) Una cuenta que la necesita no puede quitarla.

**Restablecer.** Quien tiene `usuarios.desbloquear` (mismo alcance y jerarquía que desbloquear; nunca la propia cuenta ni al Super Administrador) quita la verificación de otra cuenta: se borran clave y códigos, se cierran sus sesiones, se audita `usuarios.dos_pasos_restablecida` y se avisa a la persona por campana y correo (`AvisoGeneral`). Si la cuenta tenía una petición «Perdí mi teléfono» abierta, queda atendida (`SolicitudesCuenta::atendidas`).

**«Perdí mi teléfono: pedir ayuda» (C-36, E-14).** En la pantalla del código, `POST /login/verificacion/perdi-mi-telefono` (`throttle:5,1`) solo funciona con la cuenta **pendiente** en la sesión (ya escribió su contraseña): crea una `solicitudes_cuenta` de tipo `dos_pasos` (una abierta por cuenta y tipo), la audita (`usuarios.solicitud_cuenta`), avisa por campana (`cuenta_solicitud`) a quien puede atenderla y olvida la cuenta pendiente (`acceso = dos_pasos_pedido`). Ver [usuarios.md](usuarios.md#pedir-a-mi-administrador-c-36).

**Super Administrador sin terminal (C-36, DD-21, E-14).** El hospedaje no tiene terminal, así que la recuperación del Super Administrador no depende de la consola:
1. **Códigos de respaldo** (10, de un solo uso), como cualquier cuenta. La tarjeta de Super Administradores muestra cuántos le quedan a cada uno.
2. **Otro Super Administrador**: la petición «Perdí mi teléfono» de un Super Administrador (`empresa_id` nulo) llega por `avisarPlataforma` a los **otros** Super Administradores activos (Inicio: renglón «Super Administradores que piden ayuda»; Configuración → Plataforma → Super Administradores). Cualquiera de ellos la atiende con `POST /configuracion/superadmins/{id}/dos-pasos`, **escribiendo su propia contraseña**: `Plataforma\SuperAdministradores::restablecerDosPasos` → `VerificacionDosPasos::restablecerSuperadmin` (borra clave y códigos, cierra sesiones, audita `usuarios.dos_pasos_restablecida` con `origen = otro_superadmin`, avisa a la cuenta por correo) y campana `plataforma_superadmin` (no silenciable) a los demás. Nunca sobre la propia cuenta.
   La contraseña que confirma cada acción de la tarjeta tiene límite: tras **5 errores** por cuenta (`RateLimiter` `superadmin-confirmar:{id}`, `SuperAdministradores::INTENTOS_CONTRASENA`) se espera **15 minutos**, así una sesión robada no sirve para adivinarla.
3. **Mínimo dos activos** (`SuperAdministradores::MINIMO`): con uno solo, Inicio y la tarjeta lo avisan en rojo; dar de baja a uno que dejaría menos de dos se rechaza (`superadmin`). El alta del segundo se hace en la misma tarjeta (sin consola): correo obligatorio, enlace de bienvenida o contraseña temporal, `debe_cambiar_contrasena` y dos pasos obligatorios al entrar.
4. La consola (`php artisan plataforma:dos-pasos`) **sigue** sirviendo donde sí hay terminal.

El Super Administrador es la identidad de la plataforma (no un rol de empresa): estas pantallas revisan `es_superadmin`, igual que respaldos y correo de la plataforma.

**Desactivar.** Pide contraseña + código (de la app o de respaldo). Auditoría `cuenta.dos_pasos_desactivada`; borra la cookie de dispositivo.

## App móvil

La app de Android es un WebView de la misma plataforma: el segundo paso es la misma pantalla (`autocomplete="one-time-code"`). En la pantalla de activación, dentro de la app (o en un celular) aparece «Abrir en mi app de autenticación» con el `otpauth://`.

## Permisos

Sin acciones nuevas: Mi cuenta es de su dueño (criterio 6); restablecer usa `usuarios.desbloquear`; la política, `configuracion.editar` con alcance de empresa; Super Administradores, `es_superadmin` (plataforma).

| Método | Ruta | Nombre | Quién |
|---|---|---|---|
| POST | `/login/verificacion/perdi-mi-telefono` | `login.dos-pasos.ayuda` | Cuenta pendiente (contraseña ya escrita) |
| POST | `/configuracion/superadmins` | `superadmins.store` | Super Administrador + su contraseña |
| PATCH | `/configuracion/superadmins/{id}/estado` | `superadmins.estado` | Super Administrador + su contraseña; mínimo dos activos |
| POST | `/configuracion/superadmins/{id}/contrasena` | `superadmins.contrasena` | Super Administrador + su contraseña (no la propia) |
| POST | `/configuracion/superadmins/{id}/dos-pasos` | `superadmins.dos-pasos` | Super Administrador + su contraseña (no la propia) |
| PATCH | `/configuracion/superadmins/solicitudes/{id}/rechazar` | `superadmins.solicitudes.rechazar` | Super Administrador (no la propia); A-08 |

Auditoría nueva: `plataforma.superadmin_creado`, `plataforma.superadmin_dado_de_baja`, `plataforma.superadmin_reactivado`, `plataforma.superadmin_contrasena`.

## Pruebas

`tests/Feature/Seguridad/DosPasosTest.php`: vectores RFC 6238, ventana y reutilización; activar con QR/clave y códigos una vez; entrada con código; límite de intentos y auditoría; códigos de respaldo y restablecerlos; recordar dispositivo (firma, 30 días, cambio de contraseña); política por empresa y restablecer valor original; Super Administrador obligatorio y consola; restablecer por el administrador (alcance, otra empresa 404, aviso y correo); desactivar; app móvil. `tests/Feature/Seguridad/CuentaEsDeSuDuenoTest.php` (C-36): «Perdí mi teléfono» de una cuenta y de un Super Administrador, códigos de respaldo, restablecer por otro Super Administrador (contraseña, propia cuenta, administrador de empresa 403), mínimo dos activos y aviso en Inicio.
