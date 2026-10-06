# Bitácora de Novedades (despacho de tickets)

Réplica de `modules/bitacora/novedades_*` de SEGCAT (`novedades_lista.php`, `novedades_proceso.php`, `novedades_categoria_ajax.php`, `novedades_detalle_ajax.php`, `ficha_hechos.php`, `lf_buscar_coincidencias.php`, `lf_vincular_perdida.php`, `lf_acuse.php` y los fragmentos `fragmentos/frag_*.php`; inventario §4.23). Pantalla `/novedades` (menú Operación). `/novedades/lost-found` es la misma pantalla filtrada a Lost & Found (ruta del submódulo `lost_found` en el menú).

- Controlador: `app/Http/Controllers/Seguridad/NovedadController.php` (solo arma pantallas y respuestas)
- Reglas: `app/Services/Novedades/AdministradorNovedades.php` (alcance, alta, expediente, estatus, reapertura, vincular, Minuto a Minuto, auditoría)
- Formatos por categoría: `app/Services/Novedades/Formatos/*` (`Formato` base + `ReporteGeneral`, `Accidente`, `ValoresVista`, `Siniestro`, `RecorridoPc`, `LostFound`, `Robo`). Cada uno: `relaciones()`, `valores()`, `validar()`, `guardar()`.
- Buscar coincidencias: `app/Services/Novedades/CoincidenciasLostFound.php`
- Ficha de Hechos: `app/Services/Novedades/FichaHechos/FichaDeHechos.php` + interfaz `FuenteFichaHechos`
- Modelos: `Novedad`, `NovedadNota`, `NovedadTestigo`, `NovedadReporteGeneral`, `Accidente{Huesped,Colaborador,Dictamen,Guardavidas,Incapacidad,Firma}`, `ValoresVista{Detalle,Persona,Apertura,Zona}`, `Siniestro{Detalle,Servicio,Equipo,Dano}`, `RecorridoPcPunto`, `LostFound{Detalle,Articulo,ReportePerdida,Umbral,Entrega}`, `RoboDetalle` (todos con `PerteneceAEmpresa` y `RegistraAutor`)
- Migración: `2026_10_10_000300_crear_bitacora_de_novedades`
- Vistas: `resources/views/seguridad/novedades/{index,_alta,_expediente,imprimir,acuse,ficha-hechos}.blade.php` y `formatos/*.blade.php` (un parcial por categoría y uno por fila dinámica)
- JS: bloque "Bitácora de Novedades" al final de `public/js/plataforma.js`; CSS: bloque al final de `public/css/plataforma.css` y `public/css/modos-pantalla.css`
- Pruebas: `tests/Feature/Seguridad/NovedadesTest.php` (18) y `NovedadesFormatosTest.php` (12), base común `PruebaNovedades.php`

## Tablas

Todas llevan `empresa_id` (scope de tenant) y autoría `creado_por` / `actualizado_por` + timestamps. Las fechas con hora (`ocurrio_en`, `cerrado_en`, `controlado_en`) se guardan en **UTC** y se muestran con `@fecha` en la zona de la sede.

