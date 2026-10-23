# Configuración

Pantalla `GET /configuracion` (módulo `configuracion`, área Dirección), **ordenada por secciones con índice** (petición del dueño, 2026-10-15). El inventario completo de ajustes (por empresa, de plataforma, `.env` y constantes revisadas) está en [configuracion-inventario.md](configuracion-inventario.md).

Entra quien tiene `configuracion.ver` **o** quien configura los días de resguardo de Lost & Found (`lost_found.configurar` con alcance de empresa); este último solo ve su sección.

## Secciones (anclas del índice)

| Ancla | Sección | Tarjetas | Quién edita |
|---|---|---|---|
| `#sec-general` | General | «Configura tu empresa» (`#configura-tu-empresa`: Mostrar u Ocultar en Inicio, ver [configura-tu-empresa.md](configura-tu-empresa.md)), Datos de la empresa (enlace a Empresas), **Reloj del visto bueno** (`#cfg-vobo`), **Nombres en el menú** (`#cfg-menu`, Avanzado) | `configuracion.editar` empresa; Empresas: `empresas.editar` |
| `#caseta` | Caseta y accesos | Esperas del departamento (`#cfg-esperas`, enlace), Impresión desde padrones (`#cfg-impresion`, enlace), Procedimientos (`#cfg-procedimientos`), avisos de Colaboradores, Altas por verificar y Procedimientos | `configuracion.editar` empresa; enlaces: su permiso `configurar` |
| `#sec-rh` | Recepción y RR. HH. | Avisos, aviso de privacidad y kiosco (`#recepcion`), Plazos y documentos (`#cfg-plazos`, enlace), Criterios (`#cfg-criterios`, enlace), Bolsa de trabajo (`#bolsa-trabajo`) | `candidatos.configurar` / `vacantes.configurar` empresa |
| `#sec-novedades` | (nombre de la bitácora de la empresa) | Avisos, nombre y hallazgo (`#novedades`), Lost & Found (`#lost-found`) | `configuracion.editar` empresa; LF: `lost_found.configurar` empresa |
| `#sec-activos` | Activos | Llaves (`#cfg-llaves`), Responsivas (`#cfg-responsivas`), avisos de Vouchers | `configuracion.editar` empresa |
| `#sec-transporte` | Transporte | Firmas del vale (`#cfg-transporte`), avisos del vale de taxi | ídem |
| `#sec-pases` | Pases de salida | Resumen del circuito («Configurar circuito»), avisos | `pases_salida.configurar`; avisos: `configuracion.editar` |
| `#sec-avisos` | Avisos por correo | Estado del correo, resumen encendido/apagado por módulo con enlace a cada tarjeta, «Otros avisos», enlace a Mi cuenta | `configuracion.editar` empresa |
| `#sec-cuentas` | Cuentas y seguridad | Avisos, qué ve «Su sede» (`#config-auditoria`), cierre por inactividad (solo lectura, `#cfg-sesion`) | `configuracion.editar` empresa |
| `#sec-plataforma` | Plataforma (solo SA) | Enlaces a Identidad, Catálogo comercial (Ajustes y Clientes: suscripciones) y Empresas (módulos); Correo de la plataforma; Respaldos | Super Administrador |

