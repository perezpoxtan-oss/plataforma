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

### Avisos por correo y Cuentas y seguridad

| Ajuste | Dónde se guarda | Pantalla | Quién | Por omisión |
|---|---|---|---|---|
| Cada aviso (20) e interruptor por módulo | pref. `avisos.*`, `avisos_modulos.*` | Configuración → cada sección; resumen en Avisos por correo | `configuracion.editar` empresa | encendidos, salvo los dos de la bitácora |
| Avisos que una persona silencia | `users.preferencias_avisos` | Mi cuenta → Mis avisos | cada persona | ninguno silenciado |
| Qué ve «Su sede» en la Bitácora de auditoría | pref. `auditoria_sede` | Configuración → Cuentas y seguridad | `configuracion.editar` empresa | `sede` |
| Movimientos sensibles a la campana | pref. `avisos.movimientos_sensibles` | Configuración → Cuentas y seguridad | ídem | encendido |
| Cierre de sesión por inactividad | `.env` `PLATAFORMA_INACTIVIDAD_MINUTOS` | Solo se **muestra** en Cuentas y seguridad (es de plataforma) | quien administra el servidor | 20 min |

## 2. Ajustes de plataforma (Super Administrador)

| Ajuste | Dónde se guarda | Pantalla | Por omisión |
|---|---|---|---|
| Correo SMTP (servidor, puerto, cifrado, usuario, contraseña cifrada, remitente) | `configuracion_plataforma` clave `correo` | Configuración → Plataforma → Correo de la plataforma | sin configurar |
| Respaldos de la base | archivos en `storage/app/private/respaldos` | Configuración → Plataforma → Respaldos (bloque a cargo de otro equipo) | diario después de las 3:00, 14 días |
| Identidad (nombre, logos, colores) | `configuracion_plataforma` (Identidad) | Estructura → Plataforma → Identidad (enlace en Plataforma) | «Plataforma», azul #2563eb |
| Catálogo comercial: moneda, IVA, página pública, textos, correos de solicitudes, texto legal | `configuracion_plataforma` clave `comercial` | Catálogo comercial → Ajustes (enlace en Plataforma) | MXN, 16 %, sin publicar |
| Suscripciones: prueba gratis, días de prueba, días de gracia, descuento anual, avisos antes de vencer, contacto | ídem (`prueba_habilitada`, `dias_prueba`, `dias_gracia`, `descuento_anual_porcentaje`, `avisos_dias_antes`, `contacto_suscripciones`) | Catálogo comercial → Ajustes (enlace) | sí, 14, 15, 10 %, 7 y 1 |
| Plan, sedes, usuarios y excepciones de cada cliente | `suscripciones*` | Catálogo comercial → Clientes (enlace) | — |

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
| `Respaldos::DIAS` | 14 | Fuera de alcance (bloque de Respaldos a cargo de otro equipo). |
| Lost & Found: amarillo al 70 % de los días | 70 % | Se queda: los días ya son configurables. |
| Límites técnicos (`MAX_*`, `POR_PAGINA`, tamaños de archivo, cachés) | — | No son ajustes de negocio. |

## Pendiente (no se hizo, por decisión)

- **Inactividad por empresa**: hoy es una sola para toda la plataforma (`.env`). Una caseta en kiosco podría querer más tiempo; hacerlo por empresa toca el middleware de sesión y la cookie: se propone como mejora aparte si el dueño lo pide.
- `empresas.idioma` y `empresas.moneda` existen como columnas sin uso: no se agregó pantalla para no ofrecer ajustes que no hacen nada.
