# Lección 18 — Bitácora de accesos

**Para:** Agentes de caseta, Supervisor, Jefe de seguridad y Administrador · **Duración:** 30 minutos

## Qué vas a aprender

- Leer las pestañas Pendientes de Autorización, Gente en Sitio e Historial Finalizados.
- Registrar el ingreso de cada tipo de persona con el lector universal y lo mínimo de escritura.
- Autorizar proveedores y contratistas.
- Dar salida (de la tarjeta o escaneando el gafete), cambiar zona, salida a tour y regreso.
- Buscar en el historial y exportarlo a Excel.

## Práctica (en QA)

1. **`admin.demo`:** abre Operación → **Accesos**.
   - En **Gente en Sitio** encuentra a **AYLEEN PÉREZ** (Hab. 310): está **FUERA EN TOUR** desde las 10:30.
   - En **Pendientes de Autorización** hay 2: **JESÚS BALAM TUN** (Abarrotes del Caribe) y **PATRICIA GÓMEZ SOSA** (Fumigaciones Peninsulares).
2. Toca **Nuevo Ingreso**, sede **Hotel Demo Centro**, tipo **Colaborador**. Escribe `Mariana` y elígela de la lista. Toca **Guardar y capturar siguiente**: el formulario se vuelve a abrir.
3. Ahora tipo **Personal Externo / Visita**:
   - Tipo de Gafete **Visitante**; en Gafete Asignado escribe `VIS` y fíjate que **no aparecen** HOT-CEN-VIS-001, 002 ni 003 (están prestados). Elige el 005.
   - Nombre `Elena Prueba Caseta`, ID **INE**, motivo **Visita a Colaborador**, a quién visita: **Carlos Pérez Gómez**.
   - Forma de llegada **En Vehículo**, placas `QA-123-B`, **Enviar a** Estacionamiento Huéspedes.
   - Agrega un acompañante `NIÑO PRUEBA` con el gafete HOT-CEN-VIS-001: el sistema avisa dentro de la ventana que ese gafete no está disponible. Cámbialo por el 006 y guarda.
4. Abre Padrones → Vehículos y busca `QA123B`: el vehículo se registró solo.
5. Nuevo ingreso tipo **Proveedor** sin elegir Host: el aviso dice que el Host es obligatorio. Elige uno y guarda: queda en **Pendientes**.
6. En **Pendientes**, toca **Confirmar Autorización** en JESÚS BALAM TUN: pasa a Gente en Sitio.
7. En la tarjeta de **LAURA MÉNDEZ RÍOS**, da **Salida** solo a su acompañante SOFÍA MÉNDEZ; luego **Registrar Salida** a Laura. Revisa que el gafete HOT-CEN-VIS-001 ya se puede elegir otra vez.
8. En **AYLEEN PÉREZ** toca **Registrar Regreso**: vuelve a **EN SITIO**.
9. En **LETICIA VÁZQUEZ** toca **Cambiar Zona** y elige otra zona; después **Salida a Tour** y **Registrar Regreso**.
10. Toca **Nuevo Ingreso → Dar Salida** y escribe `QRR`: aparece **RICARDO ORTEGA VELA** con lo que hay que pedirle de vuelta. Dale salida.
11. En **Historial Finalizados** filtra por ayer: aparecen la emergencia **CRUZ ROJA UNIDAD 12** y la huésped **PAMELA ALCOCER**. Toca **Exportar a Excel**.
12. Abre **Estacionamientos**: la ocupación de cada zona coincide con los vehículos en sitio.
13. **`agente.demo`:** ve solo **Hotel Demo Centro**, puede registrar ingresos y salidas, pero en Pendientes **no** tiene «Confirmar Autorización» ni el botón de exportar.
14. **`agente2.demo`** (Playa): solo ve la gente de **Hotel Demo Playa** (DANIELA CANUL MAY y JOSÉ LUIS EK CAUICH).

## Para recordar

- **Colaborador, Huésped y Emergencia nunca llevan gafete.** Visitas, proveedores y contratistas sí (o «S/G»).
- Un gafete está ocupado mientras su dueño siga dentro o pendiente, aunque haya salido un rato.
- Proveedor y contratista **siempre** necesitan Host y entran como **Pendientes**.
- Una empresa vetada (dada de baja en Proveedores) no puede entrar.
- Al dar salida al titular salen también sus acompañantes y se libera su lugar de estacionamiento.

## Autoevaluación

1. ¿Puedo prestarle a una visita el gafete de un proveedor que espera autorización? *(No: está ocupado)*
2. ¿Dónde veo a un proveedor recién registrado? *(En Pendientes de Autorización)*
3. Un huésped con reserva, ¿qué tipo de pase tiene? *(Siempre Estancia)*
4. ¿Cómo doy salida si solo tengo el gafete en la mano? *(Dar Salida → escanear el gafete)*
5. ¿Puede un Agente autorizar a un proveedor o exportar el historial? *(No)*
