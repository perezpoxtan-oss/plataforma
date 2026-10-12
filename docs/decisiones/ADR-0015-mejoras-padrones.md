# ADR-0015 — Padrones: un solo vocabulario, un solo camino de baja e impresión, campana de altas y unión de duplicados

**Fecha:** 2026-10-26 · **Estado:** aceptada (detalle en [docs/tecnico/mejoras-padrones.md](../tecnico/mejoras-padrones.md))

## Contexto

La crítica de uso de los padrones encontró la misma acción con nombres distintos (Desactivar, Suspender, Vetar, Dar de baja), dos caminos para un mismo hecho con resultados distintos (reactivar desde la ficha dejaba el voucher vigente; imprimir desde el padrón o desde Etiquetas QR daba hojas distintas), altas por verificar que solo llegaban por correo y que el agente no se enteraba si se rechazaban, personas verificadas duplicadas que no se podían unir y ocupaciones de estacionamiento desfasadas sin forma de corregirlas desde la zona.

## Decisión

1. **Vocabulario**: «Dar de baja» / «Reactivar» / «DE BAJA» en todos los padrones y catálogos; «Guardar» en todos los formularios; «Se le cobra al colaborador» en lugar de «CXC». Solo cambian textos (criterio 4: los cambios de texto que simplifican se aplican directo). La acción `eliminar` de la Matriz se renombra «Dar de baja».
2. **Campana** (criterio 1) para las altas por verificar y su resultado a quien las registró, también para los colaboradores que registra la caseta, que adoptan las mismas palabras («por verificar»). El correo sigue igual.
3. **Reactivar = Recuperado**: la ficha usa `RecuperacionVouchers`. Sin cobro se cancela el voucher ahí mismo; con cobro se lleva al diálogo «Recuperado» (hay que decir si ya se cobró). Gafetes y Equipos ganan la firma digital de Llaves.
4. **Unir personas verificadas** con las mismas reglas que unir un alta pendiente; el duplicado queda «Unido» (nada se borra). Lo hace quien edita y da de baja.
5. **Impresión**: configurable por empresa con lo original por omisión (decisión del dueño: lo que una empresa pueda querer distinto se vuelve configurable). «Hoja propia de cada padrón» (omisión) o «Plantillas de Etiquetas QR» (un solo camino, con historial). En ambos casos la hoja propia ofrece el camino de Etiquetas QR y todas recuerdan «Tamaño real / 100 %».
6. **Estacionamientos**: «Dar salida» desde la zona usa la misma salida de la Bitácora de accesos y el permiso de Accesos; no se edita la ocupación a mano.
7. **Altas cortas** (criterio 8) con «Más datos» plegado (Llaves, Personas) y **siguiente paso** tras guardar (criterio 5).

No se hizo (pide decisión del dueño): una sola categoría «Particular» con subtipo en Vehículos (cambiaría datos, filtros y reportes; se agruparon visualmente), quitar el tipo Operativo/Administrativo de Puestos, ligar Turnos a colaboradores, y la ficha de colaborador con pestañas.

## Consecuencias

- Migración `2026_10_26_000100_mejoras_padrones_nombres`: solo renombra `acciones.eliminar` y pone `modulos.nombre_menu` de Estacionamientos; `down()` regresa ambos. Sin tablas ni columnas nuevas.
- Preferencia nueva `empresas.preferencias['padrones']['impresion']` (sin la clave = hoja propia).
- Tres rutas nuevas (`personas.unir-duplicado`, `estacionamientos.salida`, `etiquetas.plantillas.impresion-padrones`), un evento de auditoría reutilizado con etiqueta existente (`etiquetas_qr.configurado`) y cuatro tipos de notificación (`alta_por_verificar`, `alta_verificada`, `alta_unida`, `alta_rechazada`).
- Un revert del código deja la preferencia sin leerse, las notificaciones creadas en la campana y los nombres renombrados hasta correr el `down()`.
