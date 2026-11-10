# Lección 48 · Suscripciones de los clientes

**Duración:** 30 minutos · **Para:** Super Administrador de la plataforma y Administrador del cliente (práctica 6).

Aprenderás:

1. A aprobar una prueba gratis y a activar a un cliente que pagó.
2. A revisar a los clientes nuevos.
3. A recibir un pago, cambiar el plan y hacer excepciones.
4. A crear una promoción y verla en la página de planes.
5. Qué pasa cuando una suscripción vence: avisos, gracia y solo consulta.

## Antes de empezar

Usa la cuenta de **Super Administrador** del ambiente de pruebas. Los datos de ejemplo traen tres clientes: **Hotel Laguna** (activo, por revisar), **Plaza Norte** (en periodo de gracia) y **Torre Centro** (solo consulta), una prueba gratis por aprobar (**Grupo Sol**) y la promoción **BIENVENIDA15**. La empresa de prueba de siempre no tiene suscripción: sigue sin límites.

## Práctica 1 · Aprobar una prueba gratis

1. **Estructura → Catálogo comercial → Solicitudes** → filtro **Pruebas por aprobar** → **Atender** en la de Grupo Sol.
2. Toca **Aprobar prueba gratis** y confirma.

Comprueba: se abre la ficha «Grupo Sol» **En prueba** con vencimiento en 14 días, una **Sede principal**, el plan Profesional y sus módulos. En **Clientes** aparece con el filtro **En prueba**.

## Práctica 2 · Un cliente que paga se activa solo

1. Publica la página (Ajustes) y, desde una ventana privada, envía una solicitud en **Contratar** con el plan Esencial, 1 sede, pago **Anual** y el código `BIENVENIDA15`.
2. En tu sesión, abre la solicitud desde la campana: el desglose muestra el descuento anual y la promoción.
3. **Estado: Pagada**, referencia «PRUEBA-1» → **Guardar**.

Comprueba: se abre la ficha del cliente nuevo, **Activa**, con vencimiento en un año. En **Por revisar** aparece con «Sin revisar hace …». Si el correo de la plataforma está configurado, al Administrador le llega la bienvenida.

## Práctica 3 · Revisar y corregir

1. Pestaña **Por revisar** → **Atender** en Hotel Laguna.
2. Revisa **Módulos que tiene** y **Precio pactado**.
3. En **Excepciones** agrega **Usuarios activos de más**, cantidad 5, motivo «Guardias de temporada» → **Guardar**.
4. Escribe una nota y toca **Guardar como revisado**.

Comprueba: «Usuarios activos» sube de 30 a 35 y el historial muestra la excepción y la revisión.

## Práctica 4 · Promoción

1. Pestaña **Promociones** → **Nueva promoción**: «Buen Fin», código `BUENFIN`, porcentaje 12, primeros 2 meses → **Guardar**.
2. En la página pública abre **Contratar**, escribe `BUENFIN` en la calculadora y toca **Contratar con esta selección**.

Comprueba: «Código aplicado: Buen Fin» y el renglón «Promoción Buen Fin (primeros 2 meses)» con su descuento. Dale **Dar de baja** y repite: «Esa promoción ya no está disponible.»

## Práctica 5 · Vencimiento y solo consulta

1. Ficha de **Plaza Norte**: está **En periodo de gracia**.
2. Ficha de **Torre Centro**: está en **Solo consulta**. Toca **Recibir pago** con la referencia «PRUEBA-2».

Comprueba: Torre Centro pasa a **Activa** y vence en un mes desde hoy. En **Dar de baja** escribe un motivo y confírmalo: vuelve a **Solo consulta**; con **Reactivar** regresa.

## Práctica 6 · Lo que ve el cliente (Administrador)

1. Entra como el Administrador de **Hotel Laguna** → **Estructura → Empresas**: bloque **Tu suscripción** con plan, sedes, usuarios y vencimiento.
2. Intenta dar de alta una tercera sede en **Sedes**: «Tu plan incluye 2 sedes…».
3. Entra como el Administrador de **Torre Centro** (antes de la práctica 5) y abre cualquier pantalla: franja roja de solo consulta; puedes ver y exportar, pero al guardar aparece el mismo mensaje. En **Tu suscripción → Ampliar o renovar mi plan** toca **Guardar solicitud**: sí se puede.

## Repaso

- Pagar activa solo; la prueba gratis la apruebas tú.
- Todo cliente nuevo pasa por **Por revisar**.
- Vencida: gracia (sigue operando) y después solo consulta (ve y exporta). Al pagar, vuelve al momento.
- Las empresas sin suscripción no cambian.
