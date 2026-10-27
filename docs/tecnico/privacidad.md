# Privacidad: conservación, solicitudes ARCO y aviso con versiones

Etapa 3 aprobada por el dueño (LFPDPPP). Ampliada con **C-37** (2026-10-26, X-01, E-09): ARCO y conservación alcanzan **todas** las tablas con datos personales (inventario con prueba) y el aviso se edita en un solo lugar.

> **Textos legales: BORRADORES PARA EL ABOGADO.** Todo texto legal de la plataforma (el aviso de privacidad original, las respuestas sugeridas y los párrafos de la sección «Borradores legales» de abajo) es un borrador que debe revisar el abogado del cliente. Por decisión del dueño, esto se marca **aquí, en la documentación técnica, y no en pantalla**: el aviso original ya no lleva la marca «Borrador — validar con su abogado»; la pantalla solo avisa que tiene **datos por completar** entre corchetes (`AjustesRecepcion::MARCA_POR_COMPLETAR`).

## Permiso

Acción nueva **`configuracion.datos_personales`** («Datos personales» del módulo Configuración) con **alcance de empresa**. La declaran Configuración → Privacidad, la bandeja de solicitudes ARCO y Mis pendientes. La migración la otorga a todo rol que hoy tiene `configuracion.editar` con alcance de empresa (Administrador y su plantilla); en instalaciones nuevas la plantilla Administrador la recibe (regla general). El Director no.

## Migración `2026_11_04_000200_privacidad_y_solicitudes_arco`

| Cambio | Para qué |
|---|---|
| `avisos_privacidad` (empresa_id **nulo = plataforma**, numero, texto, huella SHA-256, publicado_en, publicado_por, auditoría) | Versiones del aviso |
| `solicitudes_arco` (empresa_id nulo = llegó de la página de la plataforma; folio, derecho, estado, datos de contacto, detalle, token_hash, token_vence_en, verificada_en/como, vence_en, recordada_en, escalada_en, respuesta, respondida_*, acciones JSON, archivo, ip, auditoría) | Solicitudes ARCO |
| `empresas.privacidad_codigo` (único, 12 caracteres, se genera la primera vez) | Dirección pública `/privacidad/{codigo}` |
| `accesos.datos_eliminados_en` | Registro anonimizado (por conservación o ARCO) |
| `candidatos.aviso_privacidad_id`, `solicitudes_compra.aviso_privacidad_id` (FK nula, `nullOnDelete`) | Versión que aceptó cada persona |
| `empresas.preferencias.privacidad.conservacion_activa = false` en las empresas que **ya existían** | Nada se borra sin aprobación |
| Acción `datos_personales` en Configuración + permisos | Ver arriba |

`down()`: quita permisos y acción, la marca de las empresas, las FK (`dropConstrainedForeignId`), la columna de accesos, el índice único y la columna de empresas, y las dos tablas. Ningún índice que se borra sostiene una FK viva (seguro en MariaDB).

## 1. Conservación de datos personales (`ConservacionDatos`)

Ajustes por empresa en `empresas.preferencias.privacidad` (Configuración → Privacidad, `PUT /configuracion/privacidad`, auditoría `privacidad.conservacion`):

| Clave | Qué | Original | Rango |
|---|---|---|---|
| `conservacion_activa` | Tarea encendida | **sí** en empresas nuevas (sin dato); **no** en las que ya existían | — |
| `fotos_persona_dias` | Foto de la persona tomada en caseta (`accesos.foto_persona`) | 90 | 7–3650 |
| `fotos_identificacion_dias` | Foto de la identificación (`accesos.foto_identificacion`) | 90 | 7–3650 |
| `visitantes_dias` | Datos de visitantes externos (visitante, huésped, proveedor, contratista) en registros **finalizados** (desde la salida) | 365 | 30–3650 |
| `arco_recordar_dias` | Recordar solicitudes ARCO cuando falten N días hábiles (ámbar) | 5 | 1–15 |

**Candidatos no contratados:** no se duplica. Su plazo es el que ya existía (`PlazosCandidatos` `borrar_meses`, 12 meses, con aviso 15 días antes y «Conservar 6 meses más», `CaducidadCandidatos`), que corre siempre. La tarjeta lo muestra con su enlace «Configurar en Candidatos → Documentos y plazos».