| Tabla | Qué guarda | Notas |
|---|---|---|
| `novedades` | El ticket: `sede_id`, `numero` (consecutivo por empresa → `#00012`), `categoria`, `estatus`, `reportado_por` (texto) + `reportado_colaborador_id` (si se escaneó el gafete), `asignado_a` (usuario), `area_id` (edificio o piso de Zonas y áreas), `area_especifica_id` (habitación), `ubicacion`, `involucrados`, `ocurrio_en`, `descripcion`, `como_sucedio`, `resolucion`, `cerrado_en`, `cerrado_por`, `origen_novedad_id` | Único `(empresa_id, numero)`. |
| `novedad_notas` | Minuto a Minuto: una fila por nota (`tipo` nota / sistema / reapertura, `autor_nombre`, `texto`) | Solo se agrega: no hay ruta para editar ni borrar. |
| `novedad_testigos` | Testigos de Accidente, Siniestro y Robo (`formato`, `nombre`, `departamento`, `declaracion`) | Antes tres tablas iguales. |
| `novedad_reportes_generales` | Reporte General: observados, actividad, motivo, acciones inmediatas | Antes columnas `ig_*` y `acciones_inmediatas` en la cabecera. |
| `accidente_huespedes`, `accidente_colaboradores`, `accidente_dictamenes`, `accidente_guardavidas`, `accidente_incapacidades` | Las 4 secciones del Accidente (1:1) | Heridas y zonas del cuerpo como JSON. |
| `accidente_firmas` | Una fila por firma (`rol`: afectado, seguridad, medico, jefe, rh, ejecutivo) con la `ruta` en el disco **privado** | Antes 6 columnas base64. Único `(novedad_id, rol)`. |
| `valores_vista_detalles` + `_personas`, `_aperturas`, `_zonas` | Valores a la Vista (SEGCAT HABITACION) | |
| `siniestro_detalles` + `siniestro_servicios`, `_equipos`, `_danos` | Siniestro de Protección Civil | `accidente_novedad_id`: ticket de Accidente generado (una vez). |
| `novedad_recorrido_puntos` | Recorrido PC histórico (identificador, categoría, ubicación, `criterios` JSON, observaciones) | Ya no se crean tickets Recorrido PC aquí. |
| `lost_found_detalles` | Folio / enlace de plataforma externa | |
| `lost_found_articulos` | Artículos encontrados: `numero`, `folio` (`LF-000123`), objeto, `tipo_valor`, marca, color, habitación, lugar, ubicación en bodega, `estatus` (nace `EN_RESGUARDO`), `cerrado_en` | Únicos `(empresa_id, numero)` y `(empresa_id, folio)`. |
| `lost_found_reportes_perdida` | Reportes de pérdida (`RP-000123`), datos del huésped, `estatus` BUSCANDO / VINCULADO / CERRADO_SIN_HALLAZGO, `articulo_vinculado_id`, `vinculado_en/por` | |
| `lost_found_umbrales` | Días de resguardo por tipo de valor (ALTO_VALOR 180, ELECTRONICO 180, OTRO 90, ROPA 30, PERECEDERO 2) | La migración siembra los de las empresas existentes; las nuevas usan `LostFoundUmbral::POR_OMISION` hasta configurarlos. |
| `lost_found_entregas` | Cierre / entrega de un artículo (persona, paquetería, donado, destruido, beneficencia, firma privada) | La registra «Cerrar / Entregar» del [archivo de Lost & Found](lost-found-y-robo.md). |
| `robo_detalles` | Circunstancias, sospechoso, parte policial, canalización, `articulo_vinculado_id` | Se crea al despachar un Robo; se atiende también en [Robo — Seguimiento](lost-found-y-robo.md). |

### Mapeo para el importador de SEGCAT

| SEGCAT | Plataforma |
|---|---|
| `bitacora_novedades.id_novedad` | `novedades.numero` (se conserva el número) |
| `id_hotel` | `sede_id` |
| `fecha_hora_reporte` / `fecha_hora_suceso` | `created_at` / `ocurrio_en` (convertir de hora local de la sede a UTC) |
| `ubicacion_hecho`, `reportado_por`, `descripcion`, `como_sucedio`, `personas_areas_involucradas`, `resolucion_final`, `fecha_cierre` | `ubicacion`, `reportado_por`, `descripcion`, `como_sucedio`, `involucrados`, `resolucion`, `cerrado_en` |
| `area_general` + `id_seccion` | `area_id` (edificio o piso de Zonas y áreas) |
| `id_area_especifica` | `area_especifica_id` |
| `categoria` (`INCIDENTE GENERAL`, `HABITACION`…) | `categoria` según `Novedad::CATEGORIAS_SEGCAT` |
| `estatus_caso` | `estatus` según `Novedad::ESTATUS_SEGCAT` |
| `notas_seguimiento` (un texto) | `novedad_notas`: partir por línea `[fecha] autor: texto` |
| `ig_*`, `acciones_inmediatas` | `novedad_reportes_generales` |
| `registrado_por`, `guardia_asignado`, `actualizado_por` | `creado_por`, `asignado_a`, `actualizado_por` |
| `requiere_seguimiento` | Se descarta (siempre 1 en SEGCAT) |
| `accidente_huesped/colaborador/medico/guardavidas/rh` | `accidente_huespedes/colaboradores/dictamenes/guardavidas/incapacidades` |
| `accidente_firmas` (6 base64) | Guardar cada imagen con `Firmas::guardar()` y una fila en `accidente_firmas` |
| `accidente_testigos`, `siniestro_pc_testigos`, `robo_testigos` | `novedad_testigos` con `formato` accidente / proteccion_civil / robo |
| `bitacora_habitaciones_detalles` + `habitacion_personas/aperturas/valores_zona` | `valores_vista_detalles` + `_personas/_aperturas/_zonas` |
| `siniestro_pc_*` | `siniestro_detalles` + `siniestro_servicios/_equipos/_danos` |
| `bitacora_recorridos_pc` | `novedad_recorrido_puntos` |
| `lost_found_detalle/articulos/reportes_perdida/umbrales/entregas` | `lost_found_*` (conservar los folios LF-/RP-) |
| `robo_detalles` | `robo_detalles` |

