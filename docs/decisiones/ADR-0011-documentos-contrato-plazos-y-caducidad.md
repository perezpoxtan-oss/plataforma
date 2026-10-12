# ADR-0011 — Candidatos, fase 3: documentos para contratar, contrato, plazos, caducidad y expediente

**Fecha:** 2026-10-11 · **Estado:** aceptada (fase 3 de 4 del proceso de candidatos; sigue a [ADR-0009](ADR-0009-entrevistas-canalizar-y-elegir.md) y se integra con las etapas simplificadas de [ADR-0010](ADR-0010-recepcion-y-candidatos-simplificados.md))

## Contexto

Después de que el departamento **elige** (fase 2) faltaba lo que pasa entre la elección y el alta: reunir los documentos, firmar el contrato y contratar sin que algo se quede esperando sin que nadie se entere. Además, la crítica de uso pedía una **ficha de detalle del colaborador** y el criterio de privacidad pedía no guardar para siempre los datos de quien no fue contratado. Alcance aprobado por el dueño del proyecto.

## Decisión

1. **Lista de documentos por empresa** (`documentos_para_contratar`), no un catálogo global ni columnas fijas: cada empresa pide lo suyo, con obligatorio/opcional y orden; sin renglones rige la lista estándar (`DocumentosParaContratar::DEFECTO`). La **clave** del renglón es el `tipo` de `candidato_documentos` (ahora 40 caracteres), así los documentos ya subidos (cv, ine, comprobante, otro) no se convierten y un renglón quitado solo queda inactivo para conservar su nombre. CV, Otro y Contrato son tipos fijos fuera de la lista.
2. **«Recibido en papel»** se guarda como un `CandidatoDocumento` **sin archivo** (`ruta` null, origen `papel`, `recibido_en`, `creado_por`) en vez de una tabla aparte: el checklist, el contador, el expediente y el borrado por caducidad ya recorren esa tabla. El `down()` los elimina porque la versión anterior exige archivo.
3. **Contrato firmado** vive en la postulación (`contrato_firmado_en`, `contrato_recibido_por`, `contrato_documento_id`): es un hecho de esa contratación, no de la persona. Solo desde Elegido.
4. **Contratar avisa y se puede forzar con motivo.** No se bloquea a RR. HH. (la operación manda), pero el motivo queda en el historial y en la auditoría (`forzado`, `faltantes`, `motivo`). Al contratar, los documentos pasan al expediente del colaborador por `colaborador_id`; se conservan en la ficha.
5. **Plazos configurables por empresa** en `empresas.preferencias['recepcion']['plazos']` (sin columnas nuevas, como los criterios de entrevista), con un **sello por plazo** (`aviso_*_en`) que garantiza **un recordatorio al día** mientras siga pendiente (el de ayer queda leído y sin botones para no llenar la campana). Los sellos y el archivo no tocan `updated_at`. El color de Mis pendientes y el reloj de la ficha se calculan al vuelo (ámbar al pasar el plazo, rojo al doble). El plazo «con Recursos Humanos» cubre «Por atender» y «Con RR. HH.» (`registrado` y `con_rh`, las etapas de ADR-0010) con un solo número; el aviso dice «Sin atender» o «Sin canalizar» según la etapa.
6. **«No se presentó» se propone, no se marca solo:** la cita vencida sin registro en caseta manda a RR. HH. el botón «Marcar como no se presentó»; decidir sigue siendo de una persona.
7. **Caducidad en dos pasos:** archivar la postulación (Considerar / Rechazado a los 6 meses) conserva la ficha y la saca de la lista; borrar la ficha (12 meses sin movimiento en ficha, postulaciones ni documentos) avisa 15 días antes con **«Conservar 6 meses más»** y nunca borra a un contratado. La auditoría del borrado guarda solo conteos, sede, etapa y origen.
8. **La tarea corre en proceso** (`plataforma:candidatos-pendientes --si-toca` desde `desplegar.sh`, como las demás) y además «si toca» al abrir Candidatos y en la consulta periódica de la campana / Mis pendientes de quien edita candidatos (no en el pintado de cada pantalla, para no sumar consultas), con candado de caché de 60 s por empresa, por si el cron del hosting no corre.
9. **Expediente del colaborador** (`/colaboradores/{c}/expediente`) es una pantalla de solo lectura que **reúne** lo que ya existe (Colaboradores, Candidatos, Accesos, Llaves, Pases, Responsivas, Procedimientos) y muestra cada bloque solo si el usuario puede ver ese módulo; los documentos exigen `colaboradores.datos_personales`. No se crean permisos nuevos.
10. **Sin permisos nuevos:** configurar usa `candidatos.configurar` con alcance de empresa (como los ajustes de Recepción); documentos, contrato y conservar usan `candidatos.editar`.

## Consecuencias

- La lista de documentos también decide qué deduce la caseta con «Ya tiene cita» (ADR-0010): un elegido viene a «Documentos» mientras le falte un obligatorio y a «Firma» cuando ya los tiene todos.
- Migración `2026_10_22_000300_candidatos_fase_3_documentos_plazos_y_caducidad`: corre después de `2026_10_22_000100` (etapas) y `2026_10_22_000200` (solicitud corta) de ADR-0010; no depende de sus datos, pero el orden queda fijo.
- `POST /candidatos/{c}/contratar` acepta `forzar` y `motivo_forzar`; las pruebas que contratan sin expediente deben enviarlos.
- Un revert del código deja las columnas y la tabla nuevas sin usarse; la versión anterior sigue funcionando. `migrate:rollback` quita las columnas, borra los «recibidos en papel» y vuelve `tipo` a 20 caracteres (las claves de la lista estándar caben).
- Las fichas archivadas o por caducar siguen abriéndose por su enlace; la lista las muestra con sus filtros.
- La fase 4 (cierre de vacante desde el aviso y lo que decida el dueño) parte de aquí.
