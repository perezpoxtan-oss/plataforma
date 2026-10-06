# Procedimientos (manual de procedimientos operativos)

Módulo **nuevo**: no existe en SEGCAT. Es el manual de qué hacer ante un robo, un accidente, un incendio, un huracán, la entrega de turno, la pérdida de una llave… Cada procedimiento tiene **versiones** con un circuito **Borrador → En revisión → Publicado** (aprobado con firma), y cada persona a la que aplica firma un **acuse «Leí y entendí»**. Menú **Operación → Consulta → Procedimientos** (`/procedimientos`).

## Piezas

| Pieza | Archivo |
|---|---|
| Migración | `2026_10_12_000100_crear_procedimientos.php` (tablas y permisos de los roles existentes) |
| Modelos | `Procedimiento` (lector universal: `codigo_qr`, `etiqueta_nfc`, clave legible), `ProcedimientoCategoria`, `ProcedimientoVersion`, `ProcedimientoPaso`, `ProcedimientoAplicacion`, `ProcedimientoAdjunto`, `ProcedimientoAcuse`, `ProcedimientoEvento`, `ProcedimientoRecordatorio` |
| Reglas | `app/Services/Procedimientos/AdministradorProcedimientos.php` |
| Controlador | `app/Http/Controllers/Seguridad/ProcedimientoController.php` |
| Vistas | `resources/views/seguridad/procedimientos/`: `index` (fichas), `_ficha`, `_formulario` (Nuevo / Editar borrador) + `_paso`, `_categorias`, `show` (Contenido · Versiones · Acuses), `_contenido`, `_dialogos` (enviar, aprobar, pedir cambios, retirar), `_firma-propia`, `leer` (modo lectura + acuse), `imprimir` (hoja carta) |
| Correo | `app/Mail/AvisoProcedimiento.php` + `resources/views/correos/procedimiento.blade.php`; envío con `AvisosCorreo::procedimiento()` |
| Comando | `plataforma:procedimientos-pendientes --si-toca` (`app/Console/Commands/RecordarProcedimientosPendientes.php`), llamado por `despliegue/desplegar.sh` junto al de pases vencidos |
| Inicio | Bloque "Procedimientos" en `PanelController` (por aprobar y por leer) |
| JS / CSS | bloque "Procedimientos" al final de `public/js/plataforma.js`, `public/css/plataforma.css` y `public/css/modos-pantalla.css` (reutiliza las piezas de Pases de salida v2) |
| Pruebas | `tests/Feature/Seguridad/ProcedimientosTest.php` |
| Demo | `CrearDatosDemo::procedimientosDemo()` |

## Tablas

**`procedimiento_categorias`**: `empresa_id`, `nombre` (único por empresa, sin importar mayúsculas), `color` (`rojo`, `naranja`, `amarillo`, `verde`, `azul`, `morado`, `gris`), `orden`, `activo`, autoría. Al abrir el módulo por primera vez se crean: **Emergencias** (rojo, primero), Operación de caseta, Accesos, Protección civil y Administrativo.

**`procedimientos`** (la carpeta): `clave` (única por empresa, mayúsculas, p. ej. `PRO-SEG-001`; no cambia desde la versión 2), `categoria_id`, `titulo` y copias para listar rápido: `estado` (`borrador` · `en_revision` · `publicado` · `retirado`), `version_vigente`, `publicado_en`, `version_trabajo`, `estado_trabajo`; `codigo_qr` / `etiqueta_nfc` (lector universal); retiro (`retirado_en`, `retirado_por`, `motivo_retiro`); autoría.

**`procedimiento_versiones`**: `numero` (único por procedimiento), `estado` (`borrador` · `en_revision` · `publicada` · `reemplazada` · `descartada`), contenido (`categoria_id`, `titulo`, `objetivo`, `alcance`, `responsables`, `notas`, `resumen_cambios`), `aplica_todas_sedes`, envío (`enviado_en`, `enviado_por`), aprobación (`aprobado_en`, `aprobado_por`, `aprobador_nombre`, `aprobador_cargo`, `firma_ruta` en el disco privado, `comentario_aprobacion`), último rechazo (`rechazado_en`, `rechazado_por`, `motivo_rechazo`), `reemplazada_en`, autoría (`creado_por` = autor).

**`procedimiento_pasos`**: `version_id`, `orden`, `texto`, `responsable` (opcional), `critico`.

