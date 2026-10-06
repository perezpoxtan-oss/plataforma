# Lost & Found (archivo) y Robo — Seguimiento

Completan la [Bitácora de Novedades](novedades.md). Los artículos encontrados, los reportes de pérdida y los casos de robo **nacen** en un ticket de Novedades; aquí se consultan, se entregan, se imprimen y se les da seguimiento.

Réplica de `modules/bitacora/lf_archivo.php`, `lf_articulo_cerrar_modal.php`, `lf_articulo_proceso.php`, `lf_etiqueta.php`, `lf_auditoria_pdf.php`, `lf_config_umbrales.php`, `lf_alertas.php` (redirección), `robo_archivo.php`, `robo_modal_editar.php` y `robo_vincular_hallazgo.php` (inventario §4.24 y §4.25).

| Pieza | Archivo |
|---|---|
| Controladores | `app/Http/Controllers/Seguridad/LostFoundController.php`, `RoboController.php` (solo arman pantallas) |
| Reglas de Lost & Found | `app/Services/Novedades/ArchivoLostFound.php` (alcance, filtros, Cerrar / Entregar, Días de Resguardo) |
| Reglas de Robo | `AdministradorNovedades::limitarRobo()` / `permiteRobo()` + `actualizar()` y el formato `Formatos/Robo` (las mismas del expediente de Novedades) |
| Modelos | `LostFoundArticulo` (ahora `Identificable`), `LostFoundEntrega`, `LostFoundUmbral`, `RoboDetalle` |
| Migración | `2026_10_10_000310_completar_lost_found_y_robo` |
| Vistas | `resources/views/seguridad/lost-found/{index,show,_cerrar,etiqueta,auditoria,umbrales}.blade.php`, `seguridad/robo/{index,_expediente}.blade.php` (reusa `novedades/formatos/robo.blade.php`) |
| JS / CSS | Bloque «Lost & Found (archivo) y Robo — Seguimiento» al final de `public/js/plataforma.js`, `public/css/plataforma.css` y `public/css/modos-pantalla.css` |
| Pruebas | `tests/Feature/Seguridad/LostFoundRoboTest.php` (21) |

## Tablas (cambios de esta migración)

| Tabla | Columna nueva | Para qué |
|---|---|---|
| `lost_found_articulos` | `codigo_qr` (32, único) | Va en el QR de la etiqueta de la bolsa (aleatorio, no adivinable). Los artículos que ya existían reciben el suyo en la migración. |
| | `etiqueta_nfc` (64, único por empresa) | Opcional, por si se pega un chip NFC a la bolsa (ADR-0005). |
| | `cerrado_por` | Quién registró el cierre (`cerrado_en` ya existía). |
| `lost_found_entregas` | `colaborador_id` | Quien recibe es un colaborador (donación o devolución). Registrado en `AdministradorColaboradores::REFERENCIAS`. |
| | `persona_id` | Quien recibe se registró en el Padrón de personas. |

Todas llevan `empresa_id` (scope de tenant) y autoría `creado_por` / `actualizado_por`.

### Importador de SEGCAT

| SEGCAT | Plataforma |
|---|---|
| `lost_found_entregas.firma_base64` | Guardar la imagen con `Firmas::guardar(…, 'lost-found', …)` y su ruta en `firma_ruta` |
| `lost_found_entregas.cerrado_por`, `fecha_cierre` | `creado_por`, `created_at`; también `lost_found_articulos.cerrado_por` / `cerrado_en` |
| `lost_found_umbrales` (global) | `lost_found_umbrales` por empresa |
| `lost_found_articulos` | generar `codigo_qr` (lo hace el modelo al crear) |

## Rutas (endpoints) y permisos

