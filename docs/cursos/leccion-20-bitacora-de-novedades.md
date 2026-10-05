# Lección 20 — Bitácora de Novedades

**Para:** Operador de caseta (Agente), Supervisor, Jefe de seguridad y Administrador · **Duración:** 30 minutos

## Qué vas a aprender

- Despachar un ticket rápido desde la caseta (con el gafete de quien reporta).
- Atender un expediente: preguntas base, formato de la categoría y Minuto a Minuto.
- Llenar un Accidente con el mapa del cuerpo y las firmas.
- Registrar objetos encontrados y reportes de pérdida, buscar coincidencias y vincular.
- Pasar un caso a Pendiente de Turno, cerrarlo con resolución y reabrirlo con motivo.

## Práctica (en QA)

1. **`agente.demo`:** abre Operación → Bitácora de novedades.
   - Revisa las dos pestañas. Busca «laptop»: queda solo el ticket de Robo.
   - Toca **Nuevo Ticket**. La sede **Hotel Demo Centro** ya viene elegida. Categoría *Sin clasificar todavía*, toca **Fui yo quien lo observó**, ubicación `Lobby, junto a la recepción`, ¿qué sucedió? `Huésped resbaló con agua en el piso`. **Despachar Ticket**.
   - Intenta despachar otro dejando vacío ¿Qué sucedió?: el aviso sale dentro del cuadro.
2. Abre tu ticket. Cambia la categoría a **Accidente / Lesión**:
   - Tipo de Afectado **Huésped / Cliente**, nombre `Ana Ruiz`, habitación `101`.
   - En **Servicio Médico** marca *Contusa* y toca la rodilla/pierna derecha en el mapa.
   - En **Firmas**, elige *Agente de Seguridad (Atiende)*, firma y toca **Guardar Esta Firma**.
   - Escribe en el Minuto a Minuto `Se colocó letrero de piso mojado` y elige **Pendiente de Turno**. **Guardar Expediente**.
3. **`supervisor.demo`:** abre el mismo ticket. Escribe la resolución `Huésped atendido, sin lesión mayor` y márcalo **Resuelto**. Prueba antes a marcarlo Resuelto sin resolución: no te deja.
4. Abre el caso resuelto: está en solo lectura. Toca **Reabrir Caso para Editar**, escribe el motivo y confirma. Mira el Minuto a Minuto.
5. Abre el ticket **#00007 Lost & Found**: los artículos tienen folio **LF-000001** y **LF-000002** y su semáforo. En el reporte de pérdida **RP-000001** toca **Buscar Coincidencias** y **Vincular** el teléfono.
6. Abre el **#00005 Siniestro**: tiene un lesionado y por eso existe el **#00006 Accidente** «Generado desde» el siniestro.
7. Abre el **#00008 Robo** y toca **Ficha de Hechos**: ves lo que pasó en la habitación 202 y en su piso.
8. Imprime el **#00002 Accidente**: salen las 6 firmas.
9. **`agente.demo`:** abre el **#00003** (resuelto): no aparece «Reabrir Caso». Tampoco ves **Exportar**.
10. **`agente2.demo`** (Playa): solo ve los tickets de **Hotel Demo Playa**.