**`procedimiento_aplicaciones`**: una fila por elemento de «A quién aplica»: `tipo` (`sede` · `departamento` · `puesto`) + `sede_id` / `departamento_id` / `puesto_id`. Tiene columna propia (`tipo`), así que **no** es tabla puente: una sede, departamento o puesto usado aquí no se puede «Eliminar definitivamente».

**`procedimiento_adjuntos`**: `version_id`, `nombre`, `ruta` (disco privado `procedimientos/<empresa>/adjuntos/<año>/<mes>/<uuid>.<ext>`), `tipo` (`pdf` · `imagen`), `mime`, `tamano`.

**`procedimiento_acuses`**: `version_id`, `user_id`, `colaborador_id` (en `AdministradorColaboradores::REFERENCIAS`: se mueve al unir duplicados), `nombre`, `firma_ruta` (privada), `ip`, `leido_en`. Único por `(version_id, user_id)`.

**`procedimiento_eventos`** (no se edita ni se borra): `version_id` (nulo para retiro/reactivación), `evento` (`creado`, `editado`, `enviado`, `rechazado`, `aprobado`, `reemplazada`, `descartado`, `retirado`, `reactivado`), `comentario`, `user_id`, `usuario_nombre`, `ip`.

**`procedimiento_recordatorios`**: último recordatorio de acuses enviado a cada usuario (`unique(empresa_id, user_id)`).

## Máquina de estados

```
Versión:  borrador --enviar--> en_revision --aprobar (firma)--> publicada --se aprueba la siguiente--> reemplazada
          en_revision --pedir cambios (comentario obligatorio)--> borrador
          borrador de una versión ≥ 2 --descartar--> descartada
Procedimiento: publicado --retirar (motivo)--> retirado --reactivar--> publicado
```

- **Enviar**: «Editar» en todas las sedes de la versión, o su autor con «Crear». Debe tener al menos un paso; desde la versión 2 pide **resumen de cambios**.
- **Aprobar / Pedir cambios** (`AdministradorProcedimientos::motivoNoAprueba()`): permiso «Aprobar»; **no** es quien la escribió ni quien la envió; su alcance cubre **todas** las sedes de la versión («Todas las sedes» pide alcance de empresa). El formulario manda `version_id`: si ya no está en revisión (otro la aprobó o la rechazó) responde «Esta versión ya no está en revisión…» (no se aprueba dos veces). La firma es la del componente `componentes.firma` o la **firma guardada** del usuario (`firmas_usuarios`, la misma de Pases de salida: se reutiliza `AdministradorPasesSalida::firmaGuardada()` / `guardarFirmaUsuario()`); se copia al disco privado.
- **Versión nueva** (`nuevaVersion`): solo de un procedimiento publicado sin otra versión en trabajo; copia contenido, pasos, aplicación y **copia los archivos** adjuntos (quitar uno del borrador no toca la versión publicada). La vigente **sigue rigiendo** hasta que se aprueba la nueva; al aprobarla, la anterior queda `reemplazada`.
- **Una versión publicada no se edita**: `PUT /procedimientos/{id}` sin borrador responde «La versión publicada no se edita: usa «Nueva versión»…».
- **Retirar**: «Eliminar» con alcance en todas sus sedes, motivo obligatorio; descarta la versión en trabajo. Ya no se lista para quien solo consulta ni se pide firmar. **Reactivar** vuelve a publicado.
- Cada cambio corre en una transacción con el procedimiento y la versión bloqueados (`lockForUpdate`).

## A quién aplica y acuse «Leí y entendí»

- **Sedes**: todas, o las elegidas. Un colaborador entra si su sede física **o** alguna sede adicional está entre las elegidas (un colaborador corporativo, sin sede, solo entra en «Todas las sedes»).
- **Departamentos y puestos** (opcional): sin ninguno, todo el personal de esas sedes; con alguno, basta estar en **alguno** de los departamentos **o** tener **alguno** de los puestos.
- **Deben firmar** (`obligados()`): usuarios **activos** de la empresa con **colaborador activo** ligado (no unido como duplicado) y permiso `procedimientos.ver`, que entran en la aplicación de la versión **vigente**. Una versión nueva pide firmar otra vez.
- **Acuse** (`acusar()`): procedimiento publicado; `version_id` debe ser la vigente (si se publicó otra mientras leía: «Se publicó la versión N mientras leías…»); casilla `entendido` obligatoria; firma (dibujada o guardada). Una vez por versión. Cualquier usuario que pueda verlo puede firmarlo; en la pestaña Acuses aparece como «firmó sin estar en la lista».
- **Pestaña Acuses** (con «Editar» o «Aprobar»): % de cumplimiento de la vigente, filtros por sede y departamento, quién falta y quién firmó (con fecha y firma). Con alcance de sede solo se ve a las personas de sus sedes. **Exportar** a CSV con `App\Support\Csv` (a prueba de fórmulas).