| Método | Ruta | Nombre | Permiso |
|---|---|---|---|
| GET | `/lost-found?filtro=&q=&sede=&tipo=` | `lost_found.archivo` | `lost_found.ver` (menú «Lost & Found») |
| GET | `/lost-found/articulos/{id}` | `lost_found.articulos.show` | `lost_found.ver` (a donde lleva el QR) |
| POST | `/lost-found/articulos/{id}/cerrar` | `lost_found.articulos.cerrar` | `lost_found.firmar` |
| GET | `/lost-found/articulos/{id}/etiqueta` | `lost_found.articulos.etiqueta` | `lost_found.imprimir` |
| GET | `/lost-found/entregas/{id}/firma` | `lost_found.entregas.firma` | `lost_found.ver` (firma desde el disco privado) |
| GET | `/lost-found/auditoria?sede=&desde=&hasta=` | `lost_found.auditoria` | `lost_found.imprimir` |
| GET | `/lost-found/dias-resguardo` | `lost_found.umbrales` | `lost_found.ver` (solo lectura) |
| PUT | `/lost-found/dias-resguardo` | `lost_found.umbrales.guardar` | `lost_found.configurar` **con alcance de empresa** |
| GET | `/robos?filtro=&q=&sede=&abrir=` | `robo.index` | `robo.ver` (menú «Robo — seguimiento») |
| PUT | `/robos/{id}` | `robo.update` | `robo.editar` |

Desde Robo se reutilizan los endpoints de Novedades: `novedades.coincidencias` (Buscar Coincidencias), `novedades.robo.vincular` (Vincular), `novedades.ficha-hechos`, `novedades.imprimir` y la reapertura en `novedades.index?abrir=`. Cada uno conserva su propio permiso.

- **Alcance:** con alcance de sede solo se ve y se toca lo de sus sedes; con «propios», lo que registró. Otra sede u otra empresa → **404**. El superadministrador elige la empresa de trabajo.
- **Lost & Found** usa solo los permisos del submódulo `lost_found` (ver, imprimir, firmar, configurar). Los tickets de Novedades siguen con `novedades.*` o `lost_found.*`.
- **Robo** usa los permisos del submódulo `robo`. Quien solo tiene `robo.*` (sin `novedades.*`) atiende su caso sin poder cambiarle la categoría (`AdministradorNovedades::categorias()` devuelve `['robo']`).
- **Plantillas de rol:** el Agente ve, entrega (firma) e imprime en su sede, pero no configura los días. El Jefe de seguridad tiene «configurar» con alcance de sede, así que tampoco los cambia (son de toda la empresa); el Administrador sí. El Director consulta e imprime.
- **Menú:** `CatalogoSeeder::RUTAS` lleva `lost_found → lost_found.archivo` (antes los tickets filtrados; siguen en `/novedades/lost-found`) y `robo → robo.index`.
- **Lector universal:** tipo `lost_found` en `config/lector.php` (permiso `lost_found.ver`). Se encuentra por el QR de la etiqueta (`/e/{codigo}`) o tecleando el folio `LF-000123`.

## Reglas

- **Archivo:** pestañas Todos / En resguardo / Solo urgentes o vencidos / Devueltos / Donados, destruidos o beneficencia, con su conteo; búsqueda por folio, objeto, marca, color, bodega, lugar, habitación o ubicación del ticket; sede y tipo de valor. Agrupado por mes (hora local); «Solo urgentes» pone primero lo vencido (máximo 500). 60 por página. Arriba, los tickets de Lost & Found **abiertos sin ningún artículo** («Sin artículos capturados aún» → Completar).
- **Semáforo:** días en resguardo contra los Días de Resguardo de su tipo de valor: verde, ámbar (≥ 70 %), rojo (≥ 100 %). Solo para lo que sigue en resguardo.
- **Cerrar / Entregar** (solo artículos `EN_RESGUARDO`):

