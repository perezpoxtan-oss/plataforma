---
titulo: Credenciales
modulos: [colaboradores.credencial]
seccion: Estructura
orden: 25
resumen: La credencial de cada colaborador: quién tiene, quién no, cuáles vencen y cómo imprimir varias a la vez.
---

# Credenciales

**¿Para qué sirve?** Para ver en una sola lista la **credencial** de cada colaborador (la del empleado; el gafete es el de las visitas): quién la tiene vigente, cuáles están por vencer o vencidas, cuáles no regresaron y quién todavía no tiene. Desde aquí imprimes varias a la vez.

## Antes de empezar

- Necesitas el permiso **Colaboradores → Credencial** (de fábrica, Recursos Humanos y el Administrador). Si no ves el botón **Credenciales**, pídelo a tu administrador.
- La vigencia la decide tu empresa en **Configuración → Estructura** (de fábrica, sin vencimiento).

## Cómo abrir la lista

1. Entra a **Estructura → Colaboradores**.
2. Arriba toca **Credenciales**.

**Qué debes ver:** un renglón por colaborador con su folio, su estado (**DISPONIBLE**, **ASIGNADO**, **POR VENCER**, **VENCIDA**, **NO REGRESÓ**, **DE BAJA**) y su vencimiento, o **Sin credencial**.

## Cómo filtrar y buscar

1. Toca una pastilla: **Vigentes**, **Por vencer**, **Vencidas**, **No regresó**, **De baja** o **Sin credencial**. El número dice cuántos hay.
2. Si quieres, escribe el nombre o el número de empleado y toca **Buscar**.

## Cómo imprimir varias a la vez

1. Marca la casilla de cada colaborador.
2. Toca **Imprimir seleccionadas**. A quien no tenga credencial vigente se le emite una nueva en ese momento.
3. Se abre la hoja con el **frente y el reverso** de cada credencial en tamaño CR80. Toca **Imprimir credenciales** y en la impresora elige CR80 y «Tamaño real / 100 %» (o **Guardar como PDF**).
4. Cuando entregues cada una, ábrela desde su renglón y toca **Dar salida**.

## Qué lector necesita cada tecnología

La credencial siempre lleva su **código QR**. Si tu empresa también usa tarjeta en el plástico, quien administra la empresa la habilita en **Configuración → Estructura → Credenciales del personal → Avanzado: tecnología y reverso** (puede marcar las dos).

| Tecnología | Qué lector necesita la caseta |
|---|---|
| **Código QR** (siempre) | La cámara de cualquier celular o un lector de códigos QR. |
| **Tarjeta Proximidad 125 kHz** | Un lector USB de proximidad 125 kHz conectado a la computadora: al acercar la tarjeta escribe el número como si fuera un teclado. |
| **Tarjeta MIFARE 13.56 MHz** | El NFC de un celular Android o un lector USB de 13.56 MHz (MIFARE). |

Con cualquiera de las tres, la caseta encuentra al colaborador igual. En la ficha del colaborador, la credencial dice **Se lee con:** y, si lleva tarjeta, cuál es y **Lector que la lee**.

Si tu empresa no habilitó ninguna tarjeta, al dar salida a la credencial **no se pide número de tarjeta**.

## Avisos de vencimiento

Si tu empresa usa vigencia, **Mis pendientes** muestra **Credenciales por vencer** (en ámbar) desde los días antes que eligió, y **Credenciales vencidas o por vencer** (en rojo) cuando alguna ya venció y sigue en uso. Repón las vencidas desde la ficha del colaborador (**Reponer credencial → Vencida**).

En la caseta, si un colaborador se identifica con una credencial que ya no vale, la pantalla lo dice («ya no es vigente») para que le pidan otra identificación.

## Si algo sale mal

| Mensaje o síntoma | Qué significa | Qué hacer |
|---|---|---|
| «Ya tiene una credencial vigente: repónla o dala de baja antes de emitir otra.» | Solo puede haber una vigente. | Usa **Reponer credencial** en su ficha. |
| «Este colaborador es un alta provisional…» | Lo registró la caseta y falta verificarlo. | Verifícalo en [Altas por verificar](altas-por-verificar.md) y vuelve a intentar. |
| «Ninguno de los marcados puede tener credencial…» | Marcaste altas provisionales o colaboradores de baja. | Marca otros. |
| La credencial sale sin foto. | No se guardó su foto. | En su ficha abre **Tomar foto**, guárdala y repón la credencial con **Cambio de datos o foto**. |
| «Tu empresa usa solo código QR en las credenciales: no se registra número de tarjeta…» | Tu empresa no habilitó ninguna tarjeta. | Si van a usar tarjeta, pide que la habiliten en **Configuración → Estructura** (Credenciales del personal). |
| «Elige qué tarjeta es: Proximidad 125 kHz o MIFARE 13.56 MHz.» | Tu empresa usa las dos y no marcaste cuál lleva el plástico. | En **¿Qué tarjeta es?** marca la que corresponde y vuelve a tocar **Dar salida**. |
| Al escanear dice **No vigente**. | Se repuso, venció, se dio de baja o no regresó. | Revisa su ficha; si debe tener una, emítela o repónla. |

## Preguntas frecuentes

**¿Qué pasa con la credencial anterior cuando la repongo?** Deja de valer en ese momento: al escanearla dice **No vigente** y la caseta ya no lo encuentra con ella.

**¿Qué ve alguien que escanea la credencial con su celular?** Solo **Vigente** con el nombre, la empresa y hasta cuándo vale, o **No vigente**. **No muestra la foto**: para confirmar que es la persona, compárala con la foto impresa en la credencial. Nunca CURP, RFC, NSS, teléfono ni domicilio.

**¿Puedo usar mi impresora de credenciales?** Sí, cualquiera que imprima tamaño CR80: la hoja ya viene a la medida exacta.

## Relacionado

- [Colaboradores](colaboradores.md)
- [Configuración](configuracion.md)
- [Entregar y recibir](entregar-y-recibir.md)
- [Vouchers](vouchers.md)
