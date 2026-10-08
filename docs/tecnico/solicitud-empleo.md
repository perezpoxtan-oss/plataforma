# Solicitud de empleo formal (ficha del candidato y kiosco)

Lección 36. Extiende el CV de [Candidatos](candidatos.md) con la **solicitud de empleo general de México**, en la misma ficha y el mismo kiosco (no hay pantallas nuevas salvo la hoja impresa). La bolsa de trabajo por internet usa el mismo formulario: ver [vacantes.md](vacantes.md).

## Piezas

| Pieza | Archivo |
|---|---|
| Migración | `database/migrations/2026_10_17_000100_solicitud_de_empleo.php` (columnas nuevas en `candidatos`, todas opcionales) |
| Modelo | `App\Models\Candidato`: `SEXOS`, `ESTADOS_CIVILES`, `LICENCIAS`, `DOCUMENTOS_ESTUDIO`, `MEDIOS_VACANTE`, `FIRMAS_MEDIO`, `SENSIBLES`, `partesNombre()`, `domicilioCompleto()`, `solicitudFirmada()`, relación `firmaCapturadaPor` |
| Reglas | `AdministradorCandidatos::validarCv($entrada, $pidePrivacidad, $completa)`, `soloCv()`, `firmar()`, `contratar()` (copia los datos), `foto()` (enmascara) |
| Kiosco | `Kiosco::guardar()` pide la solicitud completa |
| Controlador | `CandidatoController::solicitud()` (hoja impresa), `firma()` (imagen privada), `exportar()` (`?datos=personales`) |
| Vistas | `rh/candidatos/_cv.blade.php` (secciones `<x-seccion>`), `_fila-escolaridad`, `_fila-experiencia`, `_fila-referencia` (personales y laborales), `show.blade.php`, `solicitud.blade.php` |
| Pruebas | `tests/Feature/Seguridad/SolicitudEmpleoTest.php` y ajustes en `CandidatosYAutorizacionesTest` (el kiosco ahora pide declaración, firma y 2 referencias) |

## Columnas nuevas de `candidatos`

| Grupo | Columnas |
|---|---|
| Datos personales | `nombre`, `apellido_paterno`, `apellido_materno` (si llegan, `nombre_completo` se arma con ellos; se conserva por compatibilidad), `sexo` (mujer, hombre, no_decir), `lugar_nacimiento` (estado; mismo catálogo que Colaboradores), `nacionalidad`, `estado_civil`, `dependientes` |
| Oficiales | `curp` (`Colaborador::CURP`), `rfc` (`Colaborador::RFC`), `nss` (11 dígitos), `licencia_tipo` (automovilista, chofer, motociclista, federal), `licencia_vigencia` |
| Domicilio | `calle_numero`, `colonia`, `codigo_postal` (5 dígitos), `municipio` (también llena `ciudad`), `estado_domicilio`, `tiempo_residencia`, `telefono_fijo` (10 dígitos) |
| Emergencia | `emergencia_nombre`, `emergencia_parentesco`, `emergencia_telefono` |
| Referencias | `referencias` (personales, JSON) y `referencias_laborales` (JSON): `nombre`, `telefono`, `relacion`, `anos_conocerlo` |
| Datos generales | `medio_vacante`, `tiene_familiares` + `familiares_nombre`, `trabajo_antes_aqui`, `rolar_turnos`, `puede_viajar`, `cambiar_residencia`, `fecha_inicio_posible` (el sueldo pretendido sigue en `pretension`) |
| Declaración y firma | `declaracion_aceptada_en`, `firma_ruta` (disco privado `firmas/<empresa>/candidatos/AAAA/MM/<uuid>.jpg`), `firma_en`, `firma_medio` (kiosco, web, rh), `firma_capturada_por` |

Renglones JSON: **escolaridad** agrega `periodo` y `documento` (certificado, título, cédula, trunco; `concluido` se deduce); **experiencia** (empleos anteriores) agrega `ingreso` y `salida` (mes `AAAA-MM`; sin salida = sigue ahí), `sueldo_final`, `jefe`, `jefe_telefono`, `pedir_referencias` (si/no) y `anos` se calcula con las fechas (si un renglón anterior solo tenía años, se conserva en un campo oculto).

**No se piden** datos de salud, religión, afiliación política ni similares (decisión legal y ética).

## Reglas

- **RR. HH.** captura por partes: nada es obligatorio salvo el nombre (nombre + apellido paterno, o el nombre completo de antes).
- **Lo que envía el candidato** (kiosco y bolsa de trabajo, `$completa = true`): nombre, apellido paterno, teléfono, **al menos 2 referencias personales con teléfono**, la casilla **«Declaro que la información es verdadera»** y la **firma**. Los errores salen juntos, en español, y no gastan el enlace del kiosco.
- **Firma de RR. HH. por el candidato** (opcional en «Editar solicitud» y «Nuevo candidato»): si trae firma pide también la declaración; queda `firma_medio = rh` y `firma_capturada_por`. Firmar otra vez reemplaza (y borra) la anterior. Eliminar la ficha borra la firma.
- **Contratar** copia al colaborador: nombre y apellidos, teléfono, correo, fecha y lugar de nacimiento, nacionalidad, CURP, RFC, NSS y `direccion_completa` (de `domicilioCompleto()`), con las mismas validaciones de Colaboradores (CURP/RFC/NSS únicos: el error sale dentro del diálogo). Requiere `colaboradores.datos_personales` para los datos personales (como el alta normal).

## Datos sensibles

`Candidato::SENSIBLES`: CURP, RFC, NSS, domicilio, teléfono fijo y contacto de emergencia.

- Se ven solo en la ficha (`candidatos.ver`, alcance de sede) en el bloque **«Datos oficiales y domicilio · Solo RR. HH.»** y en la hoja impresa.
- **Nunca** en el resumen del departamento (Autorizaciones), las notificaciones, los correos ni el CSV normal.
- CSV: el botón **«Con datos personales»** (`?datos=personales`, pide confirmar) agrega teléfono, correo, CURP, RFC, NSS, domicilio y emergencia con encabezado `[DATO PERSONAL]` y deja `candidatos.exportado_con_datos_personales` en la auditoría.
- Auditoría: `foto()` guarda CURP/RFC/NSS **enmascarados** (`Colaborador::enmascarar`) y `firmada`; nunca domicilio ni emergencia.

## Endpoints nuevos

| Método y ruta | Permiso | Qué hace |
|---|---|---|
| `GET /candidatos/{c}/solicitud` | `candidatos.ver` + alcance | Hoja impresa «Solicitud de empleo» (logo, folio `SOL-000123`, fecha, foto de caseta, secciones, firma) |
| `GET /candidatos/{c}/firma` | `candidatos.ver` + alcance | Imagen de la firma (`Firmas::respuesta`, solo de la empresa activa) |
| `GET /candidatos/exportar?datos=personales` | `candidatos.exportar` | CSV con columnas marcadas |

Otra empresa → 404; Agente o Director → 403.

## Qué se corrigió respecto a SEGCAT

SEGCAT no tenía solicitud de empleo: se llenaba en papel, se capturaba a mano al contratar y la firma quedaba en el archivo físico. Ahora la solicitud se llena una vez (en el celular del candidato o con RR. HH.), la firma queda privada, la hoja se imprime para el expediente y al contratar los datos pasan solos a Colaboradores.
