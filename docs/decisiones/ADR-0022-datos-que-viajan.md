# ADR-0022 — Datos que viajan entre módulos

**Fecha:** 2026-10-27 · **Estado:** aceptada · **Detalle técnico:** [docs/tecnico/datos-que-viajan.md](../tecnico/datos-que-viajan.md)

## Contexto

Cada módulo guardaba bien su dato, pero el dato no llegaba a donde se necesita: la Ficha de Hechos de un robo no sabía quién tuvo la llave (D-09), la caseta no sabía que hoy llegan cien personas a un evento (D-12, X-05), un extintor sin presión contaba como siniestro (D-06), los procedimientos no se ligaban a nada (D-20), en condominio las categorías eran de hotel (D-17), reclasificar un ticket dejaba objetos colgando (D-16), el formato de Accidente pedía de nuevo fecha, hora, habitación y turno (D-14) y la caseta no tenía dónde anotar la herramienta del proveedor (B-07). Decisiones del dueño: DD-16 (sí a sugerencias y turno en el servidor; **el formulario de Accidentes no se toca** y la terminología no cambia), DD-17 (el hallazgo abre «Mantenimiento de equipo PC») y DD-18 (lo del proveedor, como lista corta opcional).

## Decisión

1. **Fuentes enchufables de la Ficha de Hechos** (`FuenteFichaHechos`): Préstamo de llaves y Accesos se registran con una etiqueta del contenedor y respetan el permiso «ver» de su módulo. Solo se usa el lugar de catálogo (C-30): no se adivina con texto libre.
2. **Un servicio «Eventos del día»** (`EventosDelDia`) que leen la caseta, la Bitácora del día y el Informe ejecutivo. La caseta lo ve con su propio permiso de Accesos (solo área, horario, título y personas; nada del cliente) cuando la empresa tiene Reservas.
3. **Vínculos sin tablas nuevas** donde basta una preferencia: procedimiento por categoría y tipo de equipo, y tipos de Reporte General (de fábrica por giro), en `empresas.preferencias`.
4. **Accidentes por fuera de su formato:** las sugerencias salen de una subclase (`AccidenteConSugerencias`) que solo llena valores iniciales de un caso nuevo; el turno se completa después de guardar (`TurnoDelAccidente`). `Formatos/Accidente.php`, su vista y su validación no cambian. La pestaña de la ficha del colaborador tiene permiso propio (`colaboradores.accidentes`), vendido con Incidentes.
5. **Lo del proveedor en su propia tabla** (`accesos_articulos`) y la comparación al salir dentro del diálogo de salida que ya existía (C-15). La diferencia se canaliza al supervisor con el motor de relojes (ArticulosPorRevisar).

## Consecuencias

- Una empresa que nunca eligió la categoría del hallazgo pasa a «Mantenimiento de equipo PC» (DD-17). Quien quiera «Siniestro» lo elige en Configuración.
- La sugerencia de turno no se puede mostrar en el formulario (requeriría cambiar su vista): se toma en el servidor al guardar si el campo quedó vacío.
- Accidente no se ofrece en «¿Qué hacer?» porque el enlace aparecería dentro de su ventana.
- La foto de lo que trae el proveedor no se implementó (ver datos-que-viajan.md).