- Cada sección se muestra si alguna de sus tarjetas aplica al rol. Las tarjetas de avisos (`_avisos-grupo.blade.php`, ancla `#config-<grupo>`) se pintan **todas** cuando hay `configuracion.ver` (el formulario único `#formAvisos` manda todas las casillas); un grupo nuevo de `GruposAvisos` que no esté mapeado a una sección cae en «Avisos por correo».
- **Una sola fuente de verdad:** lo que vive en otro módulo (esperas y escalamiento, plazos, documentos, criterios, impresión desde padrones, circuito de pases, datos de la empresa) se muestra con su valor y su enlace «Configurar en …» (`ConfiguracionController::ajustesEnOtrasPantallas()`, solo si el rol tiene el permiso de esa pantalla). Los formularios compartidos (privacidad y kiosco, bolsa, días de Lost & Found) son la misma vista y la misma ruta en los dos lugares.
- **Valor original:** cada ajuste muestra «Valor original: …» (`.valor-original`).
- **Restablecer valor original** (`PUT /configuracion/restablecer`, `ajuste` ∈ `ConfiguracionController::RESTABLECIBLES`, 30 por minuto): un botón por tarjeta, con confirmación, solo si la tarjeta tiene algo distinto del original y el rol puede guardarla. `transporte|llaves|responsivas|procedimientos|vobo` quitan sus claves de `preferencias.caseta`; `menu` deja `nombre_visible` en nulo (salvo la bitácora); `novedades` regresa a «Novedades» y Siniestro PC; `lost_found` guarda `LostFoundUmbral::POR_OMISION` (`lost_found.configurar` empresa); `auditoria` quita `auditoria_sede`; `avisos` quita `avisos` y `avisos_modulos` (**conserva** `avisos_destinatarios`). Auditoría `configuracion.restablecido` («Ajuste regresado a su valor original»). «Restablecer» no está en los diez verbos (criterio 4): excepción pedida por el dueño en esta petición.
- **Ajustes de caseta por tarjeta:** cada tarjeta manda `_tarjeta` y solo sus campos; `AjustesCasetaController` hace `array_merge(vigentes, recibidos)` antes de validar, así una tarjeta no borra las demás. Regresa a `#cfg-<tarjeta>` (sin `_tarjeta`, a `#caseta`).

### Ajustes nuevos (antes solo en código o en la base de datos)

| Ajuste | Clave | Límites | Original | Dónde se aplica |
|---|---|---|---|---|
| Reloj del visto bueno: amarillo / rojo | `preferencias.caseta.vobo_horas_amarillo` / `vobo_horas_rojo` | 1–168 / 2–720, rojo > amarillo | 24 / 72 | `RelojVoBo::umbralesActuales()` en `componentes/reloj-vobo` (transporte y procedimientos) y `PendientesActivos` (Mis pendientes). El texto se arma con los umbrales («más de 36 h», «más de 3 días»). El Catálogo comercial (sin empresa) usa las constantes. |
| Recordar procedimientos cada N días | `preferencias.caseta.procedimientos_recordatorio_dias` | 1–30 | 3 | `AdministradorProcedimientos::recordatorios()` por empresa |
| Nombres en el menú | `empresa_modulos.nombre_visible` | 40 letras, `[\pL\pN .&-]` | vacío | `App\Support\NombresMenu` (`PUT /configuracion/menu`, `configuracion.editar` empresa, auditoría `configuracion.menu`). Excluye `novedades` (tiene su ajuste). Lo leen el menú y «Lo que verá» de la Matriz. |

### Pruebas

`tests/Feature/Direccion/ConfiguracionSeccionesTest.php` (secciones e índice por rol, Plataforma solo SA, Vo.Bo. y recordatorio se guardan y se aplican, tarjetas que no se pisan, nombres del menú, restablecer de cada tarjeta, rol que solo consulta y rol sin permiso).

## Tabla anterior (referencia)

| Sección | Quién | Dónde se guarda |
|---|---|---|
| Avisos por correo de la empresa | `configuracion.editar` (Administrador) | `empresas.preferencias` (JSON) → `avisos.*` |
| Correo de la plataforma (SMTP) | Solo el Super Administrador | `configuracion_plataforma` (clave `correo`) |
| Respaldos de la base de datos | Solo el Super Administrador | Archivos en `storage/app/private/respaldos` |
| Lost & Found: días de resguardo (`#lost-found`) | Cambia: `lost_found.configurar` **con alcance de empresa** (Administrador). Consulta: quien tiene `configuracion.ver` y `lost_found.ver` | `lost_found_umbrales` (por empresa, sin cambios) |

## Lost & Found: días de resguardo (QA 4)

Antes era la pantalla `/lost-found/dias-resguardo` dentro de Lost & Found; ahora es una sección de Configuración (petición del responsable).

| Método | Ruta | Nombre | Permiso |
|---|---|---|---|
| PUT | `/configuracion/lost-found` (`dias[OTRO|ALTO_VALOR|ELECTRONICO|ROPA|PERECEDERO]`) | `configuracion.lost_found` | `lost_found.configurar` con alcance de empresa (con alcance de sede → 403) |
| GET | `/lost-found/dias-resguardo` (dirección anterior) | `lost_found.umbrales` | `lost_found.ver` → **301** a `/configuracion#lost-found` si puede abrir Configuración; si no, 301 a `/lost-found` |

