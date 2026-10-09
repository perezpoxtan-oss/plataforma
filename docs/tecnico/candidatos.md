# Candidatos (ficha / CV, etapas, contratar y kiosco)

Módulo nuevo (no existía en SEGCAT). Decisión: [ADR-0007](../decisiones/ADR-0007-recepcion-kiosco-y-autorizaciones.md). El panel de Recepción, las notificaciones y las autorizaciones están en [recepcion-y-autorizaciones.md](recepcion-y-autorizaciones.md).

## Piezas

| Pieza | Archivo |
|---|---|
| Migración | `database/migrations/2026_10_14_000200_crear_candidatos_y_autorizaciones.php` |
| Modelos | `Candidato`, `CandidatoDocumento`, `CandidatoEvento`, `EnlaceKiosco` |
| Reglas | `app/Services/Candidatos/AdministradorCandidatos.php` (alta, CV, etapas, contratar, eliminar), `DocumentosCandidato.php` (archivos privados), `Kiosco.php`, `CambioNoPermitido.php` |
| Controladores | `app/Http/Controllers/RecursosHumanos/CandidatoController.php`, `KioscoController.php` (público) |
| Vistas | `resources/views/rh/candidatos/` (`index`, `show`, `_cv`, `_fila-*`), `resources/views/kiosco/` |
| Pruebas | `tests/Feature/Seguridad/CandidatosYAutorizacionesTest.php` |

## Tablas

### `candidatos`
`empresa_id`, `sede_id`, `persona_id` (Padrón de personas), `acceso_id` (registro en caseta), `departamento_id`, `puesto_id`, `vacante` (texto si no está en el catálogo), contacto (`nombre_completo`, `telefono`, `correo`, `fecha_nacimiento`, `ciudad`), CV (`escolaridad`, `experiencia`, `referencias` en JSON —máximo 6 renglones—, `habilidades`, `idiomas`, `disponibilidad`, `disponibilidad_notas`, `pretension`), `notas_rh`, `etapa`, `motivo_descarte`, `origen` (caseta | rh | kiosco), `autocaptura_pendiente`/`autocaptura_en`, privacidad (`privacidad_aceptada_en`, `privacidad_ip`, `privacidad_version` = SHA-256 del texto, `privacidad_medio`), tiempos (`llegada_en`, `avisado_rh_en`, `revision_en`, `aprobado_rh_en`, `enviado_departamento_en`, `respuesta_departamento_en`, `entrevista_en`, `decision_en`/`decision_por`, `contratado_en`), `colaborador_id`, auditoría.

### `candidato_documentos`
`tipo` (cv, ine, comprobante, otro), `nombre_original`, `ruta` (disco privado `candidatos/<empresa>/<candidato>/<uuid>.<ext>`), `mime`, `bytes`, `origen` (rh | kiosco).

### `candidato_eventos`
Historial: `evento`, `etapa_anterior`, `etapa_nueva`, `comentario`, `user_id` (null = el candidato en el kiosco).

### `enlaces_kiosco`
`token_hash` (SHA-256; el token nunca se guarda), `codigo` (6 caracteres sin 0/O ni 1/I/L), `expira_en`, `usos_maximos`, `usos`, `ultimo_uso_en`, `revocado_en`.

## Etapas

```
Registrado ─▶ En revisión RR. HH. ─▶ Aprobado por RR. HH. ─▶ Entrevista ─▶ Seleccionado ─▶ Contratado
                     │                      │ (responde el departamento)       │
                     └──── Entrevista ◀─────┘  Bajar a entrevistar → Entrevista; Rechazar → En cartera
Cualquiera antes de contratar ─▶ En cartera | Descartado (motivo obligatorio)
En cartera ─▶ En revisión | Descartado      Descartado ─▶ En revisión (reabrir)
Aprobado por RR. HH. ─▶ En revisión (RR. HH. lo regresa; la solicitud al departamento se cancela)
```

`Candidato::TRANSICIONES` es la fuente. Prohibidas (aviso en rojo, sin tocar nada): saltar etapas (Registrado → Seleccionado…), pasar a «Contratado» sin el botón **Contratar**, volver a «Registrado», cualquier cambio de un **Contratado**, «Aprobar» sin departamento (error de validación), «Descartar» sin motivo. Cada cambio es un `UPDATE … WHERE etapa = <la esperada>` (doble clic o dos personas: el segundo recibe «Otra persona acaba de cambiar…»).

**Contratar** (solo «Seleccionado», permisos `candidatos.contratar` y `colaboradores.crear`): pide número de empleado y confirma nombre/apellidos (se sugieren partiendo el nombre), sede, departamento y puesto; llama a `AdministradorColaboradores::crear()` (mismas validaciones: número único, sede en el alcance…) y deja el candidato «Contratado» con `colaborador_id`. Todo en una transacción.

