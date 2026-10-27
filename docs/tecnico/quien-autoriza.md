# Quién autoriza (Etapa 3: C-08, C-11, C-12 y C-27)

Ver ADR-0019. Complementa [recepcion-y-autorizaciones.md](recepcion-y-autorizaciones.md), [altas-por-verificar.md](altas-por-verificar.md), [colaboradores.md](colaboradores.md) y [usuarios.md](usuarios.md).

## C-08 · Cobertura real

`App\Services\Autorizaciones\Cobertura` (no compartida: se crea por petición; recuerda sus lecturas mientras vive):

| Método | Uso |
|---|---|
| `puedeResponderEn(User, ?sede)` | Cuenta activa + `autorizaciones.responder` con alcance en la sede (`Destinatarios::alcanza`). `null` = en alguna sede. |
| `cubre(DepartamentoResponsable, ?sede)` | La fila es de esa sede (o de todas) y su cuenta puede responder ahí. |
| `responsables(depto, sede)` | Lo que devuelve `Autorizaciones::responsablesDe()` (y por eso `tieneResponsables`, `aQuienAvisar`, `puedeResponder`, `Escalamiento`, `ResponsablesReservas`, `Entrevistas`). |
| `sedesCubiertas()` | Anticipo de la caseta (`data-departamentos-con-responsable`): depto => sedes (null = todas). Una lectura de filas, sin departamentos. |
| `mapa()` / `sinCobertura(soloPerdidas)` | Departamentos activos × sus sedes activas. `perdida` = había alguien nombrado para esa sede. |
| `problema(fila, sedes)` | Texto de la pantalla Responsables: `cuenta dada de baja`, `sin permiso para responder`, `no puede entrar a …`, `solo responde en …`. |
| `revisar(empresa)` / `revisarDespues(?empresa)` | Aviso `cobertura_perdida` (referencia `cobertura`, id del departamento) a quien tiene `autorizaciones.configurar` con alcance de empresa; se resuelve al recuperar la cobertura. |

Quién llama a `revisarDespues`: `AdministradorUsuarios::actualizar` y `cambiarEstado`, `AdministradorColaboradores::cambiarEstado` (baja en cascada), `AdministradorRoles::sincronizarPermisos` y `actualizarRol` (solo roles de la empresa) y `Delegaciones::guardarResponsables`.

`Autorizaciones::departamentosQueAtiende()` (bandeja, Mis pendientes, menú) solo devuelve pares de sedes a las que entra el usuario: una fila «todas» de una cuenta limitada a Centro solo da `[depto, Centro]`.

### DD-11 · Sin quien autorice

- `AjustesRecepcion::SIN_COBERTURA` (`supervisor` de fábrica, `directo`), guardado en `empresas.preferencias.recepcion.sin_cobertura` desde la tarjeta «Qué espera en la caseta» (parte `esperas`).
- `RecepcionEnCaseta::preparar`: visita con departamento, ajuste de visitas encendido y sin cobertura → `sin_responsable = true` (si el ajuste es `supervisor`). `RegistroAccesos` deja el acceso `pendiente` y `despues()` guarda `autorizacion = sin_resp` y llama `Autorizaciones::avisarSinCobertura()`.
- `Autorizaciones::supervisoresDeSede()`: con `accesos.aprobar` en la sede y rol «Supervisor» o «Jefe de seguridad»; si la empresa no usa esos roles, todos los que aprueban accesos ahí. Aviso `acceso_sin_cobertura` (urgente, referencia `acceso`), resuelto por `MovimientosAccesos::autorizar` y `Excepciones::autorizar`.
- La visita se autoriza con **Confirmar Autorización** (`accesos.aprobar`); el proveedor y el contratista, con **Autorizar por excepción**, como antes.
- Mis pendientes: `esperan_supervisor` (una consulta: total y la más antigua; color con los umbrales de Recepción).
- JS: `data-sin-cobertura-espera="1"` hace que el botón diga «Registrar · queda pendiente de autorización» para cualquier visita a departamento.

### A-04

- `Delegaciones::guardarResponsables` rechaza `sede_id = null` para una cuenta con alcance de sede y cualquier sede fuera de su alcance.
- La pantalla marca las opciones con `data-sedes` y `plataforma.js` (bloque «Etapa 3 (C-08, A-04)») deshabilita las demás.
- `AutorizacionController::buscar`: si la solicitud existe en la empresa, está fuera de su alcance y el usuario está en `avisados` o `escalado_a`, redirige con explicación (no 404). A cualquier otro, 404.

### Antes de dar de baja (Etapa 2)