- Reglas, mensajes y auditoría sin cambios: `ArchivoLostFound::guardarUmbrales()` (1 a 3650 días por tipo de valor, `lost_found.configurado` sobre la empresa). Al guardar regresa a `/configuracion#lost-found`.
- Cada tipo muestra cuándo se pone en amarillo (70 %) y en rojo, y «Editado por … · fecha» con `@fecha`.
- ~~En `/lost-found` se quitó el botón «Configurar Días»~~ **Decisión del dueño (2026-10-12):** los días también se editan desde el propio archivo de Lost & Found (botón y diálogo «Días de resguardo», `PUT /lost-found/dias-resguardo`, ver docs/tecnico/lost-found-y-robo.md). Una sola fuente de verdad: `lost_found_umbrales` y `ArchivoLostFound::guardarUmbrales()`; los campos de las dos pantallas son la misma vista `seguridad/lost-found/_dias-campos.blade.php`. Los días de cada tipo se siguen viendo en el filtro de tipo de valor y en cada artículo.
- La sección de Avisos por correo ahora solo se muestra a quien tiene `configuracion.ver`.
- Se borró la vista `seguridad/lost-found/umbrales.blade.php`; `LostFoundController::guardarUmbrales()` regresó (2026-10-12) como acción del diálogo del archivo, con las mismas reglas que Configuración.

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
- **`voucherConCobro`** (Ronda 5): aviso `voucher_cobro`; tres listas (`Empresa::DESTINATARIOS_VOUCHER`: `voucher_seguridad`, `voucher_recepcion`, `voucher_administracion`) bajo la casilla del aviso, guardadas en `avisos_destinatarios` con las mismas reglas (máx. 20, validadas). Un correo por copia; si las tres están vacías, a quien tiene `vouchers.imprimir` en la sede. Nunca al colaborador. Detalle en [vouchers.md](vouchers.md#ronda-5-firmas-copias-y-correo).
- **Para agregar un aviso nuevo:**
  1. agrega una entrada en `Empresa::AVISOS` (etiqueta y valor por defecto);
  2. agrega un método en `AvisosCorreo` y su Mailable en `app/Mail`, con su vista en `resources/views/correos`.

## Respaldos (`App\Services\Respaldos\Respaldos`, `php artisan plataforma:respaldar`)

- **Cómo se generan:** en PHP, sin `mysqldump` ni `exec`, que Neubox no permite desde la web. Salen en `.sql.gz`, listos para importar con la herramienta de base de datos del hospedaje. La primera línea (`Respaldos::COMO_RESTAURAR`) lo explica sin nombrar herramientas, igual que la pantalla.
- **Cuándo los llama `desplegar.sh`** (no hace falta otro cron):
  - antes de `migrate`, cuando se instala una versión nueva (`--motivo=antes-de-actualizar`);
  - en cada corrida, con `--si-toca`: hace el respaldo diario si ya pasaron las 3:00 (hora de Cancún) y aún no hay uno de hoy.
- **Conservación:** 14 días, y siempre al menos los 3 más recientes. Los archivos quedan con permisos `600`, fuera de la carpeta pública y compartidos entre versiones.
- **Qué no lleva:** las tablas `sessions`, `cache`, `cache_locks` y `password_reset_tokens` se respaldan solo con su estructura (son datos temporales que no deben viajar en un archivo descargable). La carpeta queda en `700` y el archivo es `600` desde que se empieza a escribir.
- **Descarga:** solo el Super Administrador, y cada descarga queda en la bitácora (`configuracion.respaldo_descargado`). El nombre del archivo se valida contra el patrón del servicio para evitar rutas arbitrarias.
- **Restaurar:** de forma manual en phpMyAdmin (Importar). La plataforma no restaura desde la web, a propósito: es una operación destructiva.
- **Archivos y traslado:** fotos, documentos y firmas van en el **paquete de traslado** (base + archivos + manifest con SHA-256) y su restauración por consola; ver `traslado.md`.

## Pruebas

`tests/Feature/Direccion/ConfiguracionTest.php`; días de resguardo en `tests/Feature/Seguridad/LostFoundRoboTest.php` (`test_dias_de_resguardo_solo_con_configurar_en_toda_la_empresa`).

## Caseta y activos (ADR-0014)

Ajustes de caseta y activos (`_caseta.blade.php` con `$tarjeta`, `PUT /configuracion/caseta`, `configuracion.editar` con alcance de empresa): firmas de los vales de taxi (Transporte), semáforo de llaves y equipos «Turno» (Activos), acuse y recordatorio de procedimientos (Caseta y accesos) y reloj del visto bueno (General), en `empresas.preferencias['caseta']` (solo lo distinto de la omisión). Auditoría `configuracion.caseta`. Detalle: [caseta-y-activos.md](caseta-y-activos.md).

> Mejoras de 2026-10 (roles sin números, asistente de permisos, configuración por módulo, avisos por persona, historial y varias sedes): ver [estructura-mejoras.md](estructura-mejoras.md) y [ADR-0016](../decisiones/ADR-0016-mejoras-de-estructura.md).

## Verificación en dos pasos y Privacidad

- **Cuentas y seguridad → Verificación en dos pasos** (`PUT /configuracion/dos-pasos`, `configuracion.editar` empresa; restablecible `dos_pasos`): a quién se exige. Ver [dos-pasos.md](dos-pasos.md).
- **Privacidad** (`#sec-privacidad`, acción `configuracion.datos_personales` con alcance de empresa): conservación de datos (`PUT /configuracion/privacidad`), aviso con versiones (`POST /configuracion/privacidad/aviso`) y enlace a la bandeja ARCO. Ver [privacidad.md](privacidad.md).

## Versión de PHP del servidor (`App\Services\Plataforma\VersionPhp`)

Tarjeta **Versión de PHP del servidor** (`#cfg-php`, parcial `_version-php.blade.php`) dentro de **Plataforma**: solo el Super Administrador. El controlador calcula los datos solo si `es_superadmin` (para nadie más se llama al servicio) y nada sale en cabeceras HTTP (`CabecerasSeguridad` ya quita `X-Powered-By`).

| Dato | De dónde sale |
|---|---|
| Páginas (web) | `PHP_VERSION` del proceso que atiende la página. |
| Tareas programadas | Línea `PHP del cron:` que `desplegar.sh` escribe en `$HOME/despliegue_<amb>_estado.txt`. Solo se muestra el número de versión (nunca la ruta del ejecutable). Si el archivo no existe, no se puede leer o dice «sin detectar»: «Sin dato todavía». |
| Estado | `VersionPhp::evaluar()`: **Al día** si la versión es ≥ la mínima de su rama; **Requiere actualización** (rojo) si es menor o si la rama es anterior a la más baja de la lista (sin soporte, p. ej. 8.1). Una rama más nueva que todas las de la lista cuenta como al día. Si web o cron requieren actualización sale el aviso de `config('plataforma.php.aviso')`. |
| Aceleración (OPcache) | `opcache_get_status(false)['opcache_enabled']`. |
| Memoria máxima / Tiempo máximo por página | `ini_get('memory_limit')` y `ini_get('max_execution_time')` (−1 o 0 = «Sin límite»). |

- **Versiones mínimas en un solo lugar:** `config/plataforma.php` → `php.minimas` (`8.5 ⇒ 8.5.12`, `8.4 ⇒ 8.4.27`, `8.3 ⇒ 8.3.36`, `8.2 ⇒ 8.2.35`, corrección de PHP2Shell, CVE-2026-109618 y CVE-2026-109619) y el texto en `php.aviso`. Cuando salga otra corrección se cambian ahí; no hay migraciones.
- **Archivo de estado:** `PLATAFORMA_ESTADO_DESPLIEGUE` (opcional) o, si no está, se deduce de la carpeta de la aplicación: `…/apps/plataforma-qa/releases/<versión>` ⇒ `…/despliegue_qa_estado.txt`; `plataforma-produccion` ⇒ `despliegue_prod_estado.txt`. Fuera del servidor (equipo de desarrollo) no hay archivo.
- **Pruebas:** `tests/Feature/Administracion/VersionPhpTest.php` (solo el Super Administrador la ve; nadie más la calcula ni sale en cabeceras; cálculo para 8.5/8.4/8.3/8.2 antes y después de la corrección, 8.1 y 7.4 sin soporte, rama más nueva y texto sin versión, inyectando la versión con `new VersionPhp('8.4.20')`; lectura del archivo de estado y deducción de su ruta).