**Tarea:** `php artisan plataforma:privacidad [--si-toca]` (en `despliegue/desplegar.sh`, en proceso, sin exec). Con `--si-toca` corre una vez al día después de las 2:00 (hora de Ciudad de México) y otra vez si quedó trabajo (lotes de 500 por tipo y empresa). Por cada empresa **encendida**:

- Fotos: quita el archivo y la columna en registros que no están abiertos (`pendiente`/`en_sitio`) con `entrada_at` vencida. Aplica a todos los tipos (también colaboradores).
- Visitantes: `DatosDePersona::anonimizarAcceso()` deja el registro con `nombre = «Datos eliminados por conservación»`, sin persona, persona visitada, identificación, placas, vehículo, conductor, reserva, habitación, notas ni fotos, `datos_eliminados_en`; los acompañantes también. No toca `updated_at` (no es un movimiento).
- Auditoría por empresa `privacidad.conservacion_aplicada` (conteos y días, sin datos personales) y, si se quitó algo, campana del Super Administrador `privacidad_conservacion`.
- El padrón de personas no se toca (se administra en Personas o por ARCO).

Configuración muestra «hoy se quitarían…» (conteos con los días guardados) para que el administrador sepa qué confirma al encenderla.

### C-37: plazos para los demás módulos

Migración `2026_11_10_003710_privacidad_en_todos_los_modulos`: `datos_eliminados_en` (timestamp nulo) en `lost_found_reportes_perdida`, `lost_found_entregas`, `novedades`, `reservas` y `movimientos_transporte`, y `preferencias.privacidad.plazos_ampliados = false` en las empresas que ya existían. `down()` quita la marca y las columnas.

| Clave | Qué se quita (el registro se conserva, con `datos_eliminados_en`) | Desde | Original | Rango |
|---|---|---|---|---|
| `lost_found_dias` | Reporte de pérdida: nombre, teléfono, correo (y el nombre en la descripción). Entrega: nombre, identificación, correo, firma (archivo), `persona_id` | `created_at` | 365 | 30–3650 |
| `incidentes_dias` | Tickets **resueltos** (`cerrado_en`) con involucrados, testigos, sospechoso, observados del Reporte General o accidente de huésped: `involucrados`, nombre y declaración de testigos, descripción del sospechoso, `novedad_reportes_generales.observados`; si es accidente de huésped, `DatosDePersona::anonimizarAccidente()` (abajo). **Nunca** los accidentes de colaborador (relación laboral). La descripción del hecho se conserva | `cerrado_en` | 1825 | 365–3650 |
| `reservas_dias` | Nombre, empresa, correo y teléfono del cliente (y su nombre en título, notas e indicaciones) | `fin` | 1825 | 365–3650 |
| `transporte_dias` | Firma del taxista (archivo y columna). La del guardia es personal de la empresa | `fecha` | 1825 | 365–3650 |

`ConservacionDatos::QUITA` lista lo que se cuenta y se quita; `vencidos()` arma las consultas; `aplicar()` salta los plazos de `AMPLIADOS` si la empresa no los confirmó (`ajustes()['ampliados']`, `plazos_ampliados` en preferencias, `true` si no existe la clave = empresas nuevas). Guardar la tarjeta con los plazos nuevos los confirma. Un formulario sin esos campos (anterior) los conserva (`nullable`). La auditoría `privacidad.conservacion_aplicada` guarda los conteos de cada clave.

## Inventario de datos personales (`InventarioDatosPersonales`)

`App\Services\Privacidad\InventarioDatosPersonales::TABLAS` registra **cada tabla con datos personales**: su trato (`arco`, `referencia`, `laboral`, `cuenta`, `plataforma`, `empresa`, `sin_datos`), el tipo de `DatosDePersona` que la alcanza, sus columnas personales y cómo se conserva (clave de `ConservacionDatos::DIAS` o el motivo). `PATRON` reconoce por su nombre las columnas que suelen guardar datos de una persona (nombre, teléfono, correo, CURP, RFC, NSS, firma, foto, identificación, domicilio, edad, sexo, médico, hospital, diagnóstico, placas, testigos, huésped, cliente, conductor/chofer…).