## Rutas (endpoints) y permisos

| Método | Ruta | Nombre | Permiso |
|---|---|---|---|
| GET | `/novedades` (`?abrir=ID` abre el expediente) | `novedades.index` | `novedades.ver` o `lost_found.ver` |
| GET | `/novedades/lost-found` | `lost_found.index` | ídem (solo tickets Lost & Found) |
| POST | `/novedades` | `novedades.store` | `novedades.crear` (o `lost_found.crear`: solo categoría Lost & Found) |
| PUT | `/novedades/{id}` | `novedades.update` | `editar` sobre el ticket |
| POST | `/novedades/{id}/reabrir` (`motivo`) | `novedades.reabrir` | `novedades.reabrir` |
| GET | `/novedades/{id}/imprimir` | `novedades.imprimir` | `imprimir` sobre el ticket |
| GET | `/novedades/{id}/acuse` | `novedades.acuse` | `imprimir` (solo Lost & Found) |
| GET | `/novedades/exportar?q=&categoria=&sede=&pestana=` | `novedades.exportar` | `novedades.exportar` (CSV con BOM, mismos filtros que la pantalla) |
| GET | `/novedades/{id}/firmas/{rol}` | `novedades.firma` | `ver` sobre el ticket (firma desde el disco privado) |
| GET | `/novedades/ficha-hechos?habitacion=&fecha=&novedad=&origen=&origen_id=` | `novedades.ficha-hechos` | `ver` (habitación de sus sedes) |
| GET | `/novedades/coincidencias?tipo_valor=&objeto=&marca=&color=&fecha=` | `novedades.coincidencias` | `ver` (JSON) |
| POST | `/novedades/perdidas/{reporte}/vincular` (`articulo_id`) | `novedades.perdidas.vincular` | `editar` sobre el ticket del reporte |
| POST | `/novedades/{id}/vincular-hallazgo` (`articulo_id`) | `novedades.robo.vincular` | `novedades.editar` sobre el Robo |

Alcance: con alcance de **sede** se ve y se toca solo lo de sus sedes (otra sede u otra empresa → 404); con **propios**, solo lo que registró. `lost_found.*` (submódulo) deja trabajar **solo** tickets Lost & Found (p. ej. Ama de Llaves), como en SEGCAT; el selector de categoría solo le ofrece esa. El superadministrador elige la empresa de trabajo.

Plantillas de rol: el **Agente** (módulo de Operación) despacha, atiende e imprime tickets de su sede, pero no reabre ni exporta. Supervisor y Jefe de seguridad, todo en su sede.

## Reglas

