# Inventario de configuraciones

Revisión del 2026-10-15 (petición del dueño: «revisa si en Configuración tenemos todas las configuraciones que correspondan a la plataforma»). Lista **todo** lo que se puede ajustar por empresa o para toda la plataforma: dónde se guarda, dónde se edita, quién lo edita y su valor por omisión (el original).

Convenciones:

- **Pantalla** = dónde se cambia hoy. «Configuración → X» es la sección X de `GET /configuracion` (ver [configuracion.md](configuracion.md)). «Enlace» = la Configuración lo muestra con su valor y el botón «Configurar en …»; el formulario vive en su módulo (una sola fuente de verdad).
- **Quién**: permiso `modulo.accion`. «empresa» = con alcance de toda la empresa (con alcance de sede solo se consulta). SA = Super Administrador.
- `pref.` = columna JSON `empresas.preferencias`. Lo que no está guardado vale lo de la columna «Por omisión».
- 🆕 = agregado en esta revisión (antes solo en código o solo en la base de datos).

## 1. Ajustes por empresa

### General

| Ajuste | Dónde se guarda | Pantalla | Quién | Por omisión |
|---|---|---|---|---|
| Nombre comercial, razón social, RFC, logo | `empresas.*` | Estructura → Empresas (enlace en Configuración → General) | `empresas.editar` empresa | — |
| Zona horaria de la empresa | `empresas.zona_horaria` | Estructura → Empresas (enlace en General) | `empresas.editar` empresa | `America/Mexico_City` |
| Zona horaria de cada sede | `sedes.zona_horaria` | Estructura → Sedes | `sedes.editar` | la de la empresa |
| Rubro (terminología y módulos sugeridos) | `empresas.rubro_id` | Estructura → Empresas | SA | el del alta |
| «Configura tu empresa» visible en Inicio | pref. `configura_tu_empresa` (`visible` / `oculto`) | Configuración → General (botón **Mostrar en Inicio** / **Ocultar de Inicio**, `PUT /configura-tu-empresa`) y **Ya terminé** en Inicio | `configuracion.editar` | `visible` en empresas nuevas; sin dato: se muestra solo si la empresa está casi vacía (2 pasos o menos) |
| Módulos contratados | `empresa_modulos.activo` | Estructura → Empresas → cuadrícula (enlace en Plataforma) | SA | los del plan |
| 🆕 Nombres en el menú (cada módulo salvo la bitácora) | `empresa_modulos.nombre_visible` | Configuración → General → Avanzado (antes **solo en la base de datos**) | `configuracion.editar` empresa | vacío = nombre de siempre |
| 🆕 Reloj del visto bueno (vales de taxi y procedimientos en revisión) | pref. `caseta.vobo_horas_amarillo` / `vobo_horas_rojo` | Configuración → General (antes **constantes** de `RelojVoBo`) | `configuracion.editar` empresa | 24 h / 72 h |
| Idioma y moneda de la empresa | `empresas.idioma`, `empresas.moneda` | — (columnas sin uso; no se agregó pantalla) | — | `es`, `MXN` |

### Caseta y accesos

| Ajuste | Dónde se guarda | Pantalla | Quién | Por omisión |
|---|---|---|---|---|
| Visita / proveedor / contratista esperan autorización del departamento | pref. `recepcion.{visitas,proveedores,contratistas}_requieren_autorizacion` | RR. HH. → Responsables por departamento (enlace en Caseta y accesos) | `autorizaciones.configurar` o `candidatos.configurar`, empresa | sí (empresas antiguas: su valor migrado) |
| La caseta espera el «Que pase» de RR. HH. | pref. `recepcion.rh_autoriza_paso` | Responsables por departamento (enlace) | ídem | sí (empresas antiguas: no) |
| Recordatorio y escalamiento de esperas (minutos) | pref. `recepcion.recordar_minutos` / `escalar_minutos` | Responsables por departamento (enlace) | ídem | 10 / 20 (`PanelRecepcion::ESPERA_*`) |
| Responsables, suplentes y delegaciones por departamento | tablas de autorizaciones | Responsables por departamento / «No molestar» en Mi cuenta | `autorizaciones.configurar` / cada persona | — |
| Imprimir etiquetas desde los padrones | pref. `padrones.impresion` | Etiquetas QR → Plantillas (enlace) | `etiquetas_qr.configurar` empresa | `modulo` (hoja propia) |
| Plantillas de etiquetas QR | `etiquetas_plantillas` | Etiquetas QR → Plantillas | `etiquetas_qr.configurar` | plantillas del sistema |
| Procedimientos: cómo se firma «Leí y entendí» | pref. `caseta.procedimientos_acuse` | Configuración → Caseta y accesos | `configuracion.editar` empresa | `firma` |
| 🆕 Procedimientos: recordar cada N días | pref. `caseta.procedimientos_recordatorio_dias` | Configuración → Caseta y accesos (antes **constante** `RECORDATORIO_CADA_DIAS`) | `configuracion.editar` empresa | 3 |
| Avisos: alta provisional, altas por verificar, procedimientos | pref. `avisos.*`, `avisos_modulos.*` | Configuración → Caseta y accesos | `configuracion.editar` empresa | encendidos |