`tests/Feature/Nucleo/InventarioDatosPersonalesTest.php` **falla** si una tabla (o una columna nueva de una tabla registrada) cumple el patrón y no está en el inventario, y revisa que el inventario sea coherente (tablas y columnas que existen, tipos ARCO y plazos que existen, cada tipo de ARCO con su tabla, y que Lost & Found, Novedades, Accidentes, Reservas y Transporte los alcance ARCO). Al agregar una tabla con datos personales: decide su trato, agrégala a `DatosDePersona` si es `arco` y a `ConservacionDatos` si necesita plazo, y regístrala.

| Trato | Tablas |
|---|---|
| ARCO (directo) | personas, accesos, candidatos, colaboradores (solo consulta), lost_found_reportes_perdida, lost_found_entregas, novedades, accidente_huespedes, reservas, movimientos_transporte |
| ARCO por referencia | acompanantes_acceso, vehiculos, candidato_documentos, novedad_testigos, novedad_notas, robo_detalles, novedad_reportes_generales (`observados`: «¿Quién o quiénes fueron observados?»), accidente_dictamenes, accidente_guardavidas, accidente_firmas |
| Personal de la empresa (relación laboral) | vacantes, valores_vista_*, accidente_colaboradores, accidente_incapacidades (días de incapacidad: dato de salud, se exporta con el accidente), movimiento_transporte_pasajeros, pases_salida*, procedimiento_*, firmas_usuarios, responsivas, vouchers_reposicion, importaciones |
| Cuentas y seguridad | users, sessions, password_reset_tokens, auditoria, jobs, failed_jobs |
| Plataforma | solicitudes_arco, solicitudes_compra |
| Empresa | empresas, sedes, proveedores |

Fuera del patrón y fuera del alcance de ARCO: `notificaciones` (títulos y textos de avisos internos, que pueden nombrar a una persona; son transitorios) y el texto libre de las novedades que **no** menciona el nombre exacto que se buscó.

## 2. Solicitudes ARCO (`SolicitudesArco`, `DatosDePersona`)

**Público (sin sesión, `Publico\PrivacidadController`):**

| Ruta | Límite | Qué |
|---|---|---|
| `GET /privacidad/{codigo}` | `privacidad-ver` 60/min | Aviso vigente de la empresa con su versión y botón «Ejercer mis derechos ARCO» |
| `GET/POST /privacidad/{codigo}/solicitud` | ver / `privacidad-enviar` 3/min y 20/día por IP | Formulario de la empresa (campo trampa `sitio_web`) |
| `GET/POST /privacidad/solicitud` | ídem | Formulario de la plataforma (enlazado desde la landing y su aviso); llega sin empresa |
| `GET/POST /privacidad/verificar/{token}` | ver / `privacidad-confirmar` 10/min | Confirmar el correo: GET muestra el botón, POST confirma (enlace de 72 h, un solo uso, token de 48 caracteres guardado como SHA-256) |

Enlaces al aviso/formulario: kiosco y solicitud de empleo (`_cv.blade.php`, `$arcoUrl`), postulación de la bolsa de trabajo, landing (pie) y aviso de la plataforma.

**Flujo:** crear (`por_verificar`, correo `AvisoGeneral` con el enlace) → confirmar (`pendiente`, `vence_en` = 20 días hábiles lunes–viernes, campana `arco_solicitud`) → atender → `atendida` o `rechazada` (correo con la respuesta; resuelve la campana). Sin correo configurado se avisa igual a quien atiende y se verifica **en persona** (`PATCH …/verificar`, botón **Aprobar**).

**A quién llega:** usuarios activos con `configuracion.datos_personales` de alcance de empresa (`AvisosCuenta::usuariosConPermiso`). Las de la plataforma (empresa nula), al Super Administrador, que las **canaliza** (`PATCH …/canalizar`).

**Reloj y color (criterio 3):** `diasRestantes()` en días hábiles; verde, **ámbar** desde `arco_recordar_dias`, **rojo** el día del vencimiento o después. La tarea diaria manda un recordatorio (`arco_plazo`, una vez) y, vencida, avisa al Super Administrador (suplente) y otra vez a quien atiende. Mis pendientes: renglón «Solicitudes ARCO por atender» con el color más urgente.

**Bandeja (`/solicitudes-arco`, `SolicitudesArcoController`):** filtros Abiertas / Respondidas / Todas (+ «De la página de la plataforma» para el Super Administrador). La solicitud ajena responde 404.