## Avisos por correo

| Clave (`Empresa::AVISOS`) | Cuándo | A quién |
|---|---|---|
| `procedimiento_publicado` | Se aprueba una versión | Quienes deben firmarla |
| `procedimiento_recordatorio` | Comando diario (en proceso) | Cada usuario con acuses pendientes de versiones publicadas **antes de hoy**, como máximo cada **3 días** (`RECORDATORIO_CADA_DIAS`), después de las 8:00 de la empresa (`--si-toca`) |

Se encienden o apagan en Configuración → Avisos por correo (activos por defecto). Solo salen si el correo de la plataforma está configurado. El de publicación se envía después de responder (`defer`).

## Endpoints

| Método y ruta | Permiso | Qué hace |
|---|---|---|
| `GET /procedimientos?filtro=&q=&categoria=` | `ver` | Fichas (30 por página, Emergencias primero). Filtros: `todos`, `por_leer`, `por_aprobar` (con «Aprobar»), `publicados`, `revision`, `borradores`, `retirados` (estos con crear/editar/aprobar). Búsqueda en clave, título, objetivo y pasos. Un QR leído (`/e/{codigo}`) abre el modo lectura. `?nuevo=1` abre «Nuevo Procedimiento» |
| `GET /procedimientos/por-leer` | `ver` | Mis procedimientos por leer y firmar |
| `POST /procedimientos` | `crear` | Alta (v1 en borrador; `enviar=1` la manda a revisión). Archivos `adjuntos[]` |
| `GET /procedimientos/{id}?version=N&pestana=` | `ver` | Ficha: Contenido · Versiones · Acuses |
| `PUT /procedimientos/{id}` | `editar` (o `crear` si es su autor) | Editar el borrador; `quitar_adjuntos[]`, `enviar=1` |
| `GET /procedimientos/{id}/leer?version=N` | `ver` | Modo lectura y acuse |
| `GET /procedimientos/{id}/imprimir?version=N` | `ver` | Hoja carta con logo, QR, pasos y firma de aprobación; «NO VIGENTE» si no es la vigente |
| `POST /procedimientos/{id}/nueva-version` | `editar` | Versión nueva en borrador |
| `POST /procedimientos/{id}/enviar` | `crear` o `editar` | A revisión (`resumen_cambios`) |
| `POST /procedimientos/{id}/aprobar` | `aprobar` | `version_id`, firma, `comentario`. Límite 30/min |
| `POST /procedimientos/{id}/rechazar` | `aprobar` | `version_id`, `comentario` obligatorio |
| `POST /procedimientos/{id}/descartar` | `editar` | Descarta el borrador de una versión ≥ 2 |
| `POST /procedimientos/{id}/retirar` · `/reactivar` | `eliminar` | Retiro con `motivo` / reactivación |
| `POST /procedimientos/{id}/acuse` | `ver` | `version_id`, `entendido`, firma. Límite 30/min |
| `GET /procedimientos/{id}/acuses/exportar?sede=&departamento=` | `editar` o `aprobar` | CSV |
| `GET /procedimientos/{id}/acuses/{acuse}/firma` | su dueño, o `editar`/`aprobar` con la persona en su alcance | Imagen de la firma |
| `GET /procedimientos/{id}/versiones/{version}/firma` | `ver` (versión visible) | Firma de aprobación |
| `GET /procedimientos/{id}/adjuntos/{adjunto}` | `ver` (versión visible) | PDF (descarga) o imagen; `nosniff` y `CSP: sandbox` |
| `GET /procedimientos/mi-firma` | `ver` | Mi firma guardada (solo la ve su dueño) |
| `POST /procedimientos/categorias` · `PUT /procedimientos/categorias/{categoria}` | `editar` con alcance de empresa | Catálogo de categorías |

Todo responde **404** si el procedimiento es de otra empresa o está fuera de su alcance (y, para quien solo consulta, si no está publicado); **403** si falta el permiso o la regla del circuito no lo deja (la ficha explica por qué en un recuadro amarillo).

