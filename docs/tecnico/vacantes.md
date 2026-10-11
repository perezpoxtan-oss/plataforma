# Vacantes y bolsa de trabajo pública

Lección 36. Módulo nuevo `vacantes` (área Recursos Humanos, menú Recursos Humanos → «Recepción y candidatos», **primero** de la sección). La solicitud que llena el candidato es la de [solicitud-empleo.md](solicitud-empleo.md).

## Piezas

| Pieza | Archivo |
|---|---|
| Migración | `database/migrations/2026_10_17_000200_crear_vacantes.php` (`vacantes`, `sede_vacante`, `candidatos.vacante_id`, `empresas.bolsa_slug`; en una base existente crea el módulo, lo acomoda en el menú y otorga permisos) |
| Modelo | `App\Models\Vacante` (`ESTADOS`, `TRANSICIONES`, `MOTIVOS_CIERRE`, `CONTRATOS`, `JORNADAS`, `PERIODOS`, scopes `aplicanEn()` y `vigentes($hoy)`, `sueldoTexto()`, `sedesTexto()`); `Candidato::vacantePublicada()` |
| Reglas | `app/Services/Vacantes/AdministradorVacantes.php` (alcance, alta, edición, estados, eliminar, `hoy($empresa)`), `BolsaTrabajo.php` (ajustes, slug, consulta pública, `postular()`) |
| Controladores | `RecursosHumanos/VacanteController` (pantalla, cartel, ajustes de la bolsa), `Publico/EmpleosController` (sin sesión) |
| Vistas | `rh/vacantes/` (`index`, `_formulario`, `_ajustes-bolsa`, `cartel`), `empleos/` (`plantilla`, `index`, `show`, `postular`, `gracias`) |
| Caseta | `rh/recepcion/_ingreso-acceso.blade.php` + `RecepcionEnCaseta::vacante()` (bloques delimitados «Vacantes») |
| Catálogo | `CatalogoSeeder` (módulo + `RUTAS`), `MenuSeeder` (sección «Recepción y candidatos»), `RolesPlantillaSeeder::VACANTES` |
| JS / CSS | bloque «Solicitud de empleo formal y Vacantes» al final de `plataforma.js`, `plataforma.css` y `modos-pantalla.css` |
| Pruebas | `tests/Feature/Seguridad/VacantesTest.php` |

## Tablas

- **`vacantes`**: `empresa_id`, `codigo` (10 caracteres al azar, único: dirección pública), `titulo`, `puesto_id`, `departamento_id`, `plazas` (por omisión 1; al elegir el jefe a tantas personas como plazas, las demás postulaciones de la vacante con el departamento pasan a «considerar»), `jefe_ve_cv` (fase 2 de candidatos: quien entrevista ve el CV en PDF; apagado por omisión, migración `2026_10_20_000400_vacantes_jefe_ve_cv.php`), `todas_las_sedes`, `tipo_contrato`, `jornada`, `turno_id`, `horario`, `sueldo_min`/`sueldo_max`/`sueldo_periodo`/`sueldo_a_tratar`, `descripcion`, `requisitos` y `prestaciones` (JSON, uno por renglón, máx. 15), `escolaridad_minima`, `experiencia`, `fecha_publicacion`, `fecha_cierre`, contacto (`contacto_nombre`, `contacto_telefono`, `contacto_correo`), `estado`, `cierre_motivo`, `publicada_en`, `cerrada_en`, auditoría.
- **`sede_vacante`**: sedes de la vacante (si no es de todas).
- **`candidatos.vacante_id`**: vacante a la que aplica (la columna de texto `vacante` se conserva).
- **`empresas.bolsa_slug`**: `nombre-de-la-empresa-xxxxxx` (6 al azar). Ajustes en `empresas.preferencias.bolsa_trabajo`: `activa`, `presentacion`, `indexar`.

## Estados

```
Borrador ─▶ Publicada ⇄ Pausada
   │            │          │
   └────────────┴──────────┴─▶ Cerrada (cubierta | cancelada) ─▶ Borrador (reabrir)
```

