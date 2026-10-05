# Bitácora de transporte

Réplica de `modules/transporte/bitacora_*` de SEGCAT (`bitacora_transporte.php`, `bitacora_proceso.php`, `bitacora_modal_editar.php`, `ticket_taxi.php`, `reportes_transporte.php`, `exportar_transporte.php`, `buscar_colaboradores.php`, `chofer_*_ajax.php`, `vehiculo_*_ajax.php`; inventario §4.29). Pantalla `/transporte` (menú Operación → Caseta y Control). Usa las **Rutas de transporte** (`Ruta`, `RutaHorario`, `Paradero`, ver [rutas.md](rutas.md)).

- Controlador: `app/Http/Controllers/Seguridad/TransporteController.php`
- Reglas: `app/Services/Transporte/BitacoraTransporte.php` (validación, alcance, alta normal y de taxis, edición, anular / reactivar, Vo.Bo., resumen, auditoría)
- Modelo: `app/Models/MovimientoTransporte.php` (`PerteneceAEmpresa`, `RegistraAutor`)
- Migración: `2026_10_10_000500_crear_bitacora_de_transporte`
- Vistas: `resources/views/seguridad/transporte/{index,_tarjeta,_alta,_taxi,_editar,show,vale,reportes}.blade.php`
- Correo: `App\Mail\ValeTaxiRegistrado` + `resources/views/correos/vale-taxi.blade.php`, enviado por `AvisosCorreo::valeTaxi()`
- JS: bloque "Bitácora de transporte" al final de `public/js/plataforma.js`
- CSS: bloque "Bitácora de transporte" al final de `public/css/plataforma.css` y `public/css/modos-pantalla.css`
- Pruebas: `tests/Feature/Seguridad/TransporteTest.php` (21 pruebas)
- Demo: `CrearDatosDemo::transporteDemo()`

## Tablas

| Tabla | Columnas | Notas |
|---|---|---|
| `movimientos_transporte` | `empresa_id`, `sede_id`, `ruta_id`, `ruta_horario_id`, `tipo_movimiento` (`llegada`/`salida`), `estatus` (`a_tiempo`/`retraso`/`no_llego`), `fecha` (día **local de la sede**), `vehiculo_id`, `chofer_id` (personas), `cantidad_pax`, `monto`, `justificacion`, `paradero_id` (destino del taxi), `firma_guardia`, `firma_taxista` (rutas en el disco privado), `observaciones`, `lote` (uuid de los taxis capturados juntos), `editado_por/en`, `anulado`, `anulado_por/en`, `autorizado_por/en`, autoría y timestamps | Índices `(empresa_id, sede_id, fecha)`, `(empresa_id, fecha, estatus)`, `lote`. Nunca se borra. |
| `movimiento_transporte_pasajeros` | `id`, `movimiento_transporte_id`, `colaborador_id` | Colaboradores del taxi. Sin llave única compuesta para que la unión de duplicados (`AdministradorColaboradores::REFERENCIAS`) solo cambie `colaborador_id`. |

`created_at` (UTC) es la hora del registro y se muestra con `@fecha`. `fecha` se guarda como `AAAA-MM-DD` (mutador del modelo) para que los filtros por día funcionen igual en MySQL y SQLite.

## Endpoints y permisos

| Método | Ruta | Nombre | Permiso |
|---|---|---|---|
| GET | `/transporte?fecha_inicio&fecha_fin&sede&tipo&estatus&estado&q` | `transporte.index` | `transporte.ver` |
| POST | `/transporte` | `transporte.store` | `transporte.crear` en la sede |
| GET | `/transporte/{id}` | `transporte.show` | `transporte.ver` sobre el registro |
| PUT | `/transporte/{id}` | `transporte.update` | `transporte.editar` sobre el registro |
| PATCH | `/transporte/{id}/anular` · `/reactivar` | `transporte.anular` · `transporte.reactivar` | `transporte.eliminar` sobre el registro |
| PATCH | `/transporte/{id}/autorizar` | `transporte.autorizar` | `transporte.aprobar` sobre el registro |
| GET | `/transporte/{id}/vale` | `transporte.vale` | `transporte.ver` (solo vales de taxi) |
| GET | `/transporte/{id}/firma/{guardia\|taxista}` | `transporte.firma` | `transporte.ver` sobre el registro |
| GET | `/transporte/reportes?…&proveedor` | `transporte.reportes` | `transporte.ver` |
| GET | `/transporte/exportar?…` | `transporte.exportar` | `transporte.exportar` |