## Aviso de privacidad

Obligatorio (`acepta_privacidad`) para guardar el CV desde el kiosco y para la captura de RR. HH. si aún no se había aceptado. Texto por empresa en Recepción → Ajustes (o Configuración). El texto de fábrica (`AjustesRecepcion::PRIVACIDAD_BORRADOR`) dice **«Borrador — validar con su abogado»**.

## Kiosco

- Lo genera quien tiene `candidatos.editar` (sus sedes) o la caseta con `accesos.crear` (solo candidatos registrados en caseta de sus sedes, sin ver su CV): `POST /rh/recepcion/kiosco/{candidato}`. Generar otro anula el anterior. RR. HH. lo puede anular desde la ficha.
- La tableta («Modo kiosco») muestra el QR con `/k?codigo=XXXXXX`. El candidato confirma el código (`POST /k`), recibe un token nuevo para ese enlace y llega a `/k/{token}`.
- Lo que envía: datos del CV (no el departamento ni el puesto del catálogo), aviso de privacidad, hasta 3 documentos. Cada envío cuenta un uso (actualización condicionada: no se pasa del máximo aunque lleguen dos a la vez). La ficha queda **«Por revisar»** y RR. HH. recibe aviso (campana y correo).
- Vencido, usado, anulado o inventado → página «Este enlace ya venció / ya se usó / no existe» con estado 404.

## Endpoints

| Método y ruta | Permiso | Qué hace |
|---|---|---|
| `GET /candidatos?q=&etapa=&sede=&departamento=` | `candidatos.ver` | Lista con conteo por etapa |
| `POST /candidatos` | `candidatos.crear` | Captura de RR. HH. (pide aviso de privacidad) |
| `GET /candidatos/exportar` | `candidatos.exportar` | CSV (`App\Support\Csv`, sin contacto) |
| `GET /candidatos/{c}` | `candidatos.ver` | Ficha |
| `PUT /candidatos/{c}` | `candidatos.editar` | Editar CV y notas |
| `PATCH /candidatos/{c}/etapa` | `candidatos.editar` | Cambio de etapa (`volver=recepcion` regresa al panel) |
| `POST /candidatos/{c}/revisado` | `candidatos.editar` | Quita «Por revisar» |
| `POST /candidatos/{c}/contratar` | `candidatos.contratar` + `colaboradores.crear` | Alta como colaborador |
| `DELETE /candidatos/{c}` | `candidatos.eliminar` | Borra ficha y archivos (derechos ARCO) |
| `POST /candidatos/{c}/documentos` · `GET|DELETE …/documentos/{d}` | `editar` · `ver` | Documentos privados (PDF se descarga) |
| `POST /candidatos/{c}/enlace/revocar` | `candidatos.editar` | Anula el enlace del kiosco |
| `GET /k?codigo=` · `POST /k` · `GET|POST /k/{token}` | **sin sesión** | Kiosco. Limitadores con nombre (`AppServiceProvider::limitadoresPublicos`), cada uno con su contador porque en recepción todas las tabletas salen por la misma IP: `kiosco-ver` 120/min por IP + enlace, `kiosco-canjear` 10/min por IP, `kiosco-guardar` 10/min por IP + enlace y 60/min por IP. La bolsa pública usa `empleos-ver` (120/min) y `empleos-postular` (6/min) |

Otra empresa u otra sede fuera del alcance → **404**.

## Auditoría

`candidatos.creado`, `candidatos.actualizado`, `candidatos.etapa`, `candidatos.contratado`, `candidatos.eliminado`, `candidatos.documento_agregado`/`_eliminado`, `candidatos.enlace_kiosco`, `candidatos.enlace_revocado`, `candidatos.autocaptura` (sin usuario, con IP), `candidatos.configurado`, y `visitantes.creado` cuando RR. HH. da de alta a la persona en el padrón. **La foto de auditoría no guarda el CV ni el contacto.**

## Referencias registradas

`AdministradorColaboradores::REFERENCIAS['candidatos'] = 'colaborador_id'` y `AltasPorVerificar::REFERENCIAS['personas'][] = ['candidatos', 'persona_id']` (unir duplicados mueve al candidato).

## Qué se corrigió respecto a SEGCAT

SEGCAT no tenía candidatos: el guardia escribía «Recursos Humanos» como motivo y RR. HH. no se enteraba hasta que alguien llamaba por teléfono; el CV se pedía en papel; no había constancia del aviso de privacidad ni de los tiempos de espera.

## Lección 36: solicitud de empleo y vacantes

La ficha y el kiosco llevan la solicitud de empleo formal (datos oficiales, domicilio, referencias, declaración y firma, hoja impresa): ver [solicitud-empleo.md](solicitud-empleo.md). Origen nuevo `web` (bolsa de trabajo) y `vacante_id`: ver [vacantes.md](vacantes.md).
