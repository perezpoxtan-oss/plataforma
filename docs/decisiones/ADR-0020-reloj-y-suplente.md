# ADR-0020 — Un solo motor «Reloj y suplente»

**Fecha:** 2026-10-27 · **Estado:** aceptada · **Detalle técnico:** [docs/tecnico/relojes.md](../tecnico/relojes.md)

## Contexto

El inventario de «lo que espera» (grupo E, §2) encontró 26 asuntos que esperan una respuesta. Unos tenían reloj, color y suplente (autorizaciones de la caseta, reservas, ARCO); otros solo un aviso en la campana que además se podía silenciar, y algunos ni eso (la caseta pedía por teléfono, el Super Administrador veía «Estás al día»). Hallazgos B-05, B-11, GC-09, D-04, D-05, D-08, E-05 a E-08 y E-17. Decisiones del dueño DD-10 (24 h / 72 h, llaves 12 h / 24 h encendido, no se silencia lo que no está en Mis pendientes), DD-13 (el agente aprueba por excepción si la empresa lo enciende) y DD-17 (operación programada).

## Decisión

1. **Un motor** (`MotorRelojes`) con un reloj por asunto (`Reloj`). Cada reloj dice qué espera, a quién se le recuerda y a quién pasa; el motor pone los umbrales, el color, la memoria y la campana. Los motores que ya existían se conservan y se encadenan desde el motor (no se reescriben).
2. **En proceso**: al consultar la campana (candado de un minuto por empresa) y en la corrida del despliegue que ya existía. Sin cron nuevo ni `exec`.
3. **Una sola vez por nivel** con actualización condicionada sobre `relojes_esperas`. El día que arranca, lo viejo cuenta desde el arranque (sin avalancha).
4. **Valores de fábrica** de DD-10 en «Reloj y suplente» (las horas del antiguo «Reloj del visto bueno»); por módulo, en Avanzado: horas, rol al que pasa y apagado. Las firmas de pases sin horas propias usan las generales.
5. **Regla de silencio:** un aviso que espera una acción y no tiene renglón en Mis pendientes no se puede silenciar; el aviso al suplente nunca.
6. **Renglones nuevos** en Mis pendientes e Inicio para lo que solo vivía en la campana (vacantes, pases vencidos, Lost & Found, Super Administrador, suscripción, excepciones de la caseta, permanencia, vouchers, equipos en mantenimiento, faltantes, hallazgos).
7. **Caseta de noche:** «Pedir al supervisor» en la tarjeta pendiente; aprobación del agente solo si la empresa la enciende y nadie respondió, con motivo y foto, aviso inmediato y revisión.

## Consecuencias

- Más avisos: mitigado con valores moderados, una sola vez por nivel, el arranque sin avalancha y el interruptor general.
- Mis pendientes hace una consulta más por reloj que aplica a la persona (memorizada por petición).
- Verbos: «Pedir al supervisor» es el texto del plan (C-25); el vale de taxi pasa de «Autorizar (Vo.Bo.)» a **Aprobar (Vo.Bo.)** y **Rechazar** (D-08). «Atender» para revisar la excepción.
- La operación programada de DD-17 (rutas sin registro, órdenes antes del montaje, recargas y frecuencia de revisión) va en un PR encadenado sobre este motor.
