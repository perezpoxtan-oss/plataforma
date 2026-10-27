# ADR-0013 — Incidentes: avisos de la bitácora, pendiente de turno, objeto encontrado, una sola ficha de robo y firmas de pases que tardan

**Fecha:** 2026-10-11 · **Estado:** aceptada (sigue a [docs/tecnico/novedades.md](../tecnico/novedades.md) y a ADR-0012)

## Contexto

La crítica de uso marcó en la Bitácora de novedades y sus submódulos: «¿A quién se canaliza?» que no avisa, la ubicación capturada dos veces, el nombre «Novedades», «Pendiente de Turno» sin dueño ni hora, Lost & Found con ticket completo para un objeto simple y tickets a medias, Robo con dos expedientes distintos, la categoría «Siniestro Protección Civil» para un extintor y recorridos que no avisan sus hallazgos. En Pases de salida quedó pendiente el criterio 3 (reloj, color y escalamiento de las aprobaciones). Regla del dueño: **lo que una empresa pueda querer distinto se vuelve configurable, pero por omisión se conserva el diseño original**; lo que solo simplifica se aplica directo. Los formularios de Accidentes no se tocan.

## Decisión

1. **Campana para lo que espera a alguien** (criterio 1), en un servicio propio (`AvisosNovedades`): canalizado, resuelto, pendiente de turno y hallazgos de recorrido. Los correos usan avisos nuevos de `Empresa::AVISOS` **apagados por omisión** (ninguna empresa recibe correos nuevos sin pedirlos); la campana avisa siempre, como en Pases (ADR-0012).
2. **El dueño de un «Pendiente de turno» es la persona canalizada.** No se volvió obligatoria (cambiaría lo que hoy se acepta); sin dueño, el aviso va a todo el personal de Seguridad de la sede. La hora sale de los **turnos de la empresa** (Estructura → Turnos): primer turno de la sede que empieza después de marcarlo. Al iniciarlo se avisa; si termina sin atenderse, al jefe (quien puede reabrir casos en la sede). Sin turnos no hay hora límite y el aviso es inmediato; no se inventan horas en el código (criterio 3: los tiempos los configura la empresa).
3. **Objeto encontrado en un paso**: el ticket rápido de Lost & Found pide el objeto de forma opcional y el archivo tiene «Registrar objeto encontrado», que crea el ticket por detrás con el formato de Lost & Found (mismas reglas, mismo folio). Se conservó el ticket (no un artículo suelto) porque el Minuto a Minuto, el acuse, la auditoría y la vinculación con reportes de pérdida y robos cuelgan de él. El ticket nace **Abierto**, como siempre.
4. **Una sola ficha de robo**: la lista de Robo abre el expediente de la bitácora para quien puede editarlo ahí (y regresa a Robo al guardar). El diálogo de Robo se conserva para quien solo tiene ese submódulo, pero con el mismo título, orden y botón. No se quitó la pantalla de Robo: sus filtros son de lo que mejor funciona.
5. **Categoría del hallazgo de recorrido configurable**: categoría nueva `mantenimiento_pc` («Mantenimiento de equipo PC») que solo abre la plataforma; por omisión sigue `proteccion_civil`. Su formato no pide nada: muestra los equipos con falla del recorrido.
6. **Nombre del menú**: se usa el dato que ya existía (`empresa_modulos.nombre_visible`) con una pantalla para cambiarlo. Rutas, permisos y el nombre del módulo en Roles no cambian. Se propone al dueño cambiar el nombre por omisión a «Incidentes» (no se aplicó: es un cambio visible para todas las empresas).
7. **Firmas de pases que tardan**: horas o días por empresa en el Circuito (`preferencias['pases_salida']['espera']`), apagado por omisión. El reloj se muestra siempre (único cambio visible por omisión); color, recordatorio al firmante y aviso al siguiente paso del circuito (o a quien configura los pases si es el último) solo con tiempos. Un aviso por nivel y por paso.
8. Tareas programadas `plataforma:novedades-pendientes` y `plataforma:escalar-pases`, en proceso y con `--si-toca`, agregadas a `desplegar.sh`; la bitácora además revisa los pendientes de turno al pintarse (una vez por minuto por empresa).

## Consecuencias

- Migración `2026_10_24_000100_incidentes_turno_y_espera_de_pases`: solo columnas nuevas y vacías (`novedades.pendiente_turno_en`, `turno_avisado_en`, `turno_escalado_en`; `pases_salida_aprobaciones.recordatorio_en`, `escalado_en`, `escalado_a`); su `down()` las quita.
- Tickets marcados «Pendiente de turno» antes de esta versión no tienen hora: no se avisan solos (se ven sin reloj).
- Eventos de auditoría nuevos: `novedades.configurado`, `novedades.turno_avisado`, `novedades.turno_escalado`, `lost_found.objeto_registrado`, `pases_salida.recordatorio`, `pases_salida.escalada`, `pases_salida.tiempos_espera`.
- Pendiente de decisión del dueño: nombre por omisión «Incidentes»; si «¿A quién se canaliza?» debe ser obligatorio al dejar un ticket pendiente de turno.
