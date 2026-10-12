# ADR-0012 — Pases de salida: firmas de caseta configurables, circuito por valor, rechazo configurable, campana y «Llegan a mi sede»

**Fecha:** 2026-10-11 · **Estado:** aceptada (sigue al circuito v2 de [docs/tecnico/pases-salida.md](../tecnico/pases-salida.md))

## Contexto

La crítica de uso marcó cuatro puntos [Mejorar] en Pases de salida: «hasta ocho firmas» por pase (dos en cada movimiento de caseta más las aprobaciones), «El rechazo reinicia desde el paso 1», «Los avisos de pases son solo por correo» y los nombres «Confirmar Llegada» / «Autorizar Salida de Regreso», que el agente de la sede destino no entiende. El dueño decidió: **«Para el diseño original es tal cual se plasmó, pero puede variar entre otras empresas.»** Por omisión todo se comporta exactamente como hoy y cada empresa lo ajusta.

## Decisión

1. **Configuración en `empresas.preferencias['pases_salida']`** (sin tabla nueva, como los plazos de Candidatos, ADR-0011): `firmas_caseta` y `rechazo`. Solo se guarda lo distinto de la omisión; sin la clave, el comportamiento es el original. Se edita en la misma pantalla y con el mismo botón que el circuito (**Guardar circuito**); **Restablecer** borra pasos y ajustes.
2. **Firmas en caseta por movimiento, con excepciones por motivo** (tres opciones: dos firmas · solo Seguridad · sin firma). Se eligió «todos los motivos + excepciones» en vez de una tabla completa porque lo común es una sola regla por movimiento (criterio 7: la tabla de excepciones va plegada en «Avanzado»). La verificación de artículos no se puede quitar.
3. **La configuración se copia al pase** (`pases_salida.firmas_caseta`) al crearlo y al reenviarlo (antes de llegar a caseta). Un pase en curso no cambia si la empresa cambia su configuración; los pases anteriores (`null`) piden dos firmas.
4. **Condición de valor por paso** (`valor_minimo` ≥, `valor_maximo` <) sobre el **valor total del pase** = Σ cantidad × valor por artículo. El valor de un equipo del padrón es su `costo` (manda sobre lo capturado); si no, el solicitante lo captura (opcional). **Sin valor conocido, el paso aplica** (mejor una firma de más que dejar salir equipo caro sin autorización). Cada motivo conserva al menos un paso obligatorio sin condición, para que ningún pase quede sin aprobaciones. El campo de valor solo aparece en el formulario si algún paso lo usa (el formulario de siempre no cambia).
5. **Rechazo configurable** con omisión **«Empezar desde el primer paso»** (lo original). Con «Retomar», al reenviar se recalculan los pasos con el motivo y el valor corregidos; los que siguen aplicando y ya estaban aprobados u omitidos conservan su firma (misma `firma_id`, `conservada_de_ronda`); el que rechazó y los que ahora aplican sin firma se piden. Los pasos se reconocen **por nombre** entre rondas, porque guardar el circuito recrea sus ids. La regla vigente es la del momento del reenvío.
6. **Campana** (criterio 1) además del correo, en un servicio propio (`CampanaPasesSalida`): «Te toca firmar» (botón **Abrir pase**: aprobar pide firma, se hace en la ficha; se agregó a `CentroNotificaciones` el botón `abrir`), aprobado / rechazado (motivo), vencido diario y «Un pase viene a tu sede» al salir. Cada aviso se resuelve cuando deja de aplicar (firmó otro, se canceló, llegó, regresó). El recordatorio de vencidos llega a la campana aunque el correo esté apagado.
7. **«Llegan a mi sede»** es un filtro más de la lista (no una pantalla nueva), visible solo a quien tiene «Firmar», limitado a las sedes donde lo tiene.
8. Botones de caseta renombrados **solo en texto** (excepción a la regla 4 de criterios-ux aprobada por el dueño).

## Consecuencias

- Migración `2026_10_23_000100_pases_salida_firmas_caseta_valor_y_rechazo`: solo agrega columnas sin índices; su `down()` las quita. Un revert del código deja las columnas sin usarse y la clave `pases_salida` en las preferencias (la versión anterior la ignora): los pases con «Solo Seguridad» o «Sin firma» registrados mientras tanto conservan su bitácora, y la versión anterior volvería a pedir dos firmas en sus movimientos pendientes.
- Evento de auditoría nuevo `pases_salida.ajustes_actualizados`; `circuito_restablecido` incluye los ajustes.
- El recordatorio de vencidos devuelve «pases recordados» (antes «correos enviados»).
- Pendiente: reloj, color y escalamiento (criterio 3) para las aprobaciones de pases.
