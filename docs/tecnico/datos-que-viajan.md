# Datos que viajan entre módulos (Etapa 6: C-29, C-31, C-32, C-33, C-19)

Decisión: [ADR-0022](../decisiones/ADR-0022-datos-que-viajan.md). Plan de correcciones, Etapa 6.

## C-29 · Protección Civil

| Pieza | Dónde |
|---|---|
| Categoría de fábrica del hallazgo: `mantenimiento_pc` | `AjustesNovedades::CATEGORIA_HALLAZGO_OMISION` (solo se guarda en `preferencias.novedades.categoria_hallazgo_pc` si es distinta) |
| Punto con falla sin `novedades.crear` | `AdministradorRecorridosPc::reportarHallazgo()` → `AdministradorNovedades::abrirPorLaPlataforma()` (nota «Ticket abierto por la plataforma», auditoría `novedades.creado` con `abierto_por=plataforma`; el aviso al jefe sigue en `AvisosNovedades::hallazgo`) |
| Filtro único | `Novedad::GRUPO_PC = 'pc'` y `Novedad::SUBTIPOS_PC`; la lista marca `data-grupo="pc"` y el Excel acepta `categoria=pc` |
| Reclasificación opcional | `AjustesNovedades::hallazgosComoSiniestro()` (tickets `proteccion_civil` ligados a `recorridos_pc.novedad_id` sin siniestro documentado) y `reclasificarHallazgos()`; ruta `PUT configuracion/novedades/reclasificar-hallazgos` (`configuracion.editar` con alcance de empresa). Nota por ticket y auditoría `novedades.hallazgos_reclasificados` con los folios |

Recarga y frecuencia por tipo ya llegaron en la Etapa 4 (DD-17).

## C-31 · Datos que viajan

**Ficha de Hechos.** Fuentes en `app/Services/Novedades/FichaHechos/`, registradas en `AccesosServiceProvider` con la etiqueta `FichaDeHechos::ETIQUETA`:

- `PrestamosLlavesEnFicha` (`prestamo_llaves.ver`): llaves de la sede con alcance `area` (la habitación), `piso` o `zona` (ancestros por `espacios.ruta`), `seccion` (`grupo_espacio_id`) y `global`; préstamos no anulados que se traslapan con la ventana (prestado ≤ hasta y devuelto nulo o ≥ desde). También la asignación permanente (`llaves.colaborador_id`). Alcance por `AdministradorPrestamosLlaves::limitar()`.
- `AccesosEnFicha` (`accesos.ver`): accesos de entrada con `espacio_id` = la habitación; en la zona, solo proveedor, contratista y visitante en otros espacios del subárbol. Alcance por `ConsultaAccesos::limitar()`.

**Eventos del día.** `App\Services\Reservas\EventosDelDia`: reservas `confirmada` cuyo bloqueo (montaje incluido) toca el día local. Usos: tarjeta «Eventos de hoy» en Accesos (`AccesoController::index`, sedes de `accesos.ver`, solo si el módulo `reservas` está activo; no en el historial), tipo `reservas` de `BitacoraDelDia` (`reservas.ver`, «Inicia evento» y «Termina evento») y bloque «Eventos de hoy» del `InformeEjecutivo` (`reservas.ver`). Solo lee área, horario, título y asistentes.

**¿Qué hacer?** `App\Services\Procedimientos\ProcedimientosVinculados`: mapa `preferencias.procedimientos_vinculados` (`novedad:<categoría>` y `equipo:<tipo>` → id de procedimiento publicado). Se ofrece si el módulo `procedimientos` está activo; se muestra a quien tiene `procedimientos.ver` y el procedimiento está en su alcance y publicado. Ruta `PUT configuracion/novedades/procedimientos`. Accidente no se ofrece.

## C-32 · Novedades por giro