- **`transporte.firmar`**: con este permiso el alta muestra los recuadros de firma (guardia y taxista) y son obligatorios en los vales; sin él, el vale impreso trae las líneas para firmar a mano (como SEGCAT con la firma "en pausa").
- **Alcance.** Movimiento de otra empresa o fuera del alcance de `transporte.ver` (sede o, con "propios", autor) → **404**. Visible pero sin alcance del permiso de la acción → **403**. El alta solo acepta sedes activas dentro de `transporte.crear`. Superadmin: empresa de trabajo.
- **Plantillas de rol.** Transporte está en el menú Operación: el Agente ve, registra, edita y firma en su sede; no anula, no autoriza ni exporta. Director autoriza y exporta en toda la empresa. Asistente registra y exporta pero no firma (sus vales salen para firma a mano).

## Reglas

- **Ruta**: el diálogo elige un **horario** (`ruta_horario_id`) de una ruta **activa** de la sede y del sentido elegido; el servidor lo revalida. Se propone el horario que opera hoy más cercano a la hora actual de la sede (llegadas: hora de llegada; salidas: hora de salida) — `BitacoraTransporte::sugerencia()`.
- **Servicio normal** (A TIEMPO / RETRASO): placas, chofer, pasajeros, tipo (Autobús, Van / Urvan, Otro), marca, modelo, económico, capacidad y teléfono, todo opcional. Aviso de sobrecupo si los pasajeros superan la capacidad (no bloquea).
- **NO LLEGO (USO DE TAXIS)**: de 1 a 10 taxis; cada uno exige placas, conductor, monto > 0, destino y ≥ 1 colaborador (leído con el **lector universal** `tipos=colaborador`; activo, de la empresa y de las sedes que el usuario ve), y su firma si se firma en pantalla; la firma del guardia también. Si el monto supera `rutas.costo_maximo_taxi`, la justificación es obligatoria. Un colaborador no puede ir en dos taxis. **Un registro por taxi** (como SEGCAT) con el mismo `lote`.
- **Padrones**: placas → `AdministradorVehiculos::conPlacas()`; si no existe se crea (normal: `transporte_personal` con el transportista de la ruta; taxi: `taxi_app` sin proveedor) y si existe se completan marca/modelo/económico/capacidad vacíos. Chofer → `Persona` tipo `proveedor` por nombre (mayúsculas) y transportista (taxistas sin proveedor); se completa el teléfono. Destino → `AdministradorRutas::paraderoParaRuta()` (se volvió **público**; crea o reactiva el paradero de la sede). Cada alta se audita en su módulo (`vehiculos.*`, `visitantes.*`, `rutas.creado`).
- **Firmas**: se validan antes de guardar nada y se guardan con `Firmas::guardar(…, 'transporte')`; si falla la base, se borran. Solo se sirven por `transporte.firma` después de revisar el alcance.
- **Edición** (SEGCAT): normal → pasajeros, A TIEMPO ↔ RETRASO y observaciones; taxi → monto, destino, justificación (mismo tope), pasajeros (≥1) y observaciones. Ruta, unidad y chofer no cambian (se anula y se registra de nuevo).
- **Estados y transiciones**: vigente ⇄ anulado (`transporte.eliminar`); vale pendiente → autorizado (`transporte.aprobar`). Prohibido: anular lo anulado, reactivar lo vigente, editar lo anulado o autorizado, autorizar un servicio normal, uno anulado o uno ya autorizado, pasar un normal a NO LLEGO al editar. Mensaje en `estado`.
- **Correo**: cada vale se envía (después de responder, `defer`) a la lista de Configuración → Avisos → "Enviar cada vale de taxi…" (`empresas.preferencias.avisos_destinatarios.vale_taxi`); si la lista está vacía, a los usuarios con `transporte.aprobar` que alcancen la sede. Se apaga con la casilla del aviso (`Empresa::AVISOS['vale_taxi']`).
- **Resumen y reportes**: movimientos, gasto y pasajeros cuentan solo los vigentes; los anulados aparte. Reportes: 25 por página; filtro por proveedor = transportista de la ruta.
- **CSV**: UTF-8 con BOM, mismos filtros, máximo 20 000 filas.