**Atender (`/solicitudes-arco/{id}`):**
1. Buscar (`DatosDePersona::buscar`, dentro de la empresa): padrón de personas (nombre, teléfono, folio de identificación), bitácora de accesos externos (nombre o persona encontrada, no anonimizados), candidatos (nombre, correo, teléfono) y colaboradores (cada palabra del nombre en nombre/apellidos; **solo consulta y rectificación**). **C-37:** Lost & Found — reportes de pérdida (nombre, correo, teléfono) y entregas (nombre, correo, persona del padrón encontrada); **novedades** cuyo texto la menciona (involucrados, quién reporta, descripción, cómo sucedió, resolución, testigos, sospechoso de robo, notas, observados del Reporte General; solo por nombre); **accidentes** de huésped por su nombre (con datos médicos) y de los colaboradores encontrados (solo consulta); **reservas** (cliente: nombre o título, correo, teléfono); **transporte** (conductor = persona del padrón encontrada, u observaciones/justificación con su nombre). El teléfono se compara sin espacios, guiones, paréntesis ni «+» (últimos 10 dígitos). Máx. 100 por tipo. Quien atiende marca lo que sí es de la persona.
2. **Exportar datos** (`POST …/exportar`): ZIP con `ZipPorPartes` (el escritor de la exportación de empresa) en `storage/app/private/privacidad/arco/{empresa}/`: `datos/*.json` y `datos/*.csv` (con `Csv`, protección contra fórmulas; sin columnas sensibles) de lo marcado (+ acompañantes, postulaciones y documentos del candidato) y `archivos/` (fotos, documentos, firmas). **Descargar** (`GET …/descargar`, auditado).
3. **Rectificar:** enlace a la ficha (Personas, Candidatos, Colaboradores).
4. **Eliminar definitivamente** (`POST …/eliminar`, casilla de confirmación): bitácora anonimizada con «Datos eliminados por solicitud ARCO»; padrón anónimo y de baja (y sus registros de bitácora); ficha de candidato borrada con documentos y firma (contratados se omiten). Colaboradores nunca (`DatosDePersona::SOLO_CONSULTA`). **C-37** (el nombre buscado viaja en `criterios[nombre]` y se quita de los textos libres con `DatosDePersona::sinNombre()`, sin distinguir mayúsculas):
   - Lost & Found: `anonimizarReportePerdida()` y `anonimizarEntrega()` (borra la firma del disco privado).
   - Novedad: `quitarNombreDeNovedad()` (texto del ticket, testigos con ese nombre —nombre y declaración—, sospechoso, observados del Reporte General, notas).
   - Accidente de huésped: `anonimizarAccidente()` — `accidente_huespedes` (nombre = texto; habitación, agencia, check-in/out, país, sexo, edad, relato, motivo de asistencia y detalles de testigos en nulo), `accidente_dictamenes` (**datos médicos**: heridas, zonas, auxilios, atención, diagnóstico, hospital, traslado, médico, observaciones en nulo), `accidente_guardavidas` (lesiones del huésped en nulo; el nombre del guardavidas se queda), firma `afectado` (archivo y fila), `novedades.involucrados` nulo y `datos_eliminados_en`. Se conservan fecha, lugar y casillas (estadísticas). **No cambia el formulario de Accidentes.** Un accidente de colaborador se cuenta en `omitidos`.
   - Reserva: `anonimizarReserva()` (cliente; nombre en título, notas, indicaciones y eventos). La reserva, su estado y sus importes se conservan.
   - Transporte: `anonimizarTransporte()` (nombre en observaciones y justificación; firma del taxista si la persona era el conductor).
   - El mensaje enumera lo tratado por módulo; la auditoría `privacidad.arco_eliminado` guarda los conteos por tipo.
5. **Responder** (`POST …/responder`): **Atender** o **Rechazar** con texto (10–5,000).

**Folios** (únicos por empresa, índice `solicitudes_arco_empresa_folio_unico` de la migración `2026_11_08_000100_folio_unico_de_solicitudes_arco`): `ARCO-AAAA-NNNN` en la empresa y `ARCO-P-AAAA-NNNN` en las de la página de la plataforma; el número es el mayor del prefijo + 1 (no el conteo). Al canalizar se conserva el folio (la persona ya lo recibió por correo) y no choca con los de la empresa; el contador `ARCO-P` cuenta también las ya canalizadas. La migración renumera los repetidos que ya existían (se queda con su folio la más antigua).

