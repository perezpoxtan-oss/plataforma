# Integridad de la base de datos (mantenimiento 2026-11)

Entrega de mantenimiento aprobada por el dueño a partir del análisis de normalización del 15 de octubre de 2026 (documento del proyecto «claude/analisis_normalizacion_bd_2026-10-15.md»). Sin cambios visibles: nada de pantallas, formularios ni textos (los de Accidentes no se tocaron).

| Pieza | Dónde |
|---|---|
| Migraciones (todas con `down()` e idempotentes) | `2026_11_09_000100_foraneas_restrict_desde_empresas`, `…000200_foraneas_restrict_hacia_padres_con_historia`, `…000300_empresa_en_pasajeros_de_transporte`, `…000400_autoria_en_modulos_de_empresa_y_pasos`, `…000500_normalizar_estados_antes_de_check`, `…000600_check_en_estados_y_categorias` |
| Restricciones por motor | `app/Support/BaseDatos/Restricciones.php` |
| CHECK desde las constantes | `app/Support/BaseDatos/CatalogoChecks.php` |
| Revisión de coherencia | `app/Services/BaseDatos/RevisionCoherencia.php`, comando `plataforma:revisar-coherencia` |
| Pruebas | `tests/Feature/Nucleo/IntegridadBaseDatosTest.php` (SQLite en la suite y MariaDB en su propio paso de la CI), `.github/scripts/respaldo-mariadb.php` |

## 1. Llaves foráneas: de cascade a restrict en lo que tiene historia

**Antes:** 217 llaves borraban «en cascada»; borrar una empresa (o un acceso, una novedad, un pase…) se llevaba en silencio toda su historia.

**Ahora:** las 95 llaves de tablas con valor legal o histórico son `restrict`: la base se niega a borrar el padre mientras tenga esa historia. Las demás (configuración, catálogos, tablas puente y el borrado propio de un candidato) siguen en cascada.

Por qué no rompe ningún flujo:

- **Ninguna pantalla borra empresas.** La baja es `empresas.activo = false` y el modelo usa `SoftDeletes`. Además `sedes.empresa_id` y `users.empresa_id` ya eran `restrict` desde su creación: una empresa con sedes o usuarios nunca se pudo borrar físicamente. Una empresa capturada por error, sin sedes ni historia, se sigue borrando con su configuración en cascada.
- **Eliminar definitivamente** (`borrado.md`) ya contaba cualquier fila que apunte al registro como dependencia, sin importar la acción de la llave; ahora además la base lo respalda.
- **Formatos de novedades, pases, reservas, procedimientos**: reescriben sus renglones de detalle (borran hijos), nunca el padre. Borrar hijos no cambia.
- **Lost & Found** solo quita del ticket un artículo en resguardo y sin vincular (sin entregas).
- **Quitar un área de reservas** con historial la desactiva; sin reservas borra solo su configuración.
- **Candidatos**: ARCO («Eliminar definitivamente») y la caducidad borran la ficha a propósito; sus postulaciones, evaluaciones, eventos, documentos, enlaces del kiosco y autorizaciones siguen en cascada **desde el candidato**. Desde la empresa, en cambio, son `restrict`.
- **Respaldos y traslado**: el volcado copia el esquema tal cual y la importación corre con la revisión de llaves apagada (ver `traslado.md`, «CHECK y llaves restrict»).
- **Pruebas que borran empresas**: no había; la prueba nueva cubre que una empresa con historia no se borra y una vacía sí.

Si algún día hace falta **purgar una empresa completa**, tendrá que ser un proceso explícito que borre en orden (hijos primero) y deje copia; la base ya no lo hará sola.

Cómo se cambia (`Restricciones::cambiarAccionAlBorrar`):

- **MariaDB/MySQL**: `ALTER TABLE … DROP FOREIGN KEY` + `ADD CONSTRAINT … ON DELETE RESTRICT`, con `FOREIGN_KEY_CHECKS=0` (los datos ya cumplían la llave; solo cambia la acción), así InnoDB no copia la tabla: segundos aunque la tabla sea grande.
- **SQLite** (pruebas y desarrollo): se edita el texto del `CREATE TABLE` en `sqlite_master` con `writable_schema` y `schema_version + 1`, el procedimiento que SQLite documenta para cambios que no tocan los datos en disco. No reconstruye la tabla, así que funciona dentro de la transacción de cada prueba aunque haya filas hijas. Al final revisa `quick_check`.
- Idempotente: si una corrida se corta entre quitar y volver a crear una llave, la siguiente la crea.