| ¿Cómo se cierra? | Pide | Estatus que queda |
|---|---|---|
| Devuelto en persona | Huésped o persona externa (nombre obligatorio, identificación, correo; opcional el Padrón de personas) **o** colaborador (lector universal) | `DEVUELTO` |
| Enviado por paquetería | Nombre, paquetería (FedEx, DHL, Estafeta, Otro), número de guía, correo | `DEVUELTO` |
| Donado a colaborador | Colaborador (lector universal) | `DONADO` |
| Destruido | — | `DESTRUIDO` |
| Entregado a beneficencia | Institución que recibe | `ENTREGADO_BENEFICENCIA` |

  Siempre con **firma** (quien recibe o quien autoriza, según el tipo; se borra si se cambia el tipo). En una transacción con bloqueo: entrega, estatus, `cerrado_en` / `cerrado_por`, nota de sistema en el Minuto a Minuto del ticket (y del Robo vinculado). Si algo falla, la firma guardada se borra. Un reporte de pérdida vinculado propone el nombre y correo de quien recibe.
- **Transiciones prohibidas:** un artículo cerrado no se vuelve a cerrar (SEGCAT agregaba cierres repetidos); no hay vuelta a `EN_RESGUARDO`.
- **Etiqueta:** QR local (`bacon/bacon-qr-code`) con `route('lector.ir', codigo_qr)`, folio, objeto, fecha y lugar, marca/color/tipo y bodega. Si el artículo ya se cerró, lo dice en rojo.
- **Auditoría de Inventario:** lo que está `EN_RESGUARDO` en el alcance de «imprimir», ordenado por sede, bodega y folio; filtros de sede y de fecha en que se encontró (desde/hasta en hora local). Casillas, renglones de discrepancias y firmas «Realizó el conteo» / «Cotejó / Gerente de Seguridad».
- **Días de Resguardo:** por empresa, de 1 a 3650 días por tipo de valor; las empresas sin configurar usan los de SEGCAT (`LostFoundUmbral::POR_OMISION`).
- **Robo — Seguimiento:** filtros Todos / Abiertos / Sin parte a la policía / Con sospechoso con su conteo, búsqueda por #ticket, ubicación, lugar exacto, qué se llevaron o descripción, y sede; 40 por página. El expediente (`?abrir=ID`) pide nota, estatus (Abierto / Pendiente de turno si ya lo estaba / Resuelto y Cerrado), resolución y las 4 secciones; guarda con `AdministradorNovedades::actualizar()` (Resuelto exige resolución; un caso Resuelto no se edita hasta reabrirlo en Novedades). Solo se aceptan esos campos: ubicación, descripción y categoría no se tocan desde aquí.
- **Auditoría:** `lost_found.cerrado` (antes/después con tipo, quién recibe, guía), `lost_found.configurado` (días antes/después, sobre la empresa); Robo deja `novedades.actualizado` / `novedades.resuelto` y `novedades.hallazgo_vinculado`.

## Qué se corrigió respecto a SEGCAT

- Firma del cierre en el disco **privado** y servida solo con permiso y alcance (antes `firma_base64` en la base).
- Un artículo cerrado ya no se cierra otra vez; el cierre es atómico (bloqueo de fila) y queda en el Minuto a Minuto y en la auditoría.
- Validación en el servidor con mensajes en español dentro del diálogo (antes se guardaba casi cualquier cosa; un tipo de cierre inválido mostraba una página en blanco).
- Quien recibe puede ser un **colaborador** (lector universal) o una **persona del padrón**; la donación exige colaborador.
- Etiqueta con QR generado en la plataforma y con una dirección no adivinable (antes `api.qrserver.com` con el folio); escanearla abre la ficha del artículo.
- Días de Resguardo **por empresa** y con su propio permiso `lost_found.configurar` (antes una tabla global para todos los clientes, protegida con `permisos.ver`/`editar`).
- Auditoría de Inventario por sede y por rango de fechas, solo con «imprimir», con el alcance del usuario.
- Robo con su propio permiso (`robo.*`; antes `bitacora.*`) y alcance por sede; el expediente guarda con las mismas reglas que Novedades (antes `fetch` que ignoraba los errores y recargaba).
- Sin `onclick`, sin HTML inyectado por AJAX, CSRF en todo, consultas con escape de comodines y sin N+1.