`AntesDeDarDeBaja::responsableUnicoDe` usa la misma regla (los demás deben cubrir esa sede). El reemplazo debe poder responder; si su cuenta es de sede, una fila «todas» se reparte en una fila por cada sede suya.

## C-11 · La cuenta sigue al colaborador

- `AdministradorColaboradores::sincronizarCuenta(actor, colaborador, sedeAntes)`: copia `name` (nombre completo) y `numero_colaborador` (si nadie más lo usa en la empresa) a las cuentas vinculadas; audita `usuarios.sincronizado`. Llamado desde `actualizar`, `validarProvisional`, `fusionar` (a la cuenta que pasa al destino) y, por ellos, desde la importación.
- Cambio de sede: si la cuenta solo entra a otras sedes, aviso `cuenta_sede` a quien tiene `usuarios.editar` en la sede nueva.
- `UsuarioController::vincularColaborador` pone `name` del colaborador. La vista marca el campo `data-nombre-cuenta` y el JS lo deja en solo lectura con vínculo; al elegir colaborador sugiere su sede.
- Migración `2026_11_12_000100_cuentas_siguen_al_colaborador`: una vez; `down()` sin efecto.

## C-12 · Delegar mis aprobaciones

- Rutas: `POST /mi-cuenta/delegar` (`mi-cuenta.delegar`) y `PATCH /mi-cuenta/delegar/{id}/cancelar`, con `throttle:delegar-cuenta`. Sin permiso propio; 403 si la cuenta no aprueba nada (`Delegaciones::puedeDelegar`).
- `Delegaciones::APROBACIONES`: `autorizaciones.responder`, `pases_salida.aprobar`, `reservas.aprobar`, `candidatos.evaluar`. `candidatosADelegado()` = usuarios activos con alguno.
- A quién se avisa (el delegado solo si puede): `Autorizaciones::aQuienAvisar` (responder en la sede), `Entrevistas::aQuienAvisar` (`candidatos.evaluar`), `CircuitoPasesSalida::aQuienAvisar` (el delegado está entre quienes pueden firmar), Reservas (`ResponsablesReservas::conDelegados`, como antes).
- Mis pendientes: autorizaciones, pases, entrevistas y reservas excluyen lo que el delegado activo puede resolver. Quien delegó puede seguir respondiendo.
- `autorizaciones.delegar` se conserva; la pantalla la ofrece solo a quien configura («Delegar por otra persona»).
- **En cadena (revisión de la Etapa 3a):** `App\Services\Autorizaciones\CadenaDelegaciones` es la única regla. Si A delega en B y B en C, la cadena de A es [B, C]; lo de A le llega al último de la cadena que pueda aprobarlo en ese módulo (`destino()`), y responde quien esté en la cadena (`titularesDe()`). Solo delegaciones activas con delegado activo; un bucle deja a cada quien con lo suyo. `Delegaciones::crear()` rechaza cerrar un bucle en las fechas elegidas. La usan `Autorizaciones` (avisos, `puedeResponder`, bandeja), `Escalamiento::aQuienEscalar` (antes pasaba al delegado sin revisar si podía responder), `Entrevistas`, `CircuitoPasesSalida`, `ResponsablesReservas`/`PendientesReservas` y Mis pendientes. La lectura vale por petición, empresa y minuto.
- **Reservas:** un delegado atiende reservas por su titular solo si responde autorizaciones o aprueba reservas (`ResponsablesReservas::delegadoAtiende`); antes, quien solo aprobaba pases podía aprobar reservas ajenas como delegado.
- **Límite:** `POST /mi-cuenta/delegar` y la cancelación usan el limitador propio `delegar-cuenta` (10 por minuto por cuenta), que no comparte contador con otras rutas.

## C-27 · Altas por verificar

- `GET /altas-por-verificar?pestana=personas|vehiculos|proveedores|colaboradores` (`AltaPorVerificarController::index`). 403 si no puede verificar ningún padrón ni aprobar colaboradores.
- Colaboradores: diálogos compartidos `organizacion/colaboradores/_verificar.blade.php` (Aprobar, Aprobar · es el mismo, Rechazar). `PUT /colaboradores/{id}/rechazar` → `AdministradorColaboradores::rechazarProvisional` (baja con motivo, auditoría `colaboradores.rechazado`, aviso `alta_rechazada`). `volver=altas` regresa a la bandeja.
- Mis pendientes: un solo renglón `altas` con detalle por pestaña (desaparece `colaboradores_por_verificar`). Menú: entrada personal en Padrones (`ConstructorMenu::SECCION_PADRONES`).
- Importación: `ImportadorColaboradores::existente` reconoce por nombre un alta provisional sin número; al actualizar queda verificada (`verificarDesdeImportacion`).