### Inventario (antes → después)

Total cascade antes: 217 · pasan a restrict: 95 · se quedan en cascade: 122

| Llave | Apunta a | Acción actual | Propuesta (aplicada) | Motivo |
|---|---|---|---|---|
| `accesos.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `accidente_colaboradores.novedad_id` | `novedades` | cascade | **restrict** | Detalle de un registro con historia |
| `accidente_colaboradores.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `accidente_dictamenes.novedad_id` | `novedades` | cascade | **restrict** | Detalle de un registro con historia |
| `accidente_dictamenes.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `accidente_firmas.novedad_id` | `novedades` | cascade | **restrict** | Detalle de un registro con historia |
| `accidente_firmas.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `accidente_guardavidas.novedad_id` | `novedades` | cascade | **restrict** | Detalle de un registro con historia |
| `accidente_guardavidas.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `accidente_huespedes.novedad_id` | `novedades` | cascade | **restrict** | Detalle de un registro con historia |
| `accidente_huespedes.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `accidente_incapacidades.novedad_id` | `novedades` | cascade | **restrict** | Detalle de un registro con historia |
| `accidente_incapacidades.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `acompanantes_acceso.acceso_id` | `accesos` | cascade | **restrict** | Detalle de un registro con historia |
| `acompanantes_acceso.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `autorizaciones.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `autorizaciones.acceso_id` | `accesos` | cascade | **restrict** | Detalle de un registro con historia |
| `autorizaciones.candidato_id` | `candidatos` | cascade | cascade (sin cambio) | Se borra con el candidato a propósito (ARCO, caducidad) |
| `avisos_privacidad.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `candidato_documentos.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `candidato_documentos.candidato_id` | `candidatos` | cascade | cascade (sin cambio) | Se borra con el candidato a propósito (ARCO, caducidad) |
| `candidato_eventos.candidato_id` | `candidatos` | cascade | cascade (sin cambio) | Se borra con el candidato a propósito (ARCO, caducidad) |
| `candidato_eventos.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `candidatos.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `colaborador_sede.sede_id` | `sedes` | cascade | cascade (sin cambio) | Configuración propia o tabla puente (Eliminar definitivamente la revisa antes) |
| `colaborador_sede.colaborador_id` | `colaboradores` | cascade | cascade (sin cambio) | Configuración propia o tabla puente (Eliminar definitivamente la revisa antes) |
| `colaboradores.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `costos_reposicion.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `delegaciones.delegado_id` | `users` | cascade | cascade (sin cambio) | Configuración del usuario (los usuarios no se borran) |
| `delegaciones.user_id` | `users` | cascade | cascade (sin cambio) | Configuración del usuario (los usuarios no se borran) |
| `delegaciones.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `departamento_puesto.puesto_id` | `puestos` | cascade | cascade (sin cambio) | Configuración propia o tabla puente (Eliminar definitivamente la revisa antes) |
| `departamento_puesto.departamento_id` | `departamentos` | cascade | cascade (sin cambio) | Configuración propia o tabla puente (Eliminar definitivamente la revisa antes) |
| `departamento_responsables.sede_id` | `sedes` | cascade | cascade (sin cambio) | Configuración propia o tabla puente (Eliminar definitivamente la revisa antes) |
| `departamento_responsables.user_id` | `users` | cascade | cascade (sin cambio) | Configuración del usuario (los usuarios no se borran) |
| `departamento_responsables.departamento_id` | `departamentos` | cascade | cascade (sin cambio) | Configuración propia o tabla puente (Eliminar definitivamente la revisa antes) |
| `departamento_responsables.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `departamento_sede.sede_id` | `sedes` | cascade | cascade (sin cambio) | Configuración propia o tabla puente (Eliminar definitivamente la revisa antes) |
| `departamento_sede.departamento_id` | `departamentos` | cascade | cascade (sin cambio) | Configuración propia o tabla puente (Eliminar definitivamente la revisa antes) |
| `departamentos.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `documentos_para_contratar.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `empresa_modulos.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `enlaces_kiosco.candidato_id` | `candidatos` | cascade | cascade (sin cambio) | Se borra con el candidato a propósito (ARCO, caducidad) |
| `enlaces_kiosco.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Se borra con el candidato a propósito (ARCO, caducidad) |
| `equipos.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `equipos_pc.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `equipos_responsiva.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `equipos_responsiva.responsiva_id` | `responsivas` | cascade | **restrict** | Detalle de un registro con historia |
| `espacio_llave.espacio_id` | `espacios` | cascade | cascade (sin cambio) | Configuración propia o tabla puente (Eliminar definitivamente la revisa antes) |
| `espacio_llave.llave_id` | `llaves` | cascade | cascade (sin cambio) | Configuración propia o tabla puente (Eliminar definitivamente la revisa antes) |
| `espacios.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `etiquetas_plantillas.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `evaluaciones_candidato.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `evaluaciones_candidato.postulacion_id` | `postulaciones` | cascade | cascade (sin cambio) | Se borra con el candidato a propósito (ARCO, caducidad) |
| `evaluaciones_candidato.candidato_id` | `candidatos` | cascade | cascade (sin cambio) | Se borra con el candidato a propósito (ARCO, caducidad) |
| `firmas_usuarios.user_id` | `users` | cascade | cascade (sin cambio) | Configuración del usuario (los usuarios no se borran) |
| `firmas_usuarios.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `gafetes.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `grupo_espacio_llave.grupo_espacio_id` | `grupos_espacio` | cascade | cascade (sin cambio) | Configuración propia o tabla puente (Eliminar definitivamente la revisa antes) |
| `grupo_espacio_llave.llave_id` | `llaves` | cascade | cascade (sin cambio) | Configuración propia o tabla puente (Eliminar definitivamente la revisa antes) |
| `grupos_espacio.sede_id` | `sedes` | cascade | cascade (sin cambio) | Configuración propia o tabla puente (Eliminar definitivamente la revisa antes) |
| `grupos_espacio.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `horarios_llave.llave_id` | `llaves` | cascade | cascade (sin cambio) | Configuración propia o tabla puente (Eliminar definitivamente la revisa antes) |
| `horarios_llave.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `importaciones.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `impresiones_etiquetas.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `impresiones_etiquetas_items.impresion_id` | `impresiones_etiquetas` | cascade | cascade (sin cambio) | Configuración propia o tabla puente (Eliminar definitivamente la revisa antes) |
| `impresiones_etiquetas_items.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `llaves.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `lost_found_articulos.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `lost_found_articulos.novedad_id` | `novedades` | cascade | **restrict** | Detalle de un registro con historia |
| `lost_found_detalles.novedad_id` | `novedades` | cascade | **restrict** | Detalle de un registro con historia |
| `lost_found_detalles.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `lost_found_entregas.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `lost_found_entregas.articulo_id` | `lost_found_articulos` | cascade | **restrict** | Detalle de un registro con historia |
| `lost_found_reportes_perdida.novedad_id` | `novedades` | cascade | **restrict** | Detalle de un registro con historia |
| `lost_found_reportes_perdida.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `lost_found_umbrales.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `modulo_acciones.modulo_id` | `modulos` | cascade | cascade (sin cambio) | Catálogo de la plataforma / permisos |
| `modulo_producto.modulo_id` | `modulos` | cascade | cascade (sin cambio) | Catálogo de la plataforma / permisos |
| `modulo_producto.producto_id` | `productos` | cascade | cascade (sin cambio) | Catálogo de la plataforma / permisos |
| `movimiento_transporte_pasajeros.movimiento_transporte_id` | `movimientos_transporte` | cascade | **restrict** | Detalle de un registro con historia |
| `movimientos_transporte.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `notificaciones.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `notificaciones.user_id` | `users` | cascade | cascade (sin cambio) | Configuración del usuario (los usuarios no se borran) |
| `novedad_notas.novedad_id` | `novedades` | cascade | **restrict** | Detalle de un registro con historia |
| `novedad_notas.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `novedad_recorrido_puntos.novedad_id` | `novedades` | cascade | **restrict** | Detalle de un registro con historia |
| `novedad_recorrido_puntos.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `novedad_reportes_generales.novedad_id` | `novedades` | cascade | **restrict** | Detalle de un registro con historia |
| `novedad_reportes_generales.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `novedad_testigos.novedad_id` | `novedades` | cascade | **restrict** | Detalle de un registro con historia |
| `novedad_testigos.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `novedades.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `paquete_plan.plan_id` | `planes` | cascade | cascade (sin cambio) | Catálogo de la plataforma / permisos |
| `paquete_plan.paquete_id` | `paquetes` | cascade | cascade (sin cambio) | Catálogo de la plataforma / permisos |
| `paquete_producto.producto_id` | `productos` | cascade | cascade (sin cambio) | Catálogo de la plataforma / permisos |
| `paquete_producto.paquete_id` | `paquetes` | cascade | cascade (sin cambio) | Catálogo de la plataforma / permisos |
| `paraderos.sede_id` | `sedes` | cascade | cascade (sin cambio) | Configuración propia o tabla puente (Eliminar definitivamente la revisa antes) |
| `paraderos.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `pases_salida.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `pases_salida_aprobaciones.pase_salida_id` | `pases_salida` | cascade | **restrict** | Detalle de un registro con historia |
| `pases_salida_aprobaciones.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `pases_salida_articulos.pase_salida_id` | `pases_salida` | cascade | **restrict** | Detalle de un registro con historia |
| `pases_salida_articulos.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `pases_salida_bitacora.pase_salida_id` | `pases_salida` | cascade | **restrict** | Detalle de un registro con historia |
| `pases_salida_bitacora.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `pases_salida_firmas.pase_salida_id` | `pases_salida` | cascade | **restrict** | Detalle de un registro con historia |
| `pases_salida_firmas.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `pases_salida_paso_usuarios.user_id` | `users` | cascade | cascade (sin cambio) | Configuración del usuario (los usuarios no se borran) |
| `pases_salida_paso_usuarios.paso_id` | `pases_salida_pasos` | cascade | cascade (sin cambio) | Configuración propia o tabla puente (Eliminar definitivamente la revisa antes) |
| `pases_salida_paso_usuarios.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `pases_salida_pasos.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `personas.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `plan_producto.producto_id` | `productos` | cascade | cascade (sin cambio) | Catálogo de la plataforma / permisos |
| `plan_producto.plan_id` | `planes` | cascade | cascade (sin cambio) | Catálogo de la plataforma / permisos |
| `postulaciones.candidato_id` | `candidatos` | cascade | cascade (sin cambio) | Se borra con el candidato a propósito (ARCO, caducidad) |
| `postulaciones.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `prestamos_llaves.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `procedimiento_acuses.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `procedimiento_acuses.version_id` | `procedimiento_versiones` | cascade | **restrict** | Detalle de un registro con historia |
| `procedimiento_acuses.user_id` | `users` | cascade | **restrict** | Detalle de un registro con historia |
| `procedimiento_adjuntos.version_id` | `procedimiento_versiones` | cascade | cascade (sin cambio) | Configuración propia o tabla puente (Eliminar definitivamente la revisa antes) |
| `procedimiento_adjuntos.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `procedimiento_aplicaciones.version_id` | `procedimiento_versiones` | cascade | cascade (sin cambio) | Configuración propia o tabla puente (Eliminar definitivamente la revisa antes) |
| `procedimiento_aplicaciones.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `procedimiento_categorias.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `procedimiento_eventos.version_id` | `procedimiento_versiones` | cascade | cascade (sin cambio) | Configuración propia o tabla puente (Eliminar definitivamente la revisa antes) |
| `procedimiento_eventos.procedimiento_id` | `procedimientos` | cascade | cascade (sin cambio) | Configuración propia o tabla puente (Eliminar definitivamente la revisa antes) |
| `procedimiento_eventos.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `procedimiento_pasos.version_id` | `procedimiento_versiones` | cascade | cascade (sin cambio) | Configuración propia o tabla puente (Eliminar definitivamente la revisa antes) |
| `procedimiento_pasos.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `procedimiento_recordatorios.user_id` | `users` | cascade | cascade (sin cambio) | Configuración del usuario (los usuarios no se borran) |
| `procedimiento_recordatorios.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `procedimiento_versiones.procedimiento_id` | `procedimientos` | cascade | cascade (sin cambio) | Configuración propia o tabla puente (Eliminar definitivamente la revisa antes) |
| `procedimiento_versiones.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `procedimientos.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `promocion_plan.plan_id` | `planes` | cascade | cascade (sin cambio) | Catálogo de la plataforma / permisos |
| `promocion_plan.promocion_id` | `promociones` | cascade | cascade (sin cambio) | Catálogo de la plataforma / permisos |
| `proveedor_sede.sede_id` | `sedes` | cascade | cascade (sin cambio) | Configuración propia o tabla puente (Eliminar definitivamente la revisa antes) |
| `proveedor_sede.proveedor_id` | `proveedores` | cascade | cascade (sin cambio) | Configuración propia o tabla puente (Eliminar definitivamente la revisa antes) |
| `proveedores.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `puestos.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `recorrido_pc_revisiones.recorrido_pc_id` | `recorridos_pc` | cascade | **restrict** | Detalle de un registro con historia |
| `recorrido_pc_revisiones.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `recorridos_pc.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `reserva_aprobaciones.reserva_id` | `reservas` | cascade | **restrict** | Detalle de un registro con historia |
| `reserva_aprobaciones.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `reserva_area_montajes.reserva_montaje_id` | `reserva_montajes` | cascade | cascade (sin cambio) | Configuración propia o tabla puente (Eliminar definitivamente la revisa antes) |
| `reserva_area_montajes.reserva_area_id` | `reserva_areas` | cascade | cascade (sin cambio) | Configuración propia o tabla puente (Eliminar definitivamente la revisa antes) |
| `reserva_areas.espacio_id` | `espacios` | cascade | cascade (sin cambio) | Configuración propia o tabla puente (Eliminar definitivamente la revisa antes) |
| `reserva_areas.sede_id` | `sedes` | cascade | cascade (sin cambio) | Configuración propia o tabla puente (Eliminar definitivamente la revisa antes) |
| `reserva_areas.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `reserva_eventos.reserva_id` | `reservas` | cascade | **restrict** | Detalle de un registro con historia |
| `reserva_eventos.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `reserva_montajes.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `reserva_ordenes.departamento_id` | `departamentos` | cascade | **restrict** | Detalle de un registro con historia |
| `reserva_ordenes.reserva_id` | `reservas` | cascade | **restrict** | Detalle de un registro con historia |
| `reserva_ordenes.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `reserva_servicio_items.reserva_id` | `reservas` | cascade | **restrict** | Detalle de un registro con historia |
| `reserva_servicio_items.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `reserva_servicios.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `reserva_tipos_uso.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `reservas.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `reservas.sede_id` | `sedes` | cascade | **restrict** | Detalle de un registro con historia |
| `reservas.reserva_area_id` | `reserva_areas` | cascade | **restrict** | Detalle de un registro con historia |
| `reservas.espacio_id` | `espacios` | cascade | **restrict** | Detalle de un registro con historia |
| `responsivas.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `robo_detalles.novedad_id` | `novedades` | cascade | **restrict** | Detalle de un registro con historia |
| `robo_detalles.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `rol_permisos.modulo_accion_id` | `modulo_acciones` | cascade | cascade (sin cambio) | Catálogo de la plataforma / permisos |
| `rol_permisos.rol_id` | `roles` | cascade | cascade (sin cambio) | Catálogo de la plataforma / permisos |
| `roles.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `ruta_horarios.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `ruta_horarios.ruta_id` | `rutas` | cascade | cascade (sin cambio) | Configuración propia o tabla puente (Eliminar definitivamente la revisa antes) |
| `ruta_paradas.ruta_horario_id` | `ruta_horarios` | cascade | cascade (sin cambio) | Configuración propia o tabla puente (Eliminar definitivamente la revisa antes) |
| `ruta_paradas.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `rutas.sede_id` | `sedes` | cascade | cascade (sin cambio) | Configuración propia o tabla puente (Eliminar definitivamente la revisa antes) |
| `rutas.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `sede_turno.sede_id` | `sedes` | cascade | cascade (sin cambio) | Configuración propia o tabla puente (Eliminar definitivamente la revisa antes) |
| `sede_turno.turno_id` | `turnos` | cascade | cascade (sin cambio) | Configuración propia o tabla puente (Eliminar definitivamente la revisa antes) |
| `sede_vacante.sede_id` | `sedes` | cascade | cascade (sin cambio) | Configuración propia o tabla puente (Eliminar definitivamente la revisa antes) |
| `sede_vacante.vacante_id` | `vacantes` | cascade | cascade (sin cambio) | Configuración propia o tabla puente (Eliminar definitivamente la revisa antes) |
| `siniestro_danos.novedad_id` | `novedades` | cascade | **restrict** | Detalle de un registro con historia |
| `siniestro_danos.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `siniestro_detalles.novedad_id` | `novedades` | cascade | **restrict** | Detalle de un registro con historia |
| `siniestro_detalles.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `siniestro_equipos.novedad_id` | `novedades` | cascade | **restrict** | Detalle de un registro con historia |
| `siniestro_equipos.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `siniestro_servicios.novedad_id` | `novedades` | cascade | **restrict** | Detalle de un registro con historia |
| `siniestro_servicios.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `solicitudes_arco.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `suscripcion_excepciones.modulo_id` | `modulos` | cascade | cascade (sin cambio) | Catálogo de la plataforma / permisos |
| `suscripcion_excepciones.suscripcion_id` | `suscripciones` | cascade | cascade (sin cambio) | Catálogo de la plataforma / permisos |
| `suscripcion_historial.suscripcion_id` | `suscripciones` | cascade | cascade (sin cambio) | Catálogo de la plataforma / permisos |
| `suscripcion_paquete.paquete_id` | `paquetes` | cascade | cascade (sin cambio) | Catálogo de la plataforma / permisos |
| `suscripcion_paquete.suscripcion_id` | `suscripciones` | cascade | cascade (sin cambio) | Catálogo de la plataforma / permisos |
| `suscripciones.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `tipos_equipo.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `tipos_espacio.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `tipos_gafete.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `turnos.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `usuario_roles.sede_id` | `sedes` | cascade | cascade (sin cambio) | Catálogo de la plataforma / permisos |
| `usuario_roles.rol_id` | `roles` | cascade | cascade (sin cambio) | Catálogo de la plataforma / permisos |
| `usuario_roles.user_id` | `users` | cascade | cascade (sin cambio) | Catálogo de la plataforma / permisos |
| `vacantes.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `valores_vista_aperturas.novedad_id` | `novedades` | cascade | **restrict** | Detalle de un registro con historia |
| `valores_vista_aperturas.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `valores_vista_detalles.novedad_id` | `novedades` | cascade | **restrict** | Detalle de un registro con historia |
| `valores_vista_detalles.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `valores_vista_personas.novedad_id` | `novedades` | cascade | **restrict** | Detalle de un registro con historia |
| `valores_vista_personas.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `valores_vista_zonas.novedad_id` | `novedades` | cascade | **restrict** | Detalle de un registro con historia |
| `valores_vista_zonas.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `vehiculos.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `vouchers_reposicion.empresa_id` | `empresas` | cascade | **restrict** | Historia con valor legal: no se borra con la empresa |
| `zonas_estacionamiento.empresa_id` | `empresas` | cascade | cascade (sin cambio) | Configuración o catálogo de la empresa |
| `movimiento_transporte_pasajeros.empresa_id` (nueva) | `empresas` | — | **restrict** | Columna nueva (punto 2) |