Auditoría: `transporte.creado`, `transporte.actualizado`, `transporte.anulado`, `transporte.reactivado`, `transporte.autorizado` (la foto no lleva las rutas de las firmas, solo si las hay). `LectorAuditoria` los muestra como "Movimiento de transporte".

## Archivos compartidos que se tocaron

`CatalogoSeeder::RUTAS` (`transporte`), `routes/web.php` (bloque propio), `LectorAuditoria::REGISTROS`, `AdministradorColaboradores::REFERENCIAS`, `AdministradorRutas::paraderoParaRuta` (de privado a público), `Empresa::AVISOS` + `AVISOS_CON_DESTINATARIOS` + `destinatariosAviso()`, `ConfiguracionController::avisos()` (lista de correos), vista de Configuración, `AvisosCorreo::valeTaxi()`, `CrearDatosDemo::transporteDemo()`, `tests/Feature/Seguridad/PersonasTest.php` (el conteo de personas demo excluye a los choferes que registra la bitácora demo).

## Qué se corrigió respecto a SEGCAT

| SEGCAT | Plataforma |
|---|---|
| La unidad y el chofer se guardaban como texto (`numero_unidad`, `chofer_manual`) además del id; el destino era texto libre. | Ligados a `vehiculos`, `personas` y `paraderos`; la historia no se desincroniza. |
| Las firmas iban en base64 dentro de la tabla (`longtext`) y el vale las incrustaba. | Archivo en disco privado; se sirven solo con permiso y alcance. |
| Filtro `DATE(fecha_hora)` en la hora del servidor (días cortados en la zona equivocada). | `fecha` = día local de la sede, indexado. |
| Buscadores AJAX propios de colaboradores, choferes y vehículos. | Lector universal para pasajeros (gafete, QR, NFC, número) y sugerencias de placas y choferes sin consultar al servidor; alta provisional del colaborador sin salir del diálogo. |
| Ruta elegida sin horario. | Se liga el horario y se propone el más cercano a la hora de la sede. |
| Correo a `destinatarios_vouchers` dentro de la misma petición (el guardia esperaba al SMTP). | Aviso de empresa en Configuración, enviado con `defer`; si no hay lista, a quien autoriza en esa sede. |
| El "Vo.Bo Autorización" solo existía en papel. | `transporte.aprobar` registra quién y cuándo; después ya no se edita. |
| El resumen sumaba gasto y pasajeros de registros anulados. | Solo vigentes; anulados aparte. |
| Exportación `.xls` que era HTML. | CSV UTF-8 con BOM, mismos filtros, con permiso `transporte.exportar` (antes bastaba `ver`). |
| Mensajes en la URL (`?error=validacion&detalle=`), `onclick` y JS en la página. | Errores por taxi dentro del diálogo sin perder lo capturado; JS en `plataforma.js` con `data-*`. |
| Un colaborador podía ir en dos taxis; el editar de normal no permitía corregir el retraso. | Se impide el duplicado; A TIEMPO ↔ RETRASO se corrige al editar. |
| "Hotel Sede", "LLEGADA (Al Hotel)". | "Sede", "LLEGADA (A la Sede)". |
