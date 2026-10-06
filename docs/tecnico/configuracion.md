# Configuración

Pantalla `GET /configuracion` (módulo `configuracion`, área Dirección). Crece por secciones conforme se necesitan.

| Sección | Quién | Dónde se guarda |
|---|---|---|
| Avisos por correo de la empresa | `configuracion.editar` (Administrador) | `empresas.preferencias` (JSON) → `avisos.*` |
| Correo de la plataforma (SMTP) | Solo el Super Administrador | `configuracion_plataforma` (clave `correo`) |
| Respaldos de la base de datos | Solo el Super Administrador | Archivos en `storage/app/private/respaldos` |

## Correo (`App\Support\CorreoPlataforma`)

- **Dónde se configura:** en la pantalla, no en el `.env`. Se capturan servidor, puerto, cifrado (TLS/STARTTLS 587, SSL 465 o ninguno), usuario, contraseña y remitente.
- **Servidor y puerto permitidos** (`CorreoPlataforma::problemaDestino`): solo puertos de correo (25, 465, 587, 2525) y ningún servidor que apunte a direcciones reservadas (169.254.x.x de metadatos de la nube, 0.0.0.0, multicast, enlace local IPv6). Se revisa al guardar y otra vez antes de cada envío. Evita usar la pantalla para tocar otros servicios de la red interna.
- **Contraseña:**
  - se guarda cifrada con la `APP_KEY` (`Crypt::encryptString`);
  - nunca se devuelve a la vista ni se escribe en la bitácora (solo "cambiada", "quitada" o "sin cambio");
  - si el campo se deja vacío, se conserva la que ya estaba guardada.
- **Envío:**
  - `aplicar()` arma el mailer `plataforma` al momento de enviar;
  - `enviar()` nunca lanza excepción: devuelve `false` y guarda `ultimo_error`, que se ve en la pantalla; el detalle técnico va al log.
- **Correo de prueba:** `POST /configuracion/correo/prueba`, limitado a 5 por minuto.
- **Si cambia la `APP_KEY`:** la contraseña ya no se puede descifrar y hay que volver a capturarla.

## Avisos (`App\Services\Avisos\AvisosCorreo`)

- **Cuándo se envían:** después de responder al usuario (`defer`). El guardia no espera al servidor de correo y, si el envío falla, la captura ya quedó guardada.
- **`altaProvisional`:** avisa a los usuarios activos de la empresa con `colaboradores.aprobar` que alcancen la sede del colaborador.
  - Va sin datos personales: nombre, sede, quién lo registró, hora y enlace.
  - Se manda solo si el aviso está activo en la empresa (activo por defecto) y el correo de la plataforma está configurado.
- **`valeTaxi`** (Bitácora de transporte): cada vale de taxi va a la **lista de correos** del aviso `vale_taxi` (textarea en la pantalla, `empresas.preferencias.avisos_destinatarios.vale_taxi`, máximo 20, validados); si la lista está vacía, a los usuarios con `transporte.aprobar` que alcancen la sede. Los avisos con lista se declaran en `Empresa::AVISOS_CON_DESTINATARIOS` y se leen con `Empresa::destinatariosAviso()`.
- **Para agregar un aviso nuevo:**
  1. agrega una entrada en `Empresa::AVISOS` (etiqueta y valor por defecto);
  2. agrega un método en `AvisosCorreo` y su Mailable en `app/Mail`, con su vista en `resources/views/correos`.

## Respaldos (`App\Services\Respaldos\Respaldos`, `php artisan plataforma:respaldar`)

- **Cómo se generan:** en PHP, sin `mysqldump` ni `exec`, que Neubox no permite desde la web. Salen en `.sql.gz`, listos para importar en phpMyAdmin.
- **Cuándo los llama `desplegar.sh`** (no hace falta otro cron):
  - antes de `migrate`, cuando se instala una versión nueva (`--motivo=antes-de-actualizar`);
  - en cada corrida, con `--si-toca`: hace el respaldo diario si ya pasaron las 3:00 (hora de Cancún) y aún no hay uno de hoy.
- **Conservación:** 14 días, y siempre al menos los 3 más recientes. Los archivos quedan con permisos `600`, fuera de la carpeta pública y compartidos entre versiones.
- **Qué no lleva:** las tablas `sessions`, `cache`, `cache_locks` y `password_reset_tokens` se respaldan solo con su estructura (son datos temporales que no deben viajar en un archivo descargable). La carpeta queda en `700` y el archivo es `600` desde que se empieza a escribir.
- **Descarga:** solo el Super Administrador, y cada descarga queda en la bitácora (`configuracion.respaldo_descargado`). El nombre del archivo se valida contra el patrón del servicio para evitar rutas arbitrarias.
- **Restaurar:** de forma manual en phpMyAdmin (Importar). La plataforma no restaura desde la web, a propósito: es una operación destructiva.

## Pruebas

`tests/Feature/Direccion/ConfiguracionTest.php`