- **Publicar** pide sede y que la fecha de cierre no haya pasado; sin fecha de publicación pone la de hoy (zona de la empresa).
- **Cerrar** pide el motivo; un borrador solo se **cancela**.
- Prohibidas (aviso en rojo): Borrador → Pausada, Publicada/Pausada → Borrador, Cerrada → Publicada/Pausada, estados inventados. Cada cambio es `UPDATE … WHERE estado = <esperado>` («Otra persona acaba de cambiar esta vacante»).
- **Vigente** (lo que ve internet y la caseta): publicada, `fecha_publicacion ≤ hoy ≤ fecha_cierre` (hoy = zona horaria de la empresa). Una publicada con fecha de cierre pasada se marca «Venció» en la ficha.
- **Título único** entre vacantes no cerradas (sin importar mayúsculas).
- **Eliminar** solo si nadie está ligado a ella; si no, se cierra.

## Alcance y permisos

`vacantes.ver|crear|editar|eliminar|configurar`. Plantillas: Recursos Humanos y Administrador todo (empresa); Director ver, crear, editar y eliminar (empresa, sin configurar la bolsa); Jefe de seguridad, Asistente, Supervisor y Agente solo **ver** (sede). En una base existente la migración da: todo a quien tiene `candidatos.crear`; ver/crear/editar/eliminar a quien tiene `recepcion_rh.ver`; ver a quien tiene `accesos.crear` (mismo alcance).

- Con alcance de sede se ven las vacantes de sus sedes o de «todas las sedes»; se crean y modifican solo las que son **únicamente** de sus sedes («todas» pide alcance de empresa). Su sede viene preseleccionada al crear.
- Sin `vacantes.editar` (la caseta) solo se ven las **publicadas** y el cartel.
- `configurar` (bolsa pública) exige alcance de empresa.
- Otra empresa u otra sede → **404**.

## Bolsa de trabajo pública (sin sesión)

| Método y ruta | Límite | Qué hace |
|---|---|---|
| `GET /empleos/{empresa}` | 120/min (`empleos`) | Vacantes vigentes con búsqueda y filtros de sede y área |
| `GET /empleos/{empresa}/{vacante}` | 120/min | Detalle y «Postularme» |
| `GET /empleos/{empresa}/{vacante}/postular` | 120/min | Solicitud completa + CV (siempre `noindex`, `no-store`) |
| `POST /empleos/{empresa}/{vacante}/postular` | **6/min** (`empleos-postular`, contador propio por IP) | Crea el candidato |
| `GET /empleos/{empresa}/gracias` | 120/min | Confirmación (solo después de enviar) |

- Bolsa apagada, empresa desactivada, slug o código inventados, vacante no vigente → **404** (igual para todos: no se puede saber qué existe).
- **Seguridad**: CSRF, campo trampa `sitio_web` (si llega con algo, se contesta «gracias» sin guardar), CV PDF/JPG/PNG ≤ 5 MB revisado por contenido (`DocumentosCandidato`, imágenes con `ImagenSegura`), firma con `Firmas`, todo en el disco privado; `X-Robots-Tag: noindex` salvo que la empresa marque «Permitir que Google la muestre»; `Referrer-Policy: no-referrer`; sin JavaScript en línea. Nunca muestra candidatos.
- **Postularse** (`BolsaTrabajo::postular`): misma validación del kiosco (`validarCv(..., completa: true)`) + sede (si la vacante tiene varias) → candidato `origen = web`, etapa Registrado, «Por revisar», `vacante_id`, departamento y puesto de la vacante, privacidad y firma con medio `web`, `medio_vacante = bolsa_web`. **No** se crea en el Padrón de personas (lo hace RR. HH. si avanza). Auditoría `candidatos.postulacion` (sin usuario, con IP). Aviso `candidato_postulacion` en la campana y correo (aviso `candidato_llegada` de Configuración) a quien tiene `candidatos.editar` en esa sede, sin datos sensibles.

