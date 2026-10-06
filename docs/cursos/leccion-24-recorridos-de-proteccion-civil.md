# Lección 24 — Recorridos de Protección Civil

**Para:** Agentes de caseta, Supervisores, Jefe de seguridad y Administrador · **Duración:** 20 minutos

## Qué vas a aprender

- Iniciar un recorrido y revisar los equipos uno por uno escaneando su etiqueta.
- Marcar las piezas dañadas y saber cuándo un equipo queda con FALLA.
- Qué pasa con un hallazgo (ticket en la Bitácora de Novedades).
- Guardar para después, continuar y finalizar.
- Imprimir el Reporte de Auditoría y mantener el Catálogo de Equipos.

## Práctica (en QA)

1. **`agente.demo`** (sede Centro): abre Operación → Recorridos de Protección Civil. Fíjate en los colores: **Recorrido #00002** está *Con Hallazgos* y dice qué ticket generó; toca el número del ticket y mira en Novedades el Minuto a Minuto con los dos hallazgos.
2. Regresa y toca **Continuar Recorrido** en el **#00003** (En Proceso). Mira el avance: 2 de 11 equipos.
3. En el escáner escribe `EXT-02` y Enter (o escanea su QR desde el Catálogo con otro celular). Deja todo en verde y toca **Guardar y escanear siguiente**: sale «EXT-02 revisado: OK».
4. Abre **Equipos pendientes de revisar** y toca **Revisar** en `HID-01`. Toca **Manómetro** para ponerlo en rojo: el resultado cambia a FALLA. Guarda. Aparece el aviso con el ticket nuevo de Protección Civil.
5. Escanea `LAM-01`, deja todo en verde pero escribe en Observaciones «Batería baja». Guarda: también es FALLA y se anota en el **mismo** ticket.
6. Toca **¿No tiene etiqueta…? Captúralo a mano**: ID `EXT-80`, categoría *1. Extintores*, guarda.
7. Escribe una observación general y toca **Guardar y Continuar Después**: el recorrido sigue En Proceso en la lista.
8. Ábrelo otra vez y toca **Finalizar Recorrido** → confirma. Queda *Con Hallazgos*.
9. Toca **Reporte de Auditoría**, elige el rango del mes y revisa tus recorridos. Imprime o guarda como PDF.
10. **`agente.demo`** abre **Catálogo de Equipos**: puede ver los equipos y sus QR, pero no aparece **Nuevo Equipo**.
11. **`admin.demo`**: en el Catálogo toca **Nuevo Equipo**, sede Centro, *Extintor*, ID `EXT-01` → guarda: el mensaje dice que ya existe en esta sede. Cámbialo a `EXT-04`, zona *Torre A · Piso 2* y toca **Guardar y capturar siguiente**; registra `EXT-05` con lo mismo. Imprime la etiqueta de `EXT-04`.
12. **`agente2.demo`** (Playa): solo ve el recorrido y los equipos de Hotel Demo Playa.

## Para recordar

- Todo empieza en verde: **toca solo lo que está mal**.
- Una pieza en rojo **o** una observación = FALLA, y la falla siempre llega a Novedades.
- Cada equipo se guarda al momento: puedes cerrar la página y continuar después.
- Para finalizar necesitas al menos un equipo; finalizado ya no se modifica.
- El Agente revisa; el catálogo lo mantienen Supervisor, Jefe, Asistente o Administrador.

## Autoevaluación

1. Revisé un extintor, todo estaba bien pero escribí «Se ve sucio». ¿Cómo queda? *(FALLA: cualquier observación cuenta como hallazgo)*
2. Ya hay un ticket del recorrido y encuentro otra falla, ¿se abre otro ticket? *(No, se anota en el mismo; solo si ese ticket ya se resolvió se abre uno nuevo)*
3. La etiqueta de un hidrante está rota, ¿cómo lo reviso? *(Desde «Equipos pendientes de revisar» → Revisar, o tecleando su ID)*
4. ¿Puedo agregar un equipo a un recorrido Completo? *(No; se inicia un recorrido nuevo)*
5. ¿Puedo registrar el mismo ID en dos sedes? *(Sí; en la misma sede no)*