### Recepción y RR. HH.

| Ajuste | Dónde se guarda | Pantalla | Quién | Por omisión |
|---|---|---|---|---|
| Aviso de privacidad del candidato | pref. `recepcion.aviso_privacidad` | Configuración → Recepción y RR. HH. y Recepción → Ajustes (mismo formulario) | `candidatos.configurar` empresa | borrador para el abogado |
| Enlace del kiosco: horas y usos | pref. `recepcion.kiosco_horas` / `kiosco_usos` | ídem | ídem | 2 h / 3 usos |
| Criterios de entrevista | pref. `recepcion.criterios` | Candidatos → Criterios de entrevista (enlace) | `candidatos.configurar` empresa | 5 criterios (`CRITERIOS_DEFECTO`) |
| Plazos de candidatos (solicitud, evaluación, documentos, por atender, no se presentó, archivar, borrar) | pref. `recepcion.plazos.*` | Candidatos → Documentos y plazos (enlace) | `candidatos.configurar` empresa | 1, 2 hábiles, 5, 2 días; 24 h; 6 y 12 meses |
| Documentos para contratar | `documentos_para_contratar` | Candidatos → Documentos y plazos (enlace) | `candidatos.configurar` empresa | lista base |
| Bolsa de trabajo: publicar, presentación, Google | pref. `bolsa_trabajo.*` y `empresas.bolsa_slug` | Configuración → Recepción y RR. HH. y Vacantes → Ajustes (mismo formulario) | `vacantes.configurar` empresa | apagada, sin Google |
| Avisos de Recepción y candidatos | pref. `avisos.*` | Configuración → Recepción y RR. HH. | `configuracion.editar` empresa | encendidos |

### Novedades / Incidentes

| Ajuste | Dónde se guarda | Pantalla | Quién | Por omisión |
|---|---|---|---|---|
| Nombre del módulo (Novedades / Incidentes / otro) | `empresa_modulos.nombre_visible` (módulo `novedades`) | Configuración → Novedades | `configuracion.editar` empresa | Novedades |
| Categoría del ticket de un hallazgo de recorrido | pref. `novedades.categoria_hallazgo_pc` | Configuración → Novedades | `configuracion.editar` empresa | Siniestro Protección Civil |
| Lost & Found: días de resguardo por tipo | `lost_found_umbrales` | Configuración → Novedades y diálogo «Días de resguardo» del archivo (mismo formulario) | `lost_found.configurar` empresa | 90/180/180/30/2 |
| Avisos de la bitácora (canalizado, hallazgo) | pref. `avisos.*` | Configuración → Novedades | `configuracion.editar` empresa | **apagados** |

### Activos y Transporte

| Ajuste | Dónde se guarda | Pantalla | Quién | Por omisión |
|---|---|---|---|---|
| Semáforo de llaves fuera (ámbar / rojo, horas) | pref. `caseta.llaves_horas_*` | Configuración → Activos | `configuracion.editar` empresa | 0 / 0 (apagado) |
| Equipos de «Turno»: regreso y escalamiento (horas) | pref. `caseta.responsivas_horas_*` | Configuración → Activos | ídem | 0 / 0 (apagado) |
| Vouchers con cobro: correos de las tres copias | pref. `avisos_destinatarios.voucher_*` | Configuración → Activos | ídem | vacíos (quien imprime vouchers) |
| Firmas del vale de taxi en pantalla | pref. `caseta.transporte_firmas` | Configuración → Transporte | ídem | `ambas` |
| Correos que reciben el vale de taxi | pref. `avisos_destinatarios.vale_taxi` | Configuración → Transporte | ídem | vacío (quien autoriza vales) |

