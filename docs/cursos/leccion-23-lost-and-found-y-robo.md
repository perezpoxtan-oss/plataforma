# Lección 23 — Lost & Found y Robo

**Para:** Agentes de caseta, Supervisores, Jefe de seguridad, Director y Administrador · **Duración:** 20 minutos

## Qué vas a aprender

- Encontrar un objeto en el archivo de Lost & Found (filtros, búsqueda o escaneando la etiqueta).
- Entregar, donar o destruir un objeto con firma.
- Imprimir la etiqueta de la bolsa y la Auditoría de Inventario.
- Dar seguimiento a un caso de robo y cotejarlo con Lost & Found.

## Práctica (en QA)

1. **`agente.demo`** (sede Centro): abre Operación → **Lost & Found**. Toca **Solo urgentes / vencidos**: la **Bufanda** está en rojo (40 días de 30) y los **Audífonos** en ámbar.
2. Toca **Todos** y busca `celular`. El **Teléfono celular** tiene el **Reporte de pérdida RP-000001 — LAURA GÓMEZ**.
3. Toca **Cerrar / Entregar** → **Devuelto en persona**. El nombre ya viene escrito. Elige **INE**, intenta **Registrar Cierre** sin firma (no deja) y luego firma y regístralo. Revisa que ahora diga **Devuelto al Huésped**.
4. Abre la ficha de los **Lentes de sol** (ojo): ya se entregaron a MARK JOHNSON; mira la firma.
5. En **Escanear etiqueta de la bolsa** escribe `LF-000004` y Enter: se abre la ficha de la Bufanda. Toca **Etiqueta** e imprímela.
6. Cierra la **Bufanda** como **Donado a colaborador**: escribe el número de empleado de un compañero en el lector y elige.
7. Toca **Auditoría**, elige la sede y **Aplicar**. Imprime y cuenta.
8. Fíjate que el Agente no ve el enlace a los días de resguardo: los días de cada tipo aparecen en el filtro de tipo de valor.
9. **`admin.demo`**: toca **Configurar en Estructura → Configuración** (o Estructura → Configuración → *Lost & Found: días de resguardo*), cambia *Ropa* a `20` y guarda. Vuelve al archivo: el **Sombrero** (25 días) pasa a rojo.
10. **`agente.demo`**: abre Operación → **Robo — seguimiento** y toca **Con sospechoso**. Abre el caso de la **Cartera**, escribe una nota, cambia *¿Se dio parte a la policía?* a **Sí** con el folio `FGE-2026-2001` y guarda: deja de salir en **Sin parte a la policía**.
11. En el caso de la **Laptop**, toca **Buscar Coincidencias en Lost & Found**.
12. **`director.demo`**: abre los dos archivos. Puede ver e imprimir, pero no entregar ni editar.
13. **`agente2.demo`** (Playa): solo ve lo de Hotel Demo Playa.

## Para recordar

- Los objetos y los robos se registran en la **Bitácora de Novedades**; aquí se consultan y se cierran.
- Rojo = vencido: decidir entrega, donación o destrucción.
- Cada cierre lleva firma; un artículo cerrado no se vuelve a cerrar.
- Si un huésped reportó la pérdida, revisa que sea la misma persona antes de entregar.

## Autoevaluación

1. ¿Qué quiere decir una fila en ámbar? *(Que ya pasó el 70 % de sus días en resguardo)*
2. ¿Qué se necesita para donar un objeto? *(Escanear al colaborador que lo recibe y la firma de quien autoriza)*
3. Me equivoqué de tipo de cierre después de firmar, ¿qué pasa con la firma? *(Se borra y se vuelve a pedir)*
4. ¿Quién cambia los Días de Resguardo? *(Quien tiene «configurar» en toda la empresa, por ejemplo el Administrador)*
5. ¿Cómo encuentro rápido un objeto con su bolsa en la mano? *(Escaneando la etiqueta o escribiendo su folio)*
