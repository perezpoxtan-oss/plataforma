# Lección 14 — Catálogo de llaves y etiquetas de llaveros

**Para:** Jefe de seguridad, Supervisor, Asistente, Administrador y Agentes · **Duración:** 20 minutos

## Antes de empezar

- El catálogo dice **qué llaves existen y qué abren**. El préstamo del día a día va en **Préstamo de llaves**.
- Los lugares (Torre A, Piso 1, 101, Vista al mar) vienen de **Zonas y áreas** (lección 4). Si un lugar no existe allí, usa el alcance **Otra**.

## Práctica (en QA)

1. **`admin.demo`:** abre Padrones → Catálogo de llaves.
   - En **Caducidad** elige **Vencidas**: aparece `HDC-101`. Luego **Vencen pronto**: aparece `HDC-P1-AMA`.
   - Busca `vista`: aparece `HDC-VISTA-MAR` con dos horarios (el nocturno termina al día siguiente).
2. Registra una llave en **Hotel Demo Centro**:
   - Nombre `hdc-p2-ama` (se guarda como `HDC-P2-AMA`), descripción "Habitaciones del Piso 2".
   - Tipo **Electrónica (Magnética/RFID)**, alcance **Piso** y marca **Piso 2 (Torre A)**.
   - ID externo `s-5001`, plataforma Salto, horario "Turno Limpieza" de 08:00 a 16:00.
3. Intenta registrar otra con el nombre `HDC-101`: el aviso debajo del nombre y el mensaje al guardar te dicen que ya existe.
4. Cambia la sede de tu llave a **Hotel Demo Playa**: el sistema te pide volver a elegir los lugares (los pisos eran del Centro). Usa el alcance **Otra** con "Bodega de playa".
5. Da de baja `HDC-BOD-01` como **Dañado**, con cobro: el monto sugerido aparece solo; elige al responsable con su número de empleado (`1009`). Anota el folio del voucher.
6. Reactiva `HDC-BOD-01`.
7. Marca tres llaves y toca **Imprimir Etiquetas**. Escanea un QR con el celular: abre la ficha de la llave.
8. Filtra **Tipo: Metálica tradicional** y toca **Exportar**: el archivo trae solo esas llaves.
9. **`agente.demo`:** abre el catálogo: ve solo las llaves de su sede y no puede registrar, imprimir ni exportar.

## Para recordar

- El nombre de la llave es único en la empresa; si la otra está de baja, reactívala en vez de repetirla.
- Los lugares se eligen de la sede de la llave; el sistema rechaza lugares de otra sede.
- Sin horarios, la llave vale las 24 horas. Un horario de 23:00 a 07:00 termina al día siguiente.
- Dar de baja siempre genera un **voucher** con folio; reactivar no lo borra.
- El QR se genera en la plataforma y solo lleva un código aleatorio: no expone datos.

## Autoevaluación

1. ¿Dónde se registra que un camarista se llevó la llave del Piso 1? *(En Préstamo de llaves, no en el catálogo)*
2. ¿Qué alcance uso para el cuarto de bombas que no está en Zonas y áreas? *(Otra)*
3. ¿Cuándo aparece "Vence pronto"? *(Cuando faltan 30 días o menos para la fecha de caducidad)*
4. ¿Puedo dar de baja una llave desde Editar? *(No: solo con el círculo rojo, que genera el voucher)*
5. ¿Qué hace un Agente en el catálogo? *(Consultar las llaves de su sede)*
