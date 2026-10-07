# Lección 29 — Altas por verificar

**Para:** Operador de caseta y Administrador del cliente · **Duración:** 20 minutos

## Qué vas a aprender

- Qué hacer cuando en la caseta llega un auto, una persona o una empresa que **no está en el padrón**.
- Cómo te ayuda «**¿Es alguno de estos?**» a no registrar repetidos.
- Cómo **verificar** lo que registró la caseta: aceptar, unir con el existente o rechazar.
- Qué pasa con lo rechazado y con lo unido.

## Práctica (en QA)

### Parte 1 — La caseta (`agente.demo`)

1. Entra como **`agente.demo`**. Abre Padrones → **Padrón Vehicular**.
   - Ves fichas con la insignia **Pendiente de verificar**.
   - No tienes botones para editar ni verificar.
2. Abre Operación → **Control de accesos** → **Nuevo Ingreso**.
   - Elige **Personal externo** y **Vehículo (Auto / Moto)**.
   - En **Placas** escribe `ABC-12-3B`.
   - La lista dice que no está igual, pero muestra **¿Es alguno de estos?** con `ABC123A` y `ABC128A`.
   - **No elijas nada todavía.**
3. En **Nombre de la Visita** escribe `LAURA MENDES RIOS` (con «s»).
   - Aparecen «Laura Méndez Ríos» y «Laura Mendez Rios» (esta última, pendiente de verificar).
   - Toca **Laura Méndez Ríos**: queda elegida.
4. Toca **Registrarla con su identificación en el Padrón de personas**.
   - Lee el aviso amarillo.
   - Escribe `Ricardo Ortega` y toca **Registrar**.
   - La ventana pregunta si es **Ricardo Ortega Vela**. Toca **Cancelar**: no registres nada.
5. Cierra el ingreso. Abre Operación → **Pases de salida** → **Nuevo Pase**.
   - En **Destino** elige **Proveedor** y toca **Nuevo Proveedor**.
   - Escribe `Abarrotes Caribe` y toca **Guardar Empresa**.
   - Te propone las dos «Abarrotes del Caribe». Cancela.

### Parte 2 — Quien verifica (`admin.demo`)

6. Entra como **`admin.demo`**.
   - En **Inicio** está la tarjeta **«N altas por verificar»** con los botones Vehículos, Empresas externas y Personas.
   - Toca **Vehículos**: llegas con la píldora **Pendientes de verificar** encendida.
7. En la ficha **XTR901C** toca **Verificar**.
   - Lee quién la registró, cuándo y desde dónde.
   - Con **Es correcto** marcado, corrige el **Tipo** a «SUV / Camioneta» y toca **Aceptar y verificar**.
   - La ficha ya no dice «Pendiente».
8. En la ficha **ABC128A** toca **Verificar** y elige **Ya existía**.
   - En la lista está **ABC123A**: márcala y toca **Unir con el elegido**.
   - La ficha ABC128A queda «Unido con otro registro».
9. Abre Padrones → **Empresas Externas**.
   - En **Plomería Express Cancún** toca **Verificar** → **Rechazar**.
   - Escribe solo `no` y toca **Rechazar alta**: el aviso aparece dentro de la ventana.
   - Escribe `La empresa no existe` y rechaza.
   - La ficha queda «Rechazada» con el motivo, sin botones de editar ni reactivar.
10. Abre Padrones → **Padrón de Personas**.
    - En **Laura Mendez Rios** toca **Verificar** → **Ya existía**.
    - Elige **Laura Méndez Ríos** y une.
11. Abre Padrones → **Padrón Vehicular** y busca **ZZZ000**: es un rechazo de ejemplo, con su motivo.
12. Abre Estructura → **Bitácora de auditoría** y filtra el módulo **Vehículos**. Verás:
    - «Alta provisional» (lo que registró la caseta);
    - «Verificado»;
    - «Unión de duplicado», con cuántos registros se movieron.
13. Abre Estructura → **Configuración** → Avisos por correo. El último aviso enciende o apaga el correo de altas por verificar.

### Parte 3 — Lo rechazado ya no se usa

14. Entra otra vez como **`agente.demo`**.
    - Registra un ingreso en vehículo con las placas `ZZZ000`.
    - La plataforma no lo permite y explica el motivo del rechazo.
15. Registra un ingreso con las placas `ABC128A`. Se usa **ABC123A**, el registro con el que se unió.

## Para recordar

- La caseta **nunca** edita los padrones. Lo nuevo lo registra desde su pantalla de trabajo y se usa de inmediato.
- Antes de registrar, revisa **«¿Es alguno de estos?»**: evita repetidos aunque cambien acentos, guiones o espacios.
- Verifica quien **edita** el padrón. Tiene tres caminos:
  - **Es correcto**: aceptar, corrigiendo los datos.
  - **Ya existía**: unir; todo pasa al correcto.
  - **Rechazar**: con motivo.
- Lo rechazado se queda como historia, pero **ya no se puede usar**. Lo unido lleva solo al registro correcto.
- El aviso de Inicio y el correo te dicen cuántas altas faltan por revisar en tus sedes.