- `AdministradorNovedades::categorias()` quita `habitacion` (Valores a la Vista) fuera de hotel, salvo en un ticket que ya la tiene.
- `Terminos`: «Lost & Found (Objetos Perdidos)» → «Objetos olvidados».
- Subtipos de Reporte General: columna `novedad_reportes_generales.subtipo` (migración `2026_11_15_000100`); lista en `preferencias.novedades.subtipos_reporte_general` o de fábrica por giro (`AjustesNovedades::SUBTIPOS_REPORTE_GENERAL`). Validación en `Formatos/ReporteGeneral` (la lista o el valor que ya tenía). `Novedad::etiquetaConSubtipo()` en la lista, la búsqueda y el Excel.
- `AdministradorNovedades::revisarReclasificacion()`: no cambia de categoría un ticket `lost_found` con artículos `EN_RESGUARDO` ni un `robo` con detalle capturado.

## C-33 · Accidentes (el formulario no se toca)

- `Formatos/AccidenteConSugerencias` (mapeado en `AdministradorNovedades::FORMATOS`) hereda de `Formatos/Accidente` y solo cambia `valores()` de un caso sin formato guardado: `h_/c_fecha_accidente`, `h_/c_hora_accidente` (de `ocurrio_en` o `created_at` en la hora de la sede), `h_hab` (habitación de catálogo) y `h_lugar` (ubicación del ticket). La vista y la validación son las de siempre.
- `Novedades/Accidentes/TurnoDelAccidente::completar()` se llama después de `Formato::guardar()` en `AdministradorNovedades::actualizar()`: si `accidente_colaboradores.turno` quedó vacío y la ficha tiene `turno_id`, guarda el nombre del turno.
- Pestaña «Accidentes» en la ficha (`ExpedienteController::index`, vista `ficha/_accidentes`): permiso `colaboradores.accidentes` («Ver accidentes»), en `DependenciasModulos::ACCIONES_CON_MODULO` con `novedades`. Plantillas: Administrador y Recursos Humanos (regla general) y Jefe de seguridad (`RolesPlantillaSeeder::ACCIDENTES_JEFE`). Migración `2026_11_15_000200` la otorga a los roles que ya tenían `colaboradores.datos_personales` o `novedades.reabrir`, con el alcance de su `colaboradores.ver`.

Comprobación: `git diff origin/feature/etapa5-caseta-giros -- app/Services/Novedades/Formatos/Accidente.php resources/views/seguridad/novedades/formatos/accidente.blade.php resources/views/seguridad/novedades/formatos/_fila-testigo-accidente.blade.php app/Http/Requests` no muestra cambios.

## C-19 · Lo que trae el proveedor

| Pieza | Dónde |
|---|---|
| Tabla `accesos_articulos` (artículo, cantidad, serie; se borra con su acceso) | Migración `2026_11_15_000300`, modelo `ArticuloAcceso` (máximo 10) |
| Columnas en `accesos`: `articulos_salida` (`mismo`/`distinto`), `articulos_nota`, `articulos_por_revisar`, `articulos_revisado_en/_por`, `pase_salida_id` | misma migración |
| Captura (solo proveedor y contratista; renglones vacíos se ignoran) | `RegistroAccesos::registrar()`; vista `_ingreso` + `_articulo` |
| Comparación al salir, canalización y pase | `ArticulosDelProveedor::alDarSalida()` desde `AccesoController::salida`; diálogo de salida (`casillasDevolucion` en `plataforma.js`) con `articulos_revisados`, `sale_con_lo_mismo`, `articulos_nota`, `pase_salida_id` |
| Revisión | `PATCH accesos/{acceso}/revisar-articulos` (`accesos.aprobar`), reloj `ArticulosPorRevisar` (`articulos_caseta`), aviso `acceso_articulos`, filtro `?articulos=1` |

El pase que se muestra es el `aprobado` de la misma sede y la misma empresa externa (`proveedor_id`); se lee una sola vez por petición. Sin `articulos_revisados` (pantallas de antes, app con caché) la salida es la de siempre.

**No se hizo:** la foto opcional de lo que trae (B-07 punto 1): obligaría a sumar un archivo personal más a ARCO y a la conservación; la caseta ya tiene la foto de la persona. El «inventario declarado» por empresa externa queda para una fase posterior (B-07 punto 4).