## 2. `empresa_id` en los pasajeros de transporte

`movimiento_transporte_pasajeros` era la única tabla operativa sin empresa. La migración `…000300` agrega `empresa_id`, la llena desde su movimiento (`UPDATE … = (SELECT empresa_id FROM movimientos_transporte …)`), la vuelve obligatoria, con llave `restrict` hacia `empresas` e índice `mov_transporte_pasajero_empresa_idx (empresa_id, movimiento_transporte_id)`.

`MovimientoTransporte::pasajeros()` usa el pivote `App\Models\MovimientoTransportePasajero` (`PerteneceAEmpresa`): al guardar toma la empresa activa o, sin empresa activa (consola), la del movimiento; las consultas directas al pivote llevan el filtro de empresa. `sync()` y `attach()` de la Bitácora de transporte no cambiaron.

## 3. Autoría (`creado_por` / `actualizado_por`)

Revisión caso por caso de la lista del análisis:

| Tabla | Decisión |
|---|---|
| `lost_found_articulos`, `lost_found_entregas`, `lost_found_reportes_perdida`, `lost_found_umbrales` | Ya tenían las columnas y `RegistraAutor`. Sin cambio. |
| `accidente_colaboradores`, `accidente_dictamenes`, `accidente_guardavidas`, `accidente_huespedes`, `accidente_incapacidades` | Ya tenían las columnas y `RegistraAutor`. Sin cambio (formularios de Accidentes intactos). `accidente_firmas` excluida por indicación; también las tiene. |
| `procedimiento_pasos` | **Se agregan** (migración `…000400`) con `RegistraAutor` en `ProcedimientoPaso`: es texto que se edita. Las filas existentes quedan vacías. |
| `empresa_modulos` | **Se agregan** (sin modelo propio): se llenan con el usuario en sesión en `EmpresaController::modulos` (alta o cambio), `NombresMenu` (nombre en el menú), `AjustesNovedades`, `ModulosEfectivos` (suscripciones; vacío si lo hace la tarea programada) y `ProvisionarEmpresa`. |
| `procedimiento_acuses` | Bitácora de solo agregar con su autor (`user_id`, `nombre`, firma, IP). Se queda así. |
| `procedimiento_eventos`, `candidato_eventos` | Historial de solo agregar con `user_id` (y `usuario_nombre`). Se quedan así. |
| `notificaciones` | Mensajes que genera la plataforma; `user_id` es el destinatario y la acción que la originó ya está en la auditoría. Se queda así. |

