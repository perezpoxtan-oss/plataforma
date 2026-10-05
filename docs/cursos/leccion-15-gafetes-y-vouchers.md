# Lección 15 — Gafetes y vouchers de reposición

**Para:** Jefe de seguridad, Supervisor, Asistente, Administrador y Agentes · **Duración:** 20 minutos

## Lo que vas a aprender

- Generar gafetes por lote y entender su nomenclatura.
- Imprimirlos en "doble vista" para la mica.
- Asignarles una tarjeta NFC / RFID.
- Dar de baja un gafete perdido con su voucher (con o sin cobro) y reactivarlo.
- Consultar y reimprimir vouchers.

## Práctica (en QA)

1. **`admin.demo`:** abre Padrones → **Gafetes**.
   - Toca la píldora **Proveedor** y luego **Todos**. Cambia **Todas las sedes** por **Hotel Demo Playa**.
   - Busca `vis-01`: aparece `HOT-CEN-VIS-010` como **NO DISPONIBLE** (es de los datos demo).
2. Toca **Generar Lote**: sede **Hotel Demo Centro**, tipo **Visitante**, cantidad **3**. Fíjate que se crean `HOT-CEN-VIS-011` a `HOT-CEN-VIS-013` (continúan después del último).
3. Otro lote con **+ Nuevo tipo...** llamado `Capital Humano`, cantidad 2: se crean `HOT-CEN-CAP-001` y `002`, y aparece la píldora **Capital Humano**.
4. Intenta generar **60**: el sistema te dice que el máximo es 50 por lote.
5. Toca **Marcar todos** con la píldora **Capital Humano** activa y luego **Imprimir**: revisa la hoja doble vista y escanea un QR con el celular.
6. Edita `HOT-CEN-VIS-011`:
   - en **Etiqueta NFC / RFID** escribe `04:A2:3B:1C` y guarda;
   - edita `HOT-CEN-VIS-012` y pon la misma etiqueta: el sistema te dice que ya la tiene `HOT-CEN-VIS-011`.
7. En **Hotel Demo Playa**, da de baja `HOT-PLA-VIS-001` con motivo **Robado**, marca **Aplica CXC**, deja el monto sugerido o escribe `150`, y en **Colaborador Responsable** escribe `1004` y presiona Enter. Toca **Generar Voucher y Dar de Baja** e **Imprimir voucher**.
8. Reactiva `HOT-PLA-VIS-001` con la flecha verde.
9. Abre **Vouchers de reposición**: filtra **Solo con cobro**, busca `1004` y reimprime el voucher.
10. **`agente.demo`:** abre Gafetes y Vouchers: solo ves la sede Centro, no puedes generar, editar, dar de baja ni imprimir.
11. **`jefe.demo`:** abre los dos módulos y compara qué puede hacer.

## Para recordar

- La nomenclatura se arma sola (**EMPRESA-SEDE-TIPO-número**) y no se repite dentro de la empresa.
- El QR del gafete se genera dentro de la plataforma y solo lleva un código: no expone datos y no depende de internet.
- Un gafete perdido **no se borra**: se da de baja con voucher. Si aparece, se reactiva y el voucher se conserva.
- El monto sugerido es el último que se cobró por ese tipo de gafete; se puede cambiar.
- Los vouchers no se editan: son el comprobante de lo que pasó.

## Autoevaluación

1. ¿Qué nomenclatura tendrá el siguiente Visitante de la sede Centro si el último es `HOT-CEN-VIS-013`? *(HOT-CEN-VIS-014)*
2. ¿Cuántos gafetes puedo generar en un lote? *(De 1 a 50)*
3. ¿Qué necesito capturar si marco "Aplica CXC"? *(El monto y el colaborador responsable)*
4. Si reactivo un gafete, ¿se borra su voucher? *(No)*
5. ¿Por qué un Agente no ve los vouchers de llaves? *(Porque solo ve los de los módulos que puede consultar y de su sede)*