### Pases de salida

| Ajuste | Dónde se guarda | Pantalla | Quién | Por omisión |
|---|---|---|---|---|
| Circuito de aprobación (pasos, roles, motivos, valor) | `pases_salida_pasos` | Pases de salida → Circuito (resumen y botón «Configurar circuito» en Configuración) | `pases_salida.configurar` | Jefe de Departamento → Contraloría → Gerencia |
| Firmas en caseta, rechazo, tiempos de espera | pref. `pases_salida.*` | ídem | ídem | dos firmas; empezar desde el primer paso; sin tiempos |
| Avisos de pases | pref. `avisos.*` | Configuración → Pases de salida | `configuracion.editar` empresa | encendidos |

### Reservas de áreas

Sección **Configuración → Reservas de áreas** (al final del índice). Ver [reservas.md](reservas.md).

| Ajuste | Dónde se guarda | Pantalla | Quién | Por omisión |
|---|---|---|---|---|
| 🆕 Responsable general de reservas y su suplente | pref. `reservas.responsable_id` / `suplente_id` | Configuración → Reservas de áreas y Reservas → Configuración (mismo formulario, `PUT /reservas/ajustes`) | `reservas.configurar` empresa | sin responsable (aprueba quien tenga `reservas.aprobar` en la sede) |
| 🆕 Recordar y avisar al responsable general (horas) | pref. `reservas.recordar_horas` / `escalar_horas` | ídem | ídem | 4 / 24 |
| 🆕 IVA de las reservas para clientes | pref. `reservas.iva_aplica` / `iva_porcentaje` | ídem | ídem | sí, 16 % |
| Moneda de las cotizaciones | `empresas.moneda` | — (columna de la empresa; se usa aquí) | — | `MXN` |
| 🆕 Áreas reservables (aforo, montajes con aforo, horario, montaje y desmontaje, tarifas, foto, responsable y suplente, confirmación inmediata) | `reserva_areas`, `reserva_area_montajes` | Reservas → Configuración (enlace en Configuración) | `reservas.configurar` (su sede) | ninguna; sin datos: cualquier hora, sin costo, aprueba el responsable general |
| 🆕 Catálogos: montajes, tipos de uso, servicios con su departamento | `reserva_montajes`, `reserva_tipos_uso`, `reserva_servicios` | Reservas → Configuración (enlace) | `reservas.configurar` empresa | 7 montajes y 3 tipos de uso; sin servicios |
| 🆕 Avisos de reservas (aprobación, resultado, orden de servicio) | pref. `avisos.reserva_*` | Configuración → Reservas de áreas | `configuracion.editar` empresa | encendidos |

### Avisos por correo y Cuentas y seguridad

| Ajuste | Dónde se guarda | Pantalla | Quién | Por omisión |
|---|---|---|---|---|
| Cada aviso (20) e interruptor por módulo | pref. `avisos.*`, `avisos_modulos.*` | Configuración → cada sección; resumen en Avisos por correo | `configuracion.editar` empresa | encendidos, salvo los dos de la bitácora |
| Avisos que una persona silencia | `users.preferencias_avisos` | Mi cuenta → Mis avisos | cada persona | ninguno silenciado |
| Qué ve «Su sede» en la Bitácora de auditoría | pref. `auditoria_sede` | Configuración → Cuentas y seguridad | `configuracion.editar` empresa | `sede` |
| Movimientos sensibles a la campana | pref. `avisos.movimientos_sensibles` | Configuración → Cuentas y seguridad | ídem | encendido |
| Cierre de sesión por inactividad | `.env` `PLATAFORMA_INACTIVIDAD_MINUTOS` | Solo se **muestra** en Cuentas y seguridad (es de plataforma) | quien administra el servidor | 20 min |
| 🆕 Exigir la verificación en dos pasos a: nadie / administradores / todos ([dos-pasos.md](dos-pasos.md)) | pref. `dos_pasos` | Configuración → Cuentas y seguridad (`PUT /configuracion/dos-pasos`, Restablecer valor original) | `configuracion.editar` empresa | `nadie` (el Super Administrador siempre la necesita, no es configurable) |
| 🆕 Verificación en dos pasos de cada cuenta | `users.two_factor_*` | Mi cuenta → Verificación en dos pasos (restablecer: Usuarios) | cada persona / `usuarios.desbloquear` | sin activar |