Las columnas nuevas son `foreignId()->nullable()->constrained('users')->nullOnDelete()`, como en las demás tablas con autoría.

## 4. Un solo valor verdad

| Dato repetido | Fuente | Dónde se calcula |
|---|---|---|
| `rutas.hora_inicio`, `hora_fin`, `dias` | `ruta_horarios` | **Solo** `Ruta::resumenDeHorarios()` (el horario más temprano y la unión de días). `AdministradorRutas::guardar()` lo usa y al final confirma con `Ruta::recalcularResumen()` contra los horarios ya guardados. |
| `candidatos.etapa` (y el resto de `Postulaciones::ESPEJO`) | postulación activa (mayor `id`) | `Postulaciones::reflejar()` (ver `candidatos.md`). |
| `movimiento_transporte_pasajeros.empresa_id` | `movimientos_transporte.empresa_id` | Pivote `MovimientoTransportePasajero`. |

**`php artisan plataforma:revisar-coherencia`** revisa todas las empresas:

- sin opciones: **solo lectura**; lista cada diferencia («Ruta #12 «RUTA 1» (empresa 3): resumen 05:00–06:40 LU, sus horarios dicen …», «Candidato #40 … etapa «registrado», su postulación activa #77 dice «con_rh»») y sale con **1** si hay;
- `--reparar`: corrige desde la fuente (rutas con el resumen calculado, candidatos con `reflejar()`, pasajeros con la empresa del movimiento) y deja una fila por ruta o candidato en la Bitácora de auditoría (`plataforma.coherencia_reparada`, con antes y después);
- `--si-toca`: una vez al día después de las **4:00** (hora de la plataforma, después del respaldo de las 3:00); anota la corrida en `configuracion_plataforma` (`coherencia_revision`).
- También informa (sin reparar) los **valores fuera de catálogo** de los CHECK (punto 5): no hay un valor correcto que se pueda deducir.

En el hospedaje no hay SSH, así que el cron de `desplegar.sh` la corre **en proceso** con `--si-toca --reparar` (como las demás tareas); si falla, el archivo de estado dice «ADVERTENCIA: no se pudo revisar la coherencia de la base».

## 5. CHECK en estados, etapas y categorías

La base rechaza un valor que no esté en las constantes de los modelos (`CatalogoChecks::definiciones()`):

| Tabla.columna | Valores (constante) |
|---|---|
| `accesos.estado` | `Acceso::ESTADOS` |
| `autorizaciones.estado` | `Autorizacion::ESTADOS` |
| `candidatos.etapa`, `postulaciones.etapa` | `Candidato::ETAPAS` |
| `lost_found_articulos.estatus` | `LostFoundArticulo::ESTATUS` |
| `movimientos_transporte.tipo_movimiento`, `.estatus` | `MovimientoTransporte::TIPOS`, `::ESTATUS` |
| `novedades.categoria`, `.estatus` | `Novedad::CATEGORIAS`, `::ESTATUS` |
| `pases_salida.estado` | `PaseSalida::ESTADOS` |
| `prestamos_llaves.estado` | `PrestamoLlave::EN_USO`, `DEVUELTA` |
| `recorridos_pc.estatus` | `RecorridoPc::ESTATUS` |
| `reservas.estado` | `Reserva::ESTADOS` |
| `responsivas.estado` | `Responsiva::EN_CAMPO`, `DEVUELTA` |
| `solicitudes_arco.estado` | `SolicitudArco::ESTADOS` |

Nombre: `chk_<tabla>_<columna>`. `NULL` se permite (el CHECK no lo rechaza; las columnas ya son obligatorias donde aplica).

**Antes de agregar** (migración `…000500`): `CatalogoChecks::normalizar()` pasa a su valor vigente lo escrito distinto — sinónimos conocidos (`Candidato::ETAPAS_ANTERIORES`, `Novedad::CATEGORIAS_SEGCAT` / `ESTATUS_SEGCAT`, `RecorridoPc::ESTATUS_SEGCAT`) y diferencias de mayúsculas, espacios o acentos — y no toca lo que no tenga equivalente claro. El informe queda en el log y en `configuracion_plataforma` (`restricciones_check`: `normalizados`, `sin_reconocer`).

**Al agregar** (migración `…000600`): solo pone el CHECK de una columna si **todas** sus filas cumplen; si no, lo omite (`restricciones_check.omitidos`), la migración no falla y la revisión de coherencia lo sigue reportando. Corregido el dato, otra migración (o volver a correr la lógica, que es idempotente) lo agrega.

Por motor (`Restricciones::agregarChecks` / `quitarChecks`):

- **Comparación exacta.** Con la intercalación `utf8mb4_unicode_ci` de MariaDB, `'RESUELTO' IN ('resuelto')` es verdadero; por eso el CHECK, la detección y la normalización comparan en binario (`cast(col as binary)`, `Restricciones::exacta()`): un valor en mayúsculas de SEGCAT se normaliza y después no puede volver a entrar (el código lo compara con `===`).
- **MariaDB/MySQL**: un `ALTER TABLE … ADD CONSTRAINT … CHECK (cast(col as binary) IN (…))` por tabla (todas sus columnas juntas). MariaDB revisa las filas existentes y puede copiar la tabla: en las tablas grandes (accesos, novedades) cuenta con unos segundos dentro de la ventana de mantenimiento del despliegue. `down()` hace `DROP CONSTRAINT`.
- **SQLite**: mismo procedimiento de `writable_schema` que las llaves (sin reconstruir). Así **toda la suite de pruebas corre con los CHECK**: si algún código escribiera un estado fuera de las constantes, falla la prueba.

**Agregar un estado nuevo** a una de esas constantes exige una migración que actualice su CHECK:

```php
Restricciones::agregarChecks('reservas', ['estado' => CatalogoChecks::definiciones()['reservas']['estado']]);
```

(`agregarChecks` reemplaza el CHECK si los valores cambiaron.) La prueba `test_los_check_existen_con_los_valores_de_las_constantes` falla mientras la base y las constantes no coincidan.

## Pruebas

`tests/Feature/Nucleo/IntegridadBaseDatosTest.php`: llaves restrict y las que se quedan en cascada; la base no borra una empresa ni una novedad con historia, pero sí sus renglones de detalle; la baja (activo / SoftDeletes) sigue; una empresa vacía se borra en cascada; borrar un candidato se lleva su historia; Eliminar definitivamente un departamento usado en reservas explica por qué no; pasajeros con empresa, filtro de empresa, `sync` al editar y migración que llena la empresa; autoría en módulos de empresa (alta y cambio) y pasos de procedimiento; resumen de rutas; `plataforma:revisar-coherencia` en solo lectura, con `--reparar` y con `--si-toca`; `desplegar.sh`; CHECK con los valores de las constantes, rechazo de valores inválidos, normalización y omisión sin fallar; respaldo → restauración con CHECK y llaves restrict; cada migración se revierte y se vuelve a aplicar con datos.

En la CI: la misma clase corre también contra **MariaDB 11.4** (paso «Integridad de la base en MariaDB»; las partes que revierten migraciones se omiten ahí porque un `ALTER` cierra la transacción de la prueba, y las cubre «migrar → revertir → migrar»), y `.github/scripts/respaldo-mariadb.php` comprueba que la base restaurada tiene los mismos CHECK y llaves restrict y rechaza un estado inválido.