## Pantalla `/vacantes` (autenticada)

| Método y ruta | Permiso | Qué hace |
|---|---|---|
| `GET /vacantes?q=&estado=&sede=&departamento=` | `vacantes.ver` | Fichas con contador por estado y candidatos y plazas por vacante (enlace a la tabla de candidatos) |
| `GET /vacantes/{v}/candidatos` | `vacantes.ver` + (`candidatos.ver` o `candidatos.evaluar`) | Fase 2: «Candidatos de esta vacante» (nombre, etapa, promedio RR. HH., promedio departamento, resultado, entrevistador; plazas y cubiertas). RR. HH.: todas las postulaciones de sus sedes (`AdministradorCandidatos::limitar`); quien entrevista: solo las suyas (`Entrevistas::limitar`) |
| `POST /vacantes` (`publicar=1` → «Guardar y publicar») | `vacantes.crear` | Alta (borrador) |
| `PUT /vacantes/{v}` | `vacantes.editar` + alcance | Editar (un solo diálogo que se llena con `data-accion="editar-registro"`) |
| `PATCH /vacantes/{v}/estado` | `vacantes.editar` + alcance | Publicar, Pausar, Reanudar, Cerrar (motivo), Reabrir |
| `DELETE /vacantes/{v}` | `vacantes.eliminar` + alcance | Solo sin candidatos |
| `GET /vacantes/{v}/cartel` | `vacantes.ver` + alcance | Cartel carta con QR a la vacante (sin QR si no está publicada o la bolsa está apagada) |
| `PUT /vacantes/bolsa` | `vacantes.configurar` (empresa) | Encender/apagar, texto de presentación, indexar |

«Copiar enlace» (`data-copiar-enlace`, portapapeles o ventana para copiar a mano) y «WhatsApp» (`https://wa.me/?text=`) aparecen cuando la vacante está publicada y la bolsa encendida.

## Integraciones

- **Caseta**: «Viene como candidato» → lista «¿A qué vacante viene?» con las vigentes (acotadas a la sede elegida con el mismo filtro `data-depto-recepcion`). El servidor revisa que sea vigente y de esa sede; si la caseta no eligió puesto o departamento, se toman de la vacante.
- **Candidatos**: filtro por vacante, «Vacante: …» en la ficha y en la lista, columna «Vacante» en el CSV; RR. HH. liga o cambia la vacante en «Editar solicitud» (publicadas o en pausa; la que ya tenía se conserva aunque esté cerrada).
- **Configuración**: sección «Bolsa de trabajo en internet» (misma vista parcial).
- **Entrevistas (fase 2)**: «El jefe puede ver el CV» abre `GET /entrevistas/{p}/cv`; las plazas deciden cuándo se cubre la vacante (ver [candidatos.md](candidatos.md)).

## Auditoría

`vacantes.creado`, `.actualizado`, `.publicada`, `.pausada`, `.reanudada`, `.cerrada`, `.reabierta`, `.eliminado`, `.bolsa_configurada`; `candidatos.postulacion`.

## Qué se corrigió respecto a SEGCAT

SEGCAT no tenía vacantes: se pegaban hojas impresas a mano en la entrada, la caseta no sabía a qué vacante venía cada persona y RR. HH. no podía medir cuántos llegaban por cada una. Ahora la vacante se publica una vez y aparece en internet, en el cartel con QR y en la lista de la caseta; cada candidato queda ligado a su vacante.

## Pendiente (no implementado)

«Solicitud de vacante» del jefe de departamento con aprobación de RR. HH., y que el jefe vea solo las de su departamento: requiere un rol de jefe de departamento que hoy no existe en las plantillas (los responsables están en `departamento_responsables`, ligados a autorizaciones). Hoy el jefe de departamento la ve si su rol tiene `vacantes.ver`. Cerrar la vacante como «Cubierta» al cubrir las plazas sigue siendo manual (RR. HH.).