**Auditoría** (folio, derecho y conteos; nunca el nombre ni el correo): `privacidad.arco_recibida`, `_verificada`, `_exportado`, `_descargado`, `_eliminado`, `_atendida`, `_rechazada`, `_canalizada`. El historial de la solicitud (`acciones`) guarda lo mismo.

## 3. Aviso de privacidad con versiones (`AvisosPrivacidad`)

- **Empresa:** el texto sigue en `preferencias.recepcion.aviso_privacidad` (lo leen kiosco, solicitud de empleo y bolsa). `vigente($empresa)` devuelve la última versión y, si el texto cambió por otro camino o no hay ninguna, crea la siguiente. Publicar desde **Configuración → Privacidad** (`POST /configuracion/privacidad/aviso`) guarda el texto y crea la versión con autor (`privacidad.aviso_publicado`); un texto igual no crea versión. Historial y «Ver texto» (`GET /configuracion/privacidad/aviso/{version}`).
- **Un solo lugar (C-37, E-09):** Recepción → Ajustes ya **no** edita el texto: muestra «versión N» (el mismo número de Privacidad, de `AvisosPrivacidad::vigente()`) con el enlace «Editar en Configuración → Privacidad» (si el rol tiene `configuracion.datos_personales`). `AjustesRecepcion::guardar()` ignora `aviso_privacidad` aunque llegue; la parte «privacidad» de ese formulario es el kiosco. Antes Recepción mostraba la huella (`c79528b1f8`) y Privacidad «versión 1»: eran dos números del mismo texto. `registrarCambio()` queda sin uso desde Recepción.
- **Texto original:** `AjustesRecepcion::PRIVACIDAD_BORRADOR` ya no empieza con «Borrador — validar con su abogado» (los textos legales se marcan como borrador aquí, no en pantalla). Las empresas que seguían con el texto original reciben la siguiente versión automáticamente la primera vez que se consulta (cambió la huella); quien ya aceptó conserva la versión que aceptó. `esBorrador()`/`porCompletar()` reconocen `[NOMBRE DE LA EMPRESA]` (y la marca anterior).
- **Plataforma:** el aviso de la página pública (Catálogo comercial → Ajustes). Su versión (empresa nula) se crea sola al primer uso o cuando cambia el texto.
- **Qué versión aceptó cada persona:** `AdministradorCandidatos::aceptarPrivacidad()` (kiosco, RR. HH., bolsa de trabajo) guarda `candidatos.aviso_privacidad_id` además de la huella de siempre; la solicitud de compra de la landing guarda `solicitudes_compra.aviso_privacidad_id`. La ficha del candidato muestra «versión N». La caseta no muestra ni hace aceptar un aviso hoy: no aplica.

## Borradores legales (para revisión del abogado)

> **BORRADOR PARA EL ABOGADO — no está en pantalla.** Propuesta de párrafos para el aviso de privacidad integral de un cliente, ahora que la plataforma trata datos de más módulos (C-37). El abogado decide si los usa, cómo y con qué consentimiento (los datos de salud son **sensibles**: LFPDPPP art. 9, consentimiento expreso y por escrito). Lo entre corchetes lo completa el cliente.

1. **Huéspedes y visitantes.** «[NOMBRE DE LA EMPRESA] trata tu nombre, identificación, fotografía, placas y datos de tu visita para controlar el acceso y la seguridad de sus instalaciones. Los conservamos [365] días después de tu salida.»
2. **Objetos perdidos.** «Si reportas un objeto perdido o recibes uno que encontramos, tratamos tu nombre, teléfono, correo, identificación y firma para devolvértelo. Los conservamos [365] días.»
3. **Incidentes y accidentes (datos sensibles).** «Si sufres un accidente o participas en un incidente en nuestras instalaciones, tratamos tus datos de identificación y, con tu consentimiento expreso, los datos de salud necesarios para atenderte (lesiones, atención médica, hospital y médico tratante). Los conservamos [5 años] después de cerrar el caso, por [MOTIVO LEGAL: responsabilidad civil, aseguradora…].» → **El abogado debe definir cómo y dónde se recaba el consentimiento expreso** (hoy el formulario de Accidentes no lo pide y, por decisión del dueño, no se modifica).
4. **Reservas y eventos.** «Si reservas un área o un evento, tratamos tu nombre, empresa, correo y teléfono para confirmarlo y cobrarlo. Los conservamos [5 años] por obligaciones fiscales.»
5. **Transporte.** «Si conduces un taxi contratado por nosotros, tratamos tu nombre, placas y firma del vale. Los conservamos [5 años].»
6. **Derechos ARCO.** «Puedes ejercer tus derechos de acceso, rectificación, cancelación u oposición en [DIRECCIÓN DE LA PÁGINA DEL AVISO] o en [CORREO]. Respondemos en un máximo de 20 días hábiles.»