- **Despachar** (Preguntas Base): sede activa de su alcance, categoría (si no sabe: Sin clasificar), ¿quién reporta? (texto, «Fui yo quien lo observó» o gafete escaneado con el lector universal → `reportado_colaborador_id`), ¿a quién se canaliza? (usuario **activo** de la empresa con rol en toda la empresa o en esa sede; SEGCAT lo ignoraba), Área General (edificio y piso de la sede, activos; el piso debe ser de ese edificio), ubicación, involucrados, ¿cuándo? (hora local de la sede, no futura), ¿qué sucedió?, ¿cómo sucedió?. Textos clave en mayúsculas.
- **Expediente**: preguntas base + formato de la categoría (todos los parciales se pintan en el servidor; el JS solo muestra el elegido y deshabilita los demás para que no se envíen) + nota del Minuto a Minuto + estatus.
- **Estatus**: `abierto` ⇄ `pendiente_turno` → `resuelto`. Resuelto exige **resolución** y fija `cerrado_en`/`cerrado_por`. Cada cambio de estatus deja una nota de sistema. Un caso Resuelto **no se edita** (ni con una nota) hasta **Reabrir Caso** con motivo (mín. 5 letras) → vuelve a `abierto`, borra el cierre y anota «REABRIÓ EL CASO — Motivo: …».
- **Recorrido PC** ya no se elige al despachar ni al cambiar categoría (tiene su propio módulo); solo se conserva en los tickets que ya la traían.
- **Accidente**: formulario idéntico a `frag_accidente.php` (secciones, campos, etiquetas, opciones, orden y las 6 firmas). Se guarda huésped **o** colaborador según «Tipo de Afectado»; testigos y RH van con el de colaborador; el dictamen siempre; guardavidas solo si trae nombre; cada firma solo si se capturó (reemplaza y borra el archivo anterior). Departamento y puesto del colaborador se toman de su expediente.
- **Siniestro PC** con lesionados abre **una sola vez** un ticket de Accidente ligado (`origen_novedad_id`), con nota en ambos.
- **Lost & Found**: artículos nacen `EN_RESGUARDO`, el folio se asigna una vez (consecutivo por empresa); el estatus no se cambia desde el expediente. Un artículo ya entregado o vinculado, y un reporte ya vinculado, no se pueden quitar. La habitación de cada artículo debe colgar del Área General del ticket. Semáforo de resguardo con los umbrales.
- **Buscar coincidencias**: artículos en resguardo visibles para el usuario, mismo tipo de valor, objeto/marca/color parecidos, encontrados de 7 días antes a 30 días después de la fecha (máx. 15).
- **Vincular**: reporte BUSCANDO + artículo en resguardo → VINCULADO (no cierra el artículo). Robo no resuelto ← hallazgo en resguardo.
- **Ficha de Hechos**: tickets, Valores a la Vista, artículos y reportes de pérdida de la habitación (y de su zona, como contexto) de 7 días antes a 30 después. **Gancho** para otros módulos (préstamos de llaves, accesos): implementar `FuenteFichaHechos` y etiquetarla en su proveedor con `$this->app->tag([MiFuente::class], FichaDeHechos::ETIQUETA)`; `disponible()` debe revisar `Schema::hasTable` y el permiso.
- Auditoría: `novedades.creado`, `novedades.actualizado`, `novedades.resuelto`, `novedades.reabierto`, `novedades.hallazgo_vinculado`, `lost_found.vinculado`.
- `AdministradorColaboradores::REFERENCIAS` incluye `novedades.reportado_colaborador_id` y `accidente_colaboradores.colaborador_id` (unir duplicados los mueve).

## Qué se corrigió respecto a SEGCAT

- Formatos por categoría cargados como parciales Blade del servidor (antes HTML inyectado por AJAX con `innerHTML`) y sin `onclick`; CSRF en todo.
- Validación completa en el servidor con mensajes en español dentro del diálogo (antes casi todo se aceptaba sin revisar).
- Las 6 firmas del Accidente van al disco privado y se sirven solo con permiso y alcance (antes base64 en la base de datos, visibles para cualquiera con el ID).
- «Minuto a Minuto» en filas que no se editan ni se borran (antes un solo texto que crecía con `CONCAT`).
- Tablas normalizadas: lo de cada categoría ya no vive en columnas de la cabecera; tres tablas de testigos iguales pasaron a una.
- «¿A quién se canaliza?» se valida (usuario activo con acceso a la sede); antes se ignoraba en silencio un id inválido.
- Área General con edificio y piso de Zonas y áreas (antes `area_general` texto + `id_seccion`), validada contra la sede.
- Fechas en UTC y mostradas en la zona de la sede; «¿Cuándo sucedió?» no puede ser futura.
- Reapertura exige motivo y queda en el historial y en la auditoría; un caso Resuelto ya no se puede editar «por debajo».
- Número de ticket y folios LF-/RP- consecutivos **por empresa** (antes salían del id global de la tabla, compartido entre empresas).
- Exportación CSV con los mismos filtros y alcance que la pantalla.