### 🆕 Privacidad ([privacidad.md](privacidad.md))

| Ajuste | Dónde se guarda | Pantalla | Quién | Por omisión |
|---|---|---|---|---|
| Quitar automáticamente los datos vencidos (tarea de conservación) | pref. `privacidad.conservacion_activa` | Configuración → Privacidad (`PUT /configuracion/privacidad`) | `configuracion.datos_personales` empresa | **encendida** en empresas nuevas; **apagada** en las que ya existían (la migración la guardó en `false`) |
| Días: fotos de la persona / fotos de la identificación / datos de visitantes externos en registros cerrados | pref. `privacidad.fotos_persona_dias`, `fotos_identificacion_dias`, `visitantes_dias` | ídem | ídem | 90 / 90 / 365 |
| Candidatos no contratados: borrar a los N meses | pref. `recepcion.plazos.borrar_meses` (ya existía) | Enlace en Privacidad → Candidatos → Documentos y plazos | `candidatos.configurar` empresa | 12 meses |
| Solicitudes ARCO: recordar cuando falten N días hábiles | pref. `privacidad.arco_recordar_dias` | Configuración → Privacidad | `configuracion.datos_personales` empresa | 5 |
| Aviso de privacidad (versiones) | `avisos_privacidad` + pref. `recepcion.aviso_privacidad` (texto vigente) | Configuración → Privacidad (publicar versión) y Recepción → Ajustes (mismo texto, también crea versión) | `configuracion.datos_personales` / `candidatos.configurar` empresa | borrador para el abogado |
| Plazo legal de respuesta ARCO | constante `SolicitudesArco::DIAS_HABILES` | — (es de la ley) | — | 20 días hábiles |

## 2. Ajustes de plataforma (Super Administrador)

| Ajuste | Dónde se guarda | Pantalla | Por omisión |
|---|---|---|---|
| Correo SMTP (servidor, puerto, cifrado, usuario, contraseña cifrada, remitente) | `configuracion_plataforma` clave `correo` | Configuración → Plataforma → Correo de la plataforma | sin configurar |
| Respaldos de la base | archivos en `storage/app/private/respaldos` | Configuración → Plataforma → Respaldos (bloque a cargo de otro equipo) | diario después de las 3:00, 14 días |
| Versión de PHP del servidor (solo consulta) | `PHP_VERSION`, archivo de estado del despliegue; mínimas en `config/plataforma.php` (`php.minimas`) | Configuración → Plataforma → Versión de PHP del servidor | 8.5.12 / 8.4.27 / 8.3.36 / 8.2.35 |
| Identidad (nombre, logos, colores) | `configuracion_plataforma` (Identidad) | Estructura → Plataforma → Identidad (enlace en Plataforma) | «Plataforma», azul #2563eb |
| Catálogo comercial: moneda, IVA, página pública, textos, correos de solicitudes, texto legal | `configuracion_plataforma` clave `comercial` | Catálogo comercial → Ajustes (enlace en Plataforma) | MXN, 16 %, sin publicar |
| Suscripciones: prueba gratis, días de prueba, días de gracia, descuento anual, avisos antes de vencer, contacto | ídem (`prueba_habilitada`, `dias_prueba`, `dias_gracia`, `descuento_anual_porcentaje`, `avisos_dias_antes`, `contacto_suscripciones`) | Catálogo comercial → Ajustes (enlace) | sí, 14, 15, 10 %, 7 y 1 |
| Plan, sedes, usuarios y excepciones de cada cliente | `suscripciones*` | Catálogo comercial → Clientes (enlace) | — |
| 🆕 Aviso de privacidad de la página pública (con versiones) | `configuracion_plataforma` `comercial.aviso_privacidad` + `avisos_privacidad` (empresa nula) | Catálogo comercial → Ajustes | vacío |
| 🆕 Verificación en dos pasos del Super Administrador | — | Siempre obligatoria; se restablece con `php artisan plataforma:dos-pasos` | obligatoria |

## 3. Variables del servidor (`.env`)

No se cambian desde la plataforma; las administra quien instala.

