# Mejoras de los padrones (ADR-0015)

Decisión: [ADR-0015](../decisiones/ADR-0015-mejoras-padrones.md). Atiende la crítica de uso de los padrones (Empresas externas, Personas, Vehículos, Llaves, Gafetes, Vouchers, Equipos, Equipos de Protección Civil, Estacionamientos, Etiquetas QR, Rutas y los procesos transversales) y de los catálogos de personal (Departamentos, Puestos, Turnos, Colaboradores), con los criterios 1, 4, 5 y 8 de [criterios-ux.md](../criterios-ux.md).

## 1. Un solo vocabulario para dar de baja (criterio 4)

En todos los padrones y catálogos el botón es **Dar de baja** / **Reactivar** y el estado se lee **DE BAJA** (antes: Desactivar, Suspender, BAJA / VETADA, NO DISPONIBLE, BAJA/PERDIDO, Inactiva, INACTIVO, SUSPENDIDA). Los botones de guardar dicen **Guardar** (antes «Guardar e Indexar Llave», «Actualizar Registro», «Registrar Equipo», «Guardar en Padrón»…). «CXC» pasa a **Se le cobra al colaborador**.

- Solo cambian textos de pantalla, mensajes y correos. Los datos (`activo`, `estado = 'baja'`) y los eventos de auditoría (`*.desactivado`, `*.reactivado`) no cambian.
- `Equipo::ESTADOS['baja']` = `DE BAJA`.
- La acción `eliminar` del catálogo se llama **Dar de baja** en la Matriz de permisos (migración `2026_10_26_000100_mejoras_padrones_nombres` y `CatalogoSeeder::ACCIONES`). El borrado sigue siendo `borrar` = «Eliminar definitivamente»; `componentes/borrar` agrega «¿Lo capturaste por error y nadie lo ha usado?».
- El menú de Estacionamientos se llama **Estacionamientos y zonas de descarga** (`modulos.nombre_menu`, solo si estaba vacío; `MenuSeeder`).

## 2. Altas por verificar en la campana (criterio 1)

`App\Services\Padrones\CampanaPadrones`, llamado desde `AltasPorVerificar`:

| Momento | A quién | Tipo (`CentroNotificaciones::TIPOS`) | Referencia |
|---|---|---|---|
| `registrarAlta()` deja el alta pendiente | Usuarios activos con `<padron>.editar` que alcanzan la sede del alta (sin sede: todos los que tienen el permiso), menos quien la registró. Botón **Verificar** (`abrir`) | `alta_por_verificar` | `alta_<padron>` + id |
| `aceptar()` / `unir()` / `rechazar()` | Se resuelve el aviso anterior (sin botones, leído) y se avisa a `creado_por` si es otro usuario activo | `alta_verificada`, `alta_unida`, `alta_rechazada` (con el motivo) | `alta_<padron>_resultado` |

Colaboradores usa el mismo circuito y las mismas palabras: `AdministradorColaboradores::crearProvisional()` avisa a quien tiene `colaboradores.aprobar` en la sede (`colaboradorPorVerificar`), y `validarProvisional()` / `fusionar()` resuelven y avisan a quien lo registró (`colaboradorResultado`, referencia `alta_colaboradores`). `MisPendientes` agrega el renglón **Colaboradores por verificar** (clave `colaboradores_por_verificar`). En pantalla, correo e Inicio: «POR VERIFICAR», «Registro rápido», «Verificar», «Guardar y verificar», «N colaboradores por verificar». La acción de permisos `colaboradores.provisional` no cambia de clave.

El correo (`alta_por_verificar`, `alta_provisional`) sigue igual y se apaga en Configuración; la campana siempre llega.

## 3. Unir personas verificadas duplicadas

`AltasPorVerificar::unirDuplicado(actor, padron, duplicado, destino)` — igual que `unir()` pero para dos registros **verificados**:

- mueve todas las `REFERENCIAS` del padrón al destino;
- el duplicado queda `verificacion = rechazado`, `activo = false`, `fusionado_en_id = destino`, `motivo_rechazo = «Unido con «X»»` (la operación lo sigue sustituyendo por el correcto con `paraOperacion()`);
- en personas, el destino toma lo que le faltaba: folio (se **mueve**, porque es único), teléfono, empresa;
- auditoría `visitantes.fusionado` con `duplicado_verificado: true`.

