# Lección 22 — Bitácora de transporte

**Para:** Agentes de caseta, Supervisores, Jefe de seguridad, Director y Administrador · **Duración:** 25 minutos

## Qué vas a aprender

- Registrar una llegada y una salida del transporte de personal en segundos.
- Registrar taxis cuando la unidad no llega: monto, destino, pasajeros con el lector y firmas.
- Imprimir el vale de caja chica, dar el Vo.Bo., corregir, anular y reactivar.
- Consultar reportes y exportar.

## Práctica (en QA)

1. **`agente.demo`** (sede Centro): abre Operación → Bitácora de transporte. Toca **Últimos 7 días** y busca la tarjeta roja con **Pago: $320.00** (tiene justificación).
2. Toca **Registrar Movimiento**. Revisa que la sede (Hotel Demo Centro) y la ruta ya vienen elegidas. Elige *A Tiempo*, escribe las placas `TKH-101` (se llenan marca y modelo en *Más detalles*) y 35 pasajeros. Toca **Registrar y capturar siguiente**.
3. En la ventana que se abre otra vez elige **SALIDA**, *Con Retraso*, y guarda con **Guardar Registro Operativo**.
4. Ahora la unidad no llegó: **Registrar Movimiento** → **FALLA DE FLETERA (Uso de Taxis)**.
   - Taxi 1: placas `TX-5050`, conductor `Luis May`, monto `300`, destino `CHEDRAUI PORTILLO`. Fíjate en el aviso del tope ($250): escribe una justificación.
   - Pasajeros: escribe `1005` y Enter, luego `1009` y Enter.
   - Toca **Añadir otro Taxi** y llénalo con monto `120`, destino `Mi colonia nueva` y el pasajero `1007`.
   - Intenta guardar sin firmas: el sistema marca los recuadros. Firma los dos taxis y el guardia, y guarda.
5. Imprime los dos vales con los botones del aviso verde y revisa las 3 copias.
6. Abre el detalle (ojo) de uno de tus taxis y **edítalo**: cambia el monto a `200` y quita un pasajero.
7. **`director.demo`**: abre la bitácora, filtra *Vales sin Vo.Bo.* y **autoriza** uno. Intenta editarlo: ya no se puede.
8. **`admin.demo`**: **anula** un registro de llegada y luego **reactívalo**. Abre **Reportes Avanzados**, filtra por *Uso de Taxis* y **exporta**.
9. **`admin.demo`**: en Configuración → Avisos por correo, escribe `finanzas@demo.local` en los correos del vale de taxi y guarda.
10. **`agente2.demo`** (Playa): solo ve los movimientos de Hotel Demo Playa.

## Para recordar

- La ruta y el tipo se proponen solos según la hora: revísalos, no los busques.
- Un taxi = un vale. Cada taxi necesita placas, conductor, monto, destino y al menos un pasajero.
- Si el monto pasa del tope de la ruta, se justifica por escrito.
- Anular no borra; un vale con Vo.Bo. ya no se edita.

## Autoevaluación

1. ¿Qué pasa si escribo un destino que no está en la lista? *(Se agrega al catálogo de paraderos de la sede)*
2. ¿Puedo poner al mismo colaborador en dos taxis? *(No)*
3. ¿Quién da el Vo.Bo. de un vale? *(Quien tiene el permiso aprobar, por ejemplo el Director)*
4. Me equivoqué de ruta en un registro, ¿lo edito? *(No: se anula y se registra de nuevo)*
5. ¿Los registros anulados cuentan en el gasto de taxis? *(No)*