## Permisos y alcance

- Acciones del catálogo: `ver`, `crear`, `editar`, `eliminar` (retirar / reactivar), `aprobar` y `borrar` (Eliminar definitivamente, solo un borrador **nunca publicado**; ver `RegistroBorrado`).
- **Plantillas** (decisión del dueño del proyecto; `RolesPlantillaSeeder::PROCEDIMIENTOS` y la migración para bases existentes): Administrador todo (empresa, incluido `borrar`); Director todo salvo `borrar` (empresa); Jefe de seguridad todo salvo `borrar` (sede); Supervisor ver, crear y editar (sede); Agente y Asistente ver (sede); Recursos Humanos ver (empresa).
- **Ver**: con alcance de sede, los procedimientos cuya versión vigente o en trabajo aplica a **todas** las sedes o a alguna de las suyas. Quien solo tiene «Ver» (sin crear, editar ni aprobar) ve **solo los publicados** y solo la versión vigente.
- **Crear / editar / aprobar / retirar**: la versión debe aplicar **solo** a sus sedes; «Todas las sedes» pide alcance de empresa. El formulario de quien tiene alcance de sede no ofrece «Todas las sedes» y trae sus sedes marcadas.
- «Solo los propios»: además, solo los procedimientos que él creó.

## Adjuntos

PDF, JPG, PNG o WEBP, máximo 5 MB cada uno y 8 por versión. Un PDF se acepta solo si su contenido empieza con `%PDF-`; las imágenes se **re-dibujan** con `App\Support\ImagenSegura` (sin metadatos ni código pegado). Se guardan en el disco **privado** y solo se sirven por el controlador, con permiso y alcance.

## Lector universal

Tipo `procedimiento` en `config/lector.php` (`Procedimiento` implementa `Identificable`): el QR de la hoja impresa (`/e/{codigo_qr}`) abre el modo lectura; también se encuentra tecleando o leyendo su clave. En la lista, «Escanear el QR de una hoja impresa» usa `componentes.lector` (cámara, NFC o lector USB/Bluetooth).

## Auditoría

`procedimientos.creado`, `.actualizado`, `.enviado`, `.aprobado`, `.rechazado`, `.version_creada`, `.version_descartada`, `.retirado`, `.reactivado`, `.acuse_firmado`, `.categoria_creada`, `.categoria_actualizada`, `.eliminado_definitivo` (Eliminar definitivamente) y `pases_salida.firma_guardada` cuando alguien guarda su firma. Además, el historial propio en la pestaña **Versiones** (quién, cuándo, comentario e IP).

## Qué se corrigió respecto a SEGCAT

SEGCAT no tenía manual de procedimientos: los procedimientos vivían en papel o en archivos sueltos, sin saber cuál era la versión vigente ni quién los había leído. Ahora:

- Cada procedimiento tiene **versiones** con autor, aprobador (con firma) y resumen de cambios; una versión publicada **no se edita** y la anterior sigue rigiendo hasta que se aprueba la nueva.
- La **aprobación** la hace otra persona con permiso, nunca quien lo escribió; el rechazo exige explicar qué corregir.
- Se sabe **quién leyó y entendió** cada versión (firma y fecha) y quién falta, con % de cumplimiento, filtros y exportación, avisos y recordatorios por correo.
- La caseta lo consulta **rápido** en el celular (modo lectura con letra grande y puntos críticos en rojo) o escaneando el QR de la hoja pegada en la caseta.
- Firmas y adjuntos en el **disco privado**, servidos solo con permiso; todo con alcance por empresa y sede.

## Pruebas

`tests/Feature/Seguridad/ProcedimientosTest.php`: alta, validación en español dentro del diálogo, clave única por empresa, edición de borradores, circuito completo con firma privada, el autor (o quien envía) no aprueba, no se aprueba dos veces, rechazo con comentario obligatorio, versiones y nuevo acuse, descartar / retirar / reactivar, acuse con casilla y firma guardada, a quién aplica (sedes, sede adicional, departamentos o puestos), CSV, correos y recordatorio en proceso, avisos de Inicio, adjuntos privados y validados, Agente (lee y firma, no crea ni ve borradores), alcance de sede, otra empresa (404), categorías, lector universal, Eliminar definitivamente y datos demo. Además entra solo en `tests/Feature/SeguridadAuditoria` y en `EliminarDefinitivoTest`.
