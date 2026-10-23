# Privacidad: conservación, solicitudes ARCO y aviso con versiones

Etapa 3 aprobada por el dueño (LFPDPPP). Los textos legales que trae la plataforma son **borrador para el abogado**.

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
1. Buscar (`DatosDePersona::buscar`, dentro de la empresa): padrón de personas (nombre, teléfono, folio de identificación), bitácora de accesos externos (nombre o persona encontrada, no anonimizados), candidatos (nombre, correo, teléfono) y colaboradores (cada palabra del nombre en nombre/apellidos; **solo consulta y rectificación**). Máx. 100 por tipo. Quien atiende marca lo que sí es de la persona.
2. **Exportar datos** (`POST …/exportar`): ZIP con `ZipPorPartes` (el escritor de la exportación de empresa) en `storage/app/private/privacidad/arco/{empresa}/`: `datos/*.json` y `datos/*.csv` (con `Csv`, protección contra fórmulas; sin columnas sensibles) de lo marcado (+ acompañantes, postulaciones y documentos del candidato) y `archivos/` (fotos, documentos, firmas). **Descargar** (`GET …/descargar`, auditado).
3. **Rectificar:** enlace a la ficha (Personas, Candidatos, Colaboradores).
4. **Eliminar definitivamente** (`POST …/eliminar`, casilla de confirmación): bitácora anonimizada con «Datos eliminados por solicitud ARCO»; padrón anónimo y de baja (y sus registros de bitácora); ficha de candidato borrada con documentos y firma (contratados se omiten). Colaboradores nunca.
5. **Responder** (`POST …/responder`): **Atender** o **Rechazar** con texto (10–5,000).

**Folios** (únicos por empresa, índice `solicitudes_arco_empresa_folio_unico` de la migración `2026_11_08_000100_folio_unico_de_solicitudes_arco`): `ARCO-AAAA-NNNN` en la empresa y `ARCO-P-AAAA-NNNN` en las de la página de la plataforma; el número es el mayor del prefijo + 1 (no el conteo). Al canalizar se conserva el folio (la persona ya lo recibió por correo) y no choca con los de la empresa; el contador `ARCO-P` cuenta también las ya canalizadas. La migración renumera los repetidos que ya existían (se queda con su folio la más antigua).

**Auditoría** (folio, derecho y conteos; nunca el nombre ni el correo): `privacidad.arco_recibida`, `_verificada`, `_exportado`, `_descargado`, `_eliminado`, `_atendida`, `_rechazada`, `_canalizada`. El historial de la solicitud (`acciones`) guarda lo mismo.

## 3. Aviso de privacidad con versiones (`AvisosPrivacidad`)

- **Empresa:** el texto sigue en `preferencias.recepcion.aviso_privacidad` (lo leen kiosco, solicitud de empleo y bolsa). `vigente($empresa)` devuelve la última versión y, si el texto cambió por otro camino o no hay ninguna, crea la siguiente. Publicar desde **Configuración → Privacidad** (`POST /configuracion/privacidad/aviso`) guarda el texto y crea la versión con autor (`privacidad.aviso_publicado`); un texto igual no crea versión. Guardar otro texto en **Recepción → Ajustes** también crea versión con autor (`registrarCambio`). Historial y «Ver texto» (`GET /configuracion/privacidad/aviso/{version}`).
- **Plataforma:** el aviso de la página pública (Catálogo comercial → Ajustes). Su versión (empresa nula) se crea sola al primer uso o cuando cambia el texto.
- **Qué versión aceptó cada persona:** `AdministradorCandidatos::aceptarPrivacidad()` (kiosco, RR. HH., bolsa de trabajo) guarda `candidatos.aviso_privacidad_id` además de la huella de siempre; la solicitud de compra de la landing guarda `solicitudes_compra.aviso_privacidad_id`. La ficha del candidato muestra «versión N». La caseta no muestra ni hace aceptar un aviso hoy: no aplica.

## Pruebas

`tests/Feature/Seguridad/PrivacidadArcoTest.php`: conservación con viaje en el tiempo (30, 91 y 366 días; registros abiertos y colaboradores), nada se borra con la tarea apagada, empresas existentes apagadas y nuevas encendidas, configuración y permisos; ARCO de punta a punta (formulario, correo, confirmación, plazo de 20 días hábiles, campana y Mis pendientes, búsqueda solo en la empresa, ZIP, descarga, eliminación, respuesta, auditoría sin datos personales); plazos (ámbar, recordatorio único, vencida al Super Administrador) y verificación en persona; plataforma y canalización con campo trampa; versiones del aviso (empresa y plataforma) y la versión aceptada por el candidato.
