# Lección 16 — Equipos de seguridad y estacionamientos

**Para:** Jefe de seguridad, Asistente, Administrador y Agentes · **Duración:** 20 minutos

## Práctica (en QA)

### Equipos de seguridad

1. **`admin.demo`:** abre Padrones → Equipos de seguridad.
   - Toca la píldora **En Mantenimiento**: aparece el radio `130TXP1568`. Vuelve a **Cualquier estado**.
   - Busca `dm-11`: aparecen los dos detectores de metales.
2. Registra un equipo: sede **Hotel Demo Centro**, tipo **+ Nuevo tipo...** = `Fornitura`, marca `Truper`, serie `fn-001`. Revisa que se guardó como `FN-001` y DISPONIBLE.
3. Registra otro radio con marca `MOTOROLA` y modelo `DEP 450`: el **costo** aparece solo (4800).
4. Intenta registrar otra vez la serie `752TSFQ504`: el sistema te avisa que ya existe.
5. Edita `FN-001` y ponlo **EN MANTENIMIENTO**.
6. Toca el **QR** de una ficha y luego la **impresora**: escanea la etiqueta con el celular, abre la ficha.
7. Da de baja `CH-001` como **Dañado**, con **Aplica CXC**, monto 350 y responsable `1003`. Fíjate en el folio del voucher en el aviso.
8. Reactiva `CH-001`.
9. **`agente.demo`:** abre Equipos de seguridad: consulta y ve el QR, pero no registra, no edita ni da de baja.

### Estacionamientos

10. **`admin.demo`:** abre Padrones → Estacionamientos. Revisa los cupos de cada sede.
11. Crea en **Hotel Demo Playa** el estacionamiento `Valet` con cupo 12; luego una **Zona de Descarga** `Cocina` (el cupo desaparece).
12. Intenta crear `valet` otra vez en la misma sede: no se permite.
13. Reactiva **Estacionamiento Temporal (obra)** y vuelve a desactivarlo.

## Para recordar

- El número de serie no se repite **dentro de tu empresa** y se guarda en mayúsculas.
- ASIGNADO lo pondrá Responsivas; BAJA/PERDIDO solo con el botón de baja, que genera el voucher.
- Reactivar no borra el voucher: queda como historial.
- El QR y la etiqueta se generan dentro de la plataforma: no llevan datos personales y no dependen de internet.
- La ocupación de los estacionamientos la llenará la Bitácora de accesos; por ahora se ve en 0.

## Autoevaluación

1. ¿Puedo poner un equipo en ASIGNADO desde Editar? *(No: lo hace Responsivas)*
2. ¿Qué pasa con el voucher si reactivo el equipo? *(Se conserva)*
3. ¿Una zona de descarga lleva cupo? *(No)*
4. ¿Un Jefe de seguridad de Centro ve los equipos de Playa? *(No, solo los de su sede)*
5. ¿Qué hace un Agente en estos módulos? *(Consultar y ver el QR)*
