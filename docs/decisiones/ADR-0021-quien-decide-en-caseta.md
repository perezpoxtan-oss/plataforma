# ADR-0021 — Quién decide en la caseta y los giros residenciales

**Fecha:** 2026-10-27 · **Estado:** aceptada · **Detalle técnico:** [docs/tecnico/quien-decide-en-caseta.md](../tecnico/quien-decide-en-caseta.md)

## Contexto

La caseta preguntaba dos veces a quién venía la persona (host y departamento) y la autorización le llegaba al departamento, no a quien la esperaba (P-05, B-06). En condominio, «Residentes» era la lista de empleados con otro nombre y el residente no tenía vivienda (P-02, A-05, GC-11). Los lugares se escribían a mano (P-06, B-08, D-15). Un incidente solo se canalizaba a Seguridad (D-03) y las reservas en condominio no tenían residente (D-10, D-11). Decisiones del dueño: DD-12 (autoriza la persona citada, su departamento es suplente; en condominio el residente; en hotel, visita a huésped con llamada), DD-14 (tipo + vivienda ahora; portal después) y DD-15 (sí, viendo solo sus tickets).

## Decisión

1. **El residente es un tipo de persona del directorio** (`colaboradores.tipo` y `espacio_id`), con su pantalla en Padrones y alta corta. El personal conserva su formulario; «Colaborador» se dice «Personal» en residenciales y el nivel del árbol «Vivienda» en condominio.
2. **Una sola búsqueda de lugares** (`Lugares`) para caseta, incidentes, residentes y reservas; lo que no está en el catálogo se queda como texto «sin catálogo».
3. **Un solo campo «¿A quién viene?»** que se traduce a los campos de siempre. La persona con cuenta autoriza (regla de pertenencia, sin permiso nuevo); sin cuenta o área, el departamento como antes; residente, vivienda o huésped, la caseta llama y deja constancia con el botón principal («Llamé y autorizó») o «No autorizó». No agrega campos.
4. **Canalizar a un departamento** con la cobertura de la Etapa 3 y un reloj del motor de la Etapa 4; el departamento ve solo sus tickets en una pantalla propia.
5. **Reservas por vivienda** en residenciales; departamentos activos en reservas y aviso cuando una orden no tiene quien la atienda.

## Consecuencias

- Los formularios de antes y la app siguen funcionando (los campos de siempre se aceptan).
- Sin `llamada` en la petición, una visita al residente se registra como antes (sin constancia).
- El residente no tiene cuenta todavía (portal en una etapa posterior).
- «Personal» como palabra del giro necesita reglas de artículo propias; las frases que no encajan se agregan a `Terminos`.
- Verbos: «Atender» y «Rechazar» ya existían; «Llamé y autorizó» y «No autorizó» son textos de constancia (anotados para el glosario).