Ruta `PUT /personas/{persona}/unir-duplicado` (`personas.unir-duplicado`) → `AltaPorVerificarController::unirDuplicadoPersona`. Exige `visitantes.editar` (alcance de verificación) **y** `visitantes.eliminar` sobre la ficha (`AdministradorPersonas::limitar`). Responde JSON como Verificar. Vista `padrones/altas-por-verificar/_unir-duplicado` (diálogo «Unir con otra ficha»); los parecidos salen de `altas_por_verificar.parecidos?de=` y la búsqueda de `personas.buscar`. El servicio es genérico (vehículos y empresas pueden usarlo con su propia ruta).

## 4. Personas: un solo dato para la empresa

`AdministradorPersonas::validar()`: si no se eligió empresa del directorio y se escribió `empresa_procedencia`, se busca entre los parecidos de Empresas externas una **activa, no rechazada, de la categoría del tipo** cuyo nombre sea el mismo sin mayúsculas, acentos, signos ni «S.A. de C.V.» (`AltasPorVerificar::mismaEmpresa`). Si existe, se guarda `proveedor_id` y el texto libre se descarta. La lista dice «-- Otra empresa (escríbela abajo) --» y el campo «¿De qué empresa viene?». El filtro de categoría de la lista solo se ve con la píldora **Visitantes** (`data-solo-con-tipo`). El motivo pasa a **Más datos** como «Motivo habitual de sus visitas».

## 5. Baja con voucher y reactivación: un solo camino

- Gafetes y Equipos incluyen `seguridad.vouchers._firmas-baja` (firma física o digital), como Llaves. `Vouchers::validar()` ya lo soportaba.
- `RecuperacionVouchers::desdeFicha(actor, origenTipo, origen)`: el «Reactivar» de la ficha de una llave, gafete o equipo hace lo mismo que «Recuperado»:
  - sin voucher vigente (o sin `vouchers.editar` + `<modulo>.eliminar`): `sin_voucher` → el módulo reactiva como antes y avisa si queda un voucher vigente;
  - voucher vigente **sin cobro**: `recuperar()` aquí mismo (reactiva y cancela; comentario «Reactivado desde la ficha del artículo.»);
  - voucher vigente **con cobro**: no toca nada y redirige a `vouchers.index?q=<folio>&recuperar=<id>`, que abre el diálogo **Recuperado** (`data-abrir-al-cargar`).
- `RecuperacionVouchers::ultimosPorOrigen()` da el último voucher de cada artículo de baja: la ficha muestra **DE BAJA · MOTIVO** y el enlace **Ver su voucher VR-…** (si puede `vouchers.ver`).
- Gafetes: **EN USO** con `Gafete::scopePrestados()` (prestado ahora en Accesos).
- Equipos ASIGNADO: enlace **Ver responsiva** (`responsivas.index#responsiva-{id}`, si puede `responsivas.ver`).
- Llaves: la reactivación ya no abre sola «Código e identificación»; se ofrece como siguiente paso (`llaves.index?qr={id}`).

## 6. Siguiente paso tras guardar (criterio 5)

`componentes/siguiente-paso` pinta `session('siguiente_paso')` (uno o varios `{texto, url, icono, nueva_pestana}`; solo URLs de la plataforma) debajo de los avisos:

| Pantalla | Después de | Siguiente paso |
|---|---|---|
| Gafetes | Generar lote | **Imprimir este lote** (`gafetes.imprimir?gafetes[]=…`) |
| Vehículos | Registrar | **Imprimir su calcomanía** |
| Llaves | Registrar / Reactivar | **Imprimir su etiqueta** / **Ver su código QR** |
| Equipos, Equipos PC | Registrar | **Imprimir su etiqueta** |
| Empresas externas | Registrar | **Agregar su personal**, **Agregar su flotilla** |

## 7. Un solo camino de impresión

`App\Services\Lector\ImpresionDesdePadron`, configurable por empresa en `empresas.preferencias['padrones']['impresion']`:

- `modulo` (omisión): cada padrón imprime su hoja de siempre; la hoja trae `componentes/hoja-impresion-ayuda` con el recordatorio **«Tamaño real / Escala 100 %»** y **Imprimir con una plantilla de Etiquetas QR** (POST a `etiquetas.imprimir` con los mismos `sel[]`, si puede `etiquetas_qr.ver`).
- `plantillas`: `GafeteController::imprimir`, `LlaveController::imprimir`, `VehiculoController::calcomania`, `EquipoController::etiqueta` y `EquipoPcController::etiqueta`, después de revisar su permiso y alcance, registran la impresión con `ImpresionesEtiquetas::registrar()` y la plantilla `PlantillasEtiquetas::preelegida(actor, disponibles, tipo)`, y redirigen a `etiquetas.impresion`. Sin `etiquetas_qr.ver` imprimen su hoja propia.
- Se configura en **Etiquetas QR → Plantillas** (`PUT etiquetas/plantillas/impresion-padrones`, `etiquetas.plantillas.impresion-padrones`), con `etiquetas_qr.configurar` de alcance de empresa. Auditoría `etiquetas_qr.configurado`.
- La hoja de Etiquetas QR también muestra el recordatorio de escala. Sus filtros ya se aplicaban solos (`form[data-autoenviar]`).
- Colaboradores: **Imprimir credencial** en «Código e identificación» abre Etiquetas QR filtrado (`tipo=colaborador&q=<número>`).

## 8. Estacionamientos: corregir una ocupación desfasada

`PATCH /estacionamientos/{zona}/salida/{acceso}` (`estacionamientos.salida`) → `EstacionamientoController::salida`: pide `estacionamientos.ver` sobre la zona y `accesos.editar` sobre el acceso (`ConsultaAccesos::limitar`), y llama a `MovimientosAccesos::salida()` (misma regla y auditoría `accesos.salida` que la Bitácora). La tarjeta muestra **¿Quién ocupa? (n)** con placas, nombre y hora de entrada solo a quien puede dar salida.

## 9. Otros

- **Llaves**: alta corta; Departamento, Puesto, Responsable, ID externo, Caducidad, Costo, NFC y Horarios en `<details class="mas-datos">` (abierto al editar o si traen datos tras un error). El enlace de préstamos dice «Prestar o ver sus préstamos (Operación → Préstamo de llaves)». Botón de impresora en la ficha.
- **Vehículos**: «¿De quién es?» y «¿Qué forma tiene?»; las ocho categorías se agrupan en `<optgroup>` «Particular» / «De una empresa» (`AdministradorVehiculos::GRUPOS_PROPIEDAD`; no cambian los datos). Impresora en la ficha.
- **Empresas externas**: «Ficha de empresa externa», «Categoría». Con alcance de una sola sede el botón de sedes es un toque: **Quitar de mi sede** / **Agregar a mi sede** (mismo `proveedores.sedes`).
- **Equipos de Protección Civil**: «Clave de la etiqueta»; la tarjeta muestra la última revisión de `recorrido_pc_revisiones` (fecha y si tuvo falla; dos consultas para toda la lista).
- **Rutas**: los horarios se pliegan (resumen hora → hora · días · paraderos; se despliega solo si un campo obligatorio está vacío). `AdministradorRutas::paraderoParaRuta()` reutiliza el paradero cuyo nombre es igual sin acentos, signos ni espacios; en pantalla, «¿Es «X»? Usar ese» para diferencias de 1–2 letras.
- **Colaboradores**: correo personal y dirección también en el alta.
- **Puestos**: «El puesto es el rango; el departamento es el área» bajo el título. **Departamentos**: «Sin responsable» dice qué permiso pedir.

## Rutas nuevas

| Método | Ruta | Nombre | Permiso |
|---|---|---|---|
| PUT | `/personas/{persona}/unir-duplicado` | `personas.unir-duplicado` | `visitantes.editar` + `visitantes.eliminar` |
| PATCH | `/estacionamientos/{zona}/salida/{acceso}` | `estacionamientos.salida` | `estacionamientos.ver` + `accesos.editar` |
| PUT | `/etiquetas/plantillas/impresion-padrones` | `etiquetas.plantillas.impresion-padrones` | `etiquetas_qr.configurar` (empresa) |

## Pruebas

`tests/Feature/Seguridad/MejorasPadronesTest.php` y ajustes de textos en las pruebas de cada módulo.
