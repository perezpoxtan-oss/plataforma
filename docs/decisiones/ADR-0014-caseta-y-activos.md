# ADR-0014 — Caseta y activos: firmas de vales, Vo.Bo. y procedimientos en la campana, llaves y equipos de turno con reloj, un solo «Recibir» y acción probable al escanear

**Fecha:** 2026-10-11 · **Estado:** aceptada

## Contexto

La «Crítica de uso» marcó quince puntos [Mejorar] en Bitácora de transporte, Préstamo de llaves, Procedimientos, Responsivas y el Lector universal: jerga («FALLA DE FLETERA»), firmas duplicadas en los vales de taxi, Vo.Bo. y «Enviar a revisión» que no avisan a nadie, publicación solo por correo, recibir una llave obliga a buscar su ficha, folio de garantía a mano, llaves y equipos de turno que nadie vigila, dos formas de recibir equipo, tres sinónimos para lo mismo y un escaneo fuera de formulario que solo abre la ficha. Regla del dueño: **lo que una empresa pueda querer distinto se vuelve configurable, pero por omisión se conserva el diseño original**; los textos y atajos que solo simplifican se aplican directo.

## Decisión

1. **Ajustes por empresa en `empresas.preferencias['caseta']`** (sin tabla nueva, como Pases de salida y Candidatos), editables en **Configuración → Caseta y activos** por quien edita Configuración en toda la empresa. Solo se guarda lo distinto de la omisión: firmas del vale (`ambas`), semáforo de llaves (apagado), hora de regreso de equipos «Turno» (apagada) y acuse de procedimientos (`firma`).
2. **Directo (simplifican, no cambian reglas):** «LA UNIDAD NO LLEGÓ (Se usan taxis)»; «responsiva» como único término; recibir una llave escaneándola desde la pantalla principal; folio de garantía lleno con el número del gafete escaneado; un solo «Recibir» con casillas (todas marcadas: «todo» y «4 de 5» son el mismo gesto; daños y faltantes siguen en «Dañado o falta», el flujo de Ronda 8); acción probable al escanear un QR fuera de un formulario.
3. **Avisos (criterio 1), sin configuración porque solo informan:** Vo.Bo. de vales y procedimientos en revisión llegan a la campana y a Mis pendientes de quien puede resolverlos y se resuelven solos; el autor de un procedimiento se entera del resultado; la publicación también llega a la campana. El correo nuevo de «Enviar a revisión» es un aviso de empresa más (encendido por omisión como los demás de Procedimientos; se apaga en Avisos por correo).
4. **Reloj, color y escalamiento (criterio 3)** para llaves fuera y equipos de turno, con horas por empresa (no en código) y apagados por omisión: ámbar avisa a la caseta, rojo al jefe (quien anula préstamos o da de baja equipos). Un comando en proceso (`plataforma:activos-pendientes --si-toca`, desde desplegar.sh) avisa una vez por nivel.
5. **Acuse con casilla:** `procedimiento_acuses.firma_ruta` admite nulo (única migración, `2026_10_25_000100`). «Casilla salvo puntos críticos» usa el dato que ya existe (paso marcado como Punto crítico) en lugar de una bandera nueva.
6. **Lector:** el QR de una llave prestada ofrece «Recibir», el de una llave en caseta «Prestar», el de un equipo en responsiva «Recibir (OK)». El gafete en uso ya iba directo a «Dar salida» y no cambia (un paso más sería peor). Sin acción probable o sin permiso, se abre la ficha como antes. El NFC en iPhone no es posible desde el navegador: se documenta la recomendación de compra en el manual.

## Consecuencias

- Una empresa existente no ve cambios de comportamiento salvo: los textos nuevos, el campo para recibir escaneando, el «Recibir» con casillas, los avisos de campana/Mis pendientes y el correo de revisión de procedimientos.
- Revertir el código deja la clave `caseta` en las preferencias (la versión anterior la ignora) y los avisos creados en la campana. Los acuses de «solo casilla» quedan con `firma_ruta` nulo; el `down()` los pone en cadena vacía para volver a la columna obligatoria (se ven sin firma).
- Eventos de auditoría: `configuracion.caseta` (nuevo); `procedimientos.acuse_firmado` agrega `con_firma`; recibir en OK parte de una responsiva audita `responsivas.equipo_recibido`.
- Pendiente para otra entrega: reloj y color en la lista de vales por autorizar y en procedimientos en revisión (hoy solo avisan), y recordatorio periódico del Vo.Bo.
