# Vouchers de reposición

Réplica de `modules/vouchers` de SEGCAT (`voucher_lista.php`, `voucher_imprimir.php`). Pantalla `/vouchers` (Seguridad → Padrones → Inventarios de Seguridad). **Solo consulta e impresión**: los vouchers nacen de las bajas de Llaves, Gafetes y Equipos con el servicio común `App\Services\Inventarios\Vouchers` (ver [lector.md](lector.md#vouchers-de-reposición-base)) y no se editan ni se borran.

- Controlador: `app/Http/Controllers/Seguridad/VoucherController.php`
- Visibilidad: `app/Services/Vouchers/ConsultaVouchers.php`
- Modelo: `app/Models/VoucherReposicion.php` (tabla `vouchers_reposicion`, migración `2026_10_09_000100_crear_base_inventarios`; sin cambios de esquema)
- Vistas: `resources/views/seguridad/vouchers/{index,imprimir}.blade.php`
- Pruebas: `tests/Feature/Seguridad/VouchersTest.php`

## Quién ve qué

`ConsultaVouchers::consulta($usuario, $permiso)`:

1. Exige el permiso del módulo (`vouchers.ver` para la lista, `vouchers.imprimir` para imprimir).
2. Solo los vouchers cuyo **módulo de origen** puede ver (`VoucherReposicion::moduloOrigen()`: `llaves.ver`, `gafetes.ver`, `equipos.ver`), como SEGCAT.
3. Solo en las sedes donde valen **ambos** permisos (el de vouchers y el del módulo de origen). Un voucher sin sede solo lo ve quien tiene alcance de empresa.
4. Con alcance **propios** en vouchers, solo los que el usuario generó.
5. Siempre dentro de la empresa de trabajo (tenant). Un voucher fuera de todo esto responde **404** al imprimir.

Si el usuario tiene `vouchers.ver` pero ningún permiso de origen, la pantalla lo explica en lugar de mostrar una lista vacía.

## Lista — `GET /vouchers`

Tarjetas con: folio, **CON COBRO / SIN COBRO**, artículo (`origen_descripcion`, con ícono por origen), Origen, Motivo, Sede, Responsable (o "No especificado"), Monto (si hay cobro), ¿Cómo pasó?, "Generado por … · fecha" (`@fecha`) y **Ver / Reimprimir** (con `vouchers.imprimir`).

Filtros en la dirección (se pueden compartir) y en el servidor, con 30 por página:

| Parámetro | Valores |
|---|---|
| `q` | folio, artículo, ¿cómo pasó?, nombre o número de empleado del responsable |
| `sede` | id de sede (solo las del usuario en la lista) |
| `origen` | `llave`, `gafete`, `equipo` (solo los que puede ver) |
| `cobro` | `1` con cobro, `0` sin cobro |
| `desde`, `hasta` | `AAAA-MM-DD`; el día se cuenta en la hora local de la empresa (`HoraLocal`) |

Los valores inválidos se ignoran. Los selectores y las fechas envían el formulario al cambiar (`data-enviar-al-cambiar`).

## Impresión — `GET /vouchers/{id}/imprimir`

"Voucher de Reposición — 3 copias en una sola hoja": una hoja carta con **Copia Seguridad**, **Copia Recepción** y **Copia Administración** (Ronda 5), separadas por "✂ recortar aquí". Cada copia: folio, empresa, sede, fecha, artículo, motivo, responsable con número de empleado, cómo pasó, **CXC** ($ MXN o "NO APLICA"), "Referencia de pago (a mano)" si hay cobro, quién lo generó y líneas de firma (Seguridad, Colaborador con su nombre, Recepción). Requiere `vouchers.ver` y `vouchers.imprimir`.

## Permisos

| Acción | Permiso |
|---|---|
| Lista | `vouchers.ver` + `<origen>.ver` |
| Ver / Reimprimir | `vouchers.imprimir` (+ lo anterior) |

Plantillas: el **Agente** consulta (Padrones = solo `ver`) pero no imprime; Asistente, Supervisor, Jefe, Director y Administrador imprimen.

## Rutas

| Método | Ruta | Nombre |
|---|---|---|
| GET | `/vouchers` | `vouchers.index` |
| GET | `/vouchers/{id}/imprimir` | `vouchers.imprimir` |

## Ronda 5: firmas, copias y correo

Nota del dueño (LL-04): «las copias son para seguridad, recepción y administración… si aplica CXC se envíen las copias por mail y la firma de los involucrados en dos modalidades, firma digital o firma física; el colaborador no debe recibir copia».

### Esquema (migración `2026_10_12_000210_ajustes_ronda_5_llaves_y_vouchers`)

| Columna | Qué guarda |
|---|---|
| `firma_modo` | `digital` o `fisica` (null en vouchers anteriores) |
| `firma_seguridad`, `firma_responsable` | Ruta en el disco **privado** (`firmas/<empresa>/vouchers/aaaa/mm/<uuid>.jpg`) |
| `firmado_papel_en`, `firmado_papel_por` | Cuándo y quién registró la firma a mano |
| `hoja_firmada` | Foto/escaneo de la hoja firmada, re-dibujada con `ImagenSegura` en `firmas/<empresa>/vouchers-hojas/…` (privado) |

`VoucherReposicion::estadoFirma()`: `digital`, `papel`, `pendiente` o null. `VoucherReposicion::COPIAS`: Seguridad, Recepción, Administración.

### Servicio común (`App\Services\Inventarios\Vouchers`)

- `validar()` acepta `firma_modo`, `firma_seguridad`, `firma_responsable`. En **digital** exige la de Seguridad y, si hay `colaborador_id`, la del responsable («Falta la firma de…»). Sin `firma_modo` (Gafetes y Equipos hoy) todo sigue igual.
- `darDeBaja()` guarda las firmas con `Firmas::guardar()` antes de la transacción y las borra si la transacción falla.
- Con `aplica_cobro`, llama a `AvisosCorreo::voucherConCobro()` (después de responder, con `defer`).
- Para usarlo en Gafetes o Equipos basta con incluir `@include('seguridad.vouchers._firmas-baja', ['id' => '…'])` en su diálogo de baja.

### Correo (`AvisosCorreo::voucherConCobro`, Mailable `VoucherConCobro`, vista `correos/voucher-cobro`)

- Aviso `voucher_cobro` en `Empresa::AVISOS` (encendido por omisión).
- Listas por copia en `empresas.preferencias.avisos_destinatarios.voucher_seguridad|voucher_recepcion|voucher_administracion` (`Empresa::DESTINATARIOS_VOUCHER`), capturadas en Configuración (máx. 20, validadas).
- Un correo por copia: asunto «Voucher VR-… con cobro — Copia Seguridad», folio, artículo, motivo, monto, sede, responsable, estado de firmas y enlace a `vouchers.imprimir` (pide sesión y permiso). Si las tres listas están vacías, a los usuarios con `vouchers.imprimir` en esa sede. **Nunca** al colaborador.

### Endpoints nuevos

| Método y ruta | Nombre | Permiso | Qué hace |
|---|---|---|---|
| `GET /vouchers/{id}/firma/{seguridad\|responsable\|hoja}` | `vouchers.firma` | `vouchers.ver` + visibilidad (`ConsultaVouchers`) | Imagen privada con `Firmas::respuesta()`; otra empresa o sede → 404 |
| `POST /vouchers/{id}/papel` (`hoja` opcional, imagen ≤ 6 MB) | `vouchers.papel` | `vouchers.imprimir` + visibilidad | Marca «firmado en papel» (conserva la primera fecha), sube o reemplaza la hoja; audita `vouchers.firmado_papel`. Un voucher firmado digitalmente no se marca en papel |

### Transiciones de la firma

`fisica` → (Registrar firma en papel) → `papel` → (Cambiar hoja firmada) → `papel` (misma fecha). `digital` es final: no se puede marcar en papel. Los vouchers anteriores (sin modo) pueden registrar firma en papel.

### Impresión

Copias: **Copia Seguridad, Copia Recepción, Copia Administración** (antes Seguridad, Colaborador y Recepción). Líneas de firma: Seguridad, Responsable y «Recibe (área de la copia)». Con firma digital las imágenes salen sobre la línea.

## Unión de colaboradores duplicados

`vouchers_reposicion.colaborador_id` está registrado en `AdministradorColaboradores::REFERENCIAS`: al unir un colaborador provisional con su registro correcto, sus vouchers pasan al correcto.

## Qué se corrigió respecto a SEGCAT

| SEGCAT | Plataforma |
|---|---|
| La lista consultaba el artículo con una consulta por voucher (N+1) y la sede/empresa con `INNER JOIN` (un voucher de una sede borrada desaparecía). | `origen_descripcion` guardado al generarse (no cambia si después se edita la llave o el gafete); sede y responsable con carga anticipada, incluidas sedes dadas de baja. |
| Toda la lista se cargaba completa y se filtraba en el navegador. | Filtros en el servidor con paginación; se agregó rango de fechas y búsqueda por responsable. |
| La visibilidad por sede del usuario se aplicaba con `id_hotel` de la sesión. | Alcance real por permiso: sedes de `vouchers.ver` ∩ sedes del módulo de origen. |
| Imprimir solo verificaba el permiso del módulo de origen. | Exige también `vouchers.imprimir`, además de la visibilidad. |
| "Dañado" guardado con acento como valor. | Clave `danado`; la etiqueta se muestra "Dañado". |
| `onclick` y CSS en línea. | Sin JS en línea; estilos en `plataforma.css` con modos Sol y Noche. |

## Ronda 6 (GV-04): el artículo apareció — «Recuperado»

Si una llave, un gafete o un equipo con voucher aparece y se devuelve, el voucher **no se borra**: cambia de estado.

### Esquema (migración `2026_10_13_000100_ajustes_ronda_6`)

`vouchers_reposicion` gana `estado` (`vigente` por omisión), `recuperado_en`, `recuperado_por` (FK users), `recuperacion_comentario` (500), `reembolsado_en`, `reembolsado_por` (FK users), `reembolso_comentario` (500) e índice `(empresa_id, estado)`. Constantes `VoucherReposicion::ESTADOS`.

### Estados y transiciones (`App\Services\Vouchers\RecuperacionVouchers`)

| Desde | Acción | Hacia | Cuándo |
|---|---|---|---|
| `vigente` | Recuperado | `cancelado_recuperacion` | Sin cobro, o con cobro que **todavía no se pagaba**: el cobro se cancela («COBRO CANCELADO» en la tarjeta y «CANCELADO» junto al CXC impreso). |
| `vigente` | Recuperado + «Ya se le cobró» | `reembolso_pendiente` | El responsable ya pagó: el comentario es **obligatorio** (cómo se le devolverá). |
| `reembolso_pendiente` | Reembolso entregado | `reembolsado` | Se le devolvió el dinero (comentario opcional). |

Prohibidas (error dentro del diálogo, sin cambios): recuperar un voucher que no esté vigente, «reembolsar» uno vigente, cancelado o ya reembolsado, y recuperar un voucher viejo cuando el artículo se volvió a dar de baja después con otro voucher («marca ese como recuperado»).

«Recuperado» reactiva el artículo con la regla de su módulo (`AdministradorLlaves::reactivar`, `AdministradorGafetes::reactivar`, `AdministradorEquipos::reactivar` → DISPONIBLE) y su auditoría (`llaves.reactivado`…). Si ya estaba activo (alguien usó «Reactivar» en la ficha) solo cambia el voucher. Todo en una transacción. Auditoría propia: `vouchers.recuperado` (antes/después + `cobro`: «sin cobro» | «cancelado» | «ya pagado: reembolso pendiente», y `articulo_reactivado`) y `vouchers.reembolsado`.

### Endpoints y permisos

| Ruta | Nombre | Permiso |
|---|---|---|
| `POST /vouchers/{voucher}/recuperado` (`cobro_pagado`, `comentario`) | `vouchers.recuperado` | `vouchers.editar` (con su alcance de sede) + `<módulo de origen>.eliminar` (el mismo de «Reactivar») sobre el artículo |
| `POST /vouchers/{voucher}/reembolso` (`comentario`) | `vouchers.reembolso` | `vouchers.editar` |

Otra empresa u otra sede: 404. Sin permiso: 403. El Agente (Padrones solo consulta) no ve los botones.

### Pantalla e impresión

- Tarjeta: insignia de estado, «Recuperado por … · fecha — comentario» y «Reembolsado por …», botón **Recuperado** (vigente) o **Reembolso entregado** (reembolso pendiente).
- Filtro nuevo **Cualquier estado / Vigente / Cancelado por recuperación / Reembolso pendiente / Reembolsado** (`?estado=`).
- Hoja impresa: sello con el estado, quién y cuándo lo recuperó, comentario y reembolso.

### Qué se corrigió respecto a SEGCAT

SEGCAT no tenía forma de registrar que el artículo apareció: se reactivaba y el voucher seguía como si el cobro procediera. Ahora queda la historia completa y el cobro se cancela o se marca para reembolso.