| Variable | Para qué | Por omisión | ¿La querría cambiar el dueño? |
|---|---|---|---|
| `APP_NAME` | Nombre técnico (la marca visible sale de Identidad) | Plataforma | No |
| `APP_URL` | Dirección pública; enlaces de los correos | — | Solo al cambiar de dominio |
| `APP_ENV`, `APP_DEBUG` | Ambiente; nunca depuración en Producción | production / false | No |
| `DB_*` | Base de datos | — | Solo con el proveedor |
| `MAIL_*` | Respaldo si no hay correo en pantalla; **el correo real se configura en Configuración → Plataforma** | log | No |
| `SESSION_LIFETIME` | Vida de la cookie (se ajusta sola a la inactividad + 10 min) | 120 | No |
| `PLATAFORMA_INACTIVIDAD_MINUTOS` | Cierre de sesión por inactividad | 20 | Sí, ocasionalmente (ver «Pendiente») |
| `PLATAFORMA_HOSTS`, `PLATAFORMA_VERIFICAR_HOST` | Dominios aceptados | el de `APP_URL` | Solo al agregar un dominio |
| `COMERCIAL_PASARELA`, `COMERCIAL_WEBHOOKS` | Pasarela de pagos | manual / apagado | Al conectar pagos en línea |
| `PLATAFORMA_ESTADO_DESPLIEGUE` | Archivo de estado del despliegue con «PHP del cron» (Configuración → Plataforma → Versión de PHP) | se deduce de la carpeta de la aplicación | No |
| `APP_MOVIL_PAQUETE`, `APP_MOVIL_HUELLAS_SHA256` | App de Android (App Links) | `mx.smartcentinel.app` / vacío | Al publicar la app |
| Zona horaria del servidor (`config/app.php`) | Se guarda todo en UTC; cada empresa y sede muestra su hora | UTC | No (la de la empresa se cambia en Empresas) |

## 4. Constantes en código revisadas

| Constante | Valor | Decisión |
|---|---|---|
| `RelojVoBo::HORAS_AMARILLO` / `HORAS_ROJO` | 24 / 72 | 🆕 **Configurable por empresa** (General). La constante queda como valor original y para el Catálogo comercial (sin empresa). |
| `AdministradorProcedimientos::RECORDATORIO_CADA_DIAS` | 3 | 🆕 **Configurable por empresa** (Caseta y accesos). |
| `PanelRecepcion::ESPERA_AMBAR` / `ESPERA_ROJA` | 10 / 20 | Ya configurable (escalamiento en Responsables por departamento); la constante es la omisión. |
| `CaducidadCandidatos::MESES_CONSERVAR`, `DIAS_AVISO` | 6 meses, 15 días | Se quedan en código: el plazo de borrado ya es configurable (`borrar_meses`); «conservar 6 meses más» es una regla de privacidad. |
| `Llave::DIAS_AVISO_CADUCIDAD` | 30 | Se queda en código (aviso «vence pronto» de la llave; nadie lo ha pedido). |
| `Entrevistas/Kiosco::HORAS_ENLACE_CORREO`, `Autorizaciones::HORAS_ENLACE_CORREO` | 72 / 24 | Se quedan (seguridad de los enlaces de un solo uso). |
| `Delegaciones::MAX_DIAS` | 90 | Se queda (tope de seguridad). |
| 🆕 `VerificacionDosPasos::INTENTOS`, `MINUTOS_PENDIENTE`, `DIAS_RECORDAR`, `CODIGOS_RESPALDO` | 5 (cada 15 min) / 10 min / 30 días / 10 | Se quedan en código (seguridad). |
| 🆕 `SolicitudesArco::HORAS_ENLACE` | 72 | Se queda (enlace de un solo uso). |
| 🆕 `ConservacionDatos::LOTE` | 500 | Límite técnico por corrida. |
| `Respaldos::DIAS` | 14 | Fuera de alcance (bloque de Respaldos a cargo de otro equipo). |
| Lost & Found: amarillo al 70 % de los días | 70 % | Se queda: los días ya son configurables. |
| Límites técnicos (`MAX_*`, `POR_PAGINA`, tamaños de archivo, cachés) | — | No son ajustes de negocio. |

## Pendiente (no se hizo, por decisión)

- **Inactividad por empresa**: hoy es una sola para toda la plataforma (`.env`). Una caseta en kiosco podría querer más tiempo; hacerlo por empresa toca el middleware de sesión y la cookie: se propone como mejora aparte si el dueño lo pide.
- `empresas.idioma` y `empresas.moneda` existen como columnas sin uso: no se agregó pantalla para no ofrecer ajustes que no hacen nada.