Plazos sugeridos = valores originales de `ConservacionDatos::DIAS`; el abogado puede pedir otros y el cliente los cambia en Configuración → Privacidad.

### Puntos para que decida el abogado (revisión independiente del PR #86)

- **A-01 · La exportación ARCO de una novedad lleva datos de terceros.** Al exportar un ticket marcado, el ZIP incluye la fila completa del ticket (su descripción puede nombrar a otras personas), **todos** sus testigos con sus declaraciones (`novedades_testigos`), el detalle de robo (`novedades_robo`, con la descripción del sospechoso) y los observados del Reporte General. Esto puede entregar a quien pidió acceso datos personales de otras personas (LFPDPPP art. 34: el derecho de acceso es sobre los datos **propios**). Opciones: (a) exportar solo los testigos cuyo nombre coincide con el buscado y tapar en los textos los nombres de terceros; (b) que quien atiende revise el ZIP antes de entregarlo (procedimiento interno). **Hoy:** se exporta completo y quien atiende decide qué entrega. Pendiente de la decisión del abogado.
- **A-02 · La selección ARCO no se limita a lo que encontró la búsqueda.** Quien tiene el permiso «Datos personales» (con alcance de toda la empresa) puede exportar o eliminar cualquier registro **de su empresa** marcándolo, aunque la búsqueda no lo haya mostrado. El filtro de empresa sí se respeta y todo queda en la Bitácora de auditoría. Ya pasaba antes con padrón, bitácora y candidatos. Es una decisión de control interno: el abogado puede pedir que solo se acepten registros encontrados por la búsqueda de esa solicitud o que una eliminación necesite una segunda aprobación.
- **Notificaciones de la campana (A-07).** Los títulos y textos de los avisos internos pueden nombrar a una persona (por ejemplo, la visita que espera autorización). Son avisos al personal; ARCO no los busca y no se borran solos. Están en el inventario como `cuenta`. El abogado decide si basta o si se borran con un plazo.

## Pruebas

`tests/Feature/Seguridad/PrivacidadArcoTest.php`: conservación con viaje en el tiempo (30, 91 y 366 días; registros abiertos y colaboradores), nada se borra con la tarea apagada, empresas existentes apagadas y nuevas encendidas, configuración y permisos; ARCO de punta a punta (formulario, correo, confirmación, plazo de 20 días hábiles, campana y Mis pendientes, búsqueda solo en la empresa, ZIP, descarga, eliminación, respuesta, auditoría sin datos personales); plazos (ámbar, recordatorio único, vencida al Super Administrador) y verificación en persona; plataforma y canalización con campo trampa; versiones del aviso (empresa y plataforma) y la versión aceptada por el candidato.

`tests/Feature/Seguridad/PrivacidadTodosLosModulosTest.php` (C-37): un huésped con objeto en Lost & Found (reporte y entrega con firma), accidente capturado con su formulario (dictamen médico, anexo del guardavidas y firma del afectado), reserva y viaje en transporte — buscar (nombre, solo correo, solo teléfono), exportar (con firmas y datos médicos; sin la firma del guardia) y eliminar todo; vuelve a buscar sin resultados; informes, expediente y auditoría vinculados; conductor del taxi (firma) y accidente de colaborador (solo consulta); conservación de los cuatro plazos nuevos, sin aplicar hasta confirmarlos. `tests/Feature/Nucleo/InventarioDatosPersonalesTest.php`: inventario completo y coherente.
