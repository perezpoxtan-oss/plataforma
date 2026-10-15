# Lección 47 · Catálogo comercial y página de planes

**Duración:** 25 minutos · **Para:** Super Administrador de la plataforma.

Aprenderás:

1. A revisar la página pública de planes antes de publicarla.
2. A cambiar precios, planes y paquetes, y su orden.
3. A publicar (y retirar) la página y ajustar moneda, IVA y textos.
4. A atender una solicitud que llega desde «Contratar».
5. Cómo se calcula el total y por qué la solicitud conserva su precio.

## Antes de empezar

Usa la cuenta de **Super Administrador** del ambiente de pruebas. Las cuentas de las empresas (por ejemplo `admin.demo`) no ven este módulo: úsala en la práctica 5 para comprobarlo.

## Práctica 1 · Vista previa

1. **Estructura → Catálogo comercial** → **Ver la página**.
2. Recorre la portada, los planes (el recomendado está resaltado), los paquetes y la calculadora.
3. En la calculadora elige **Profesional**, 3 sedes y **Recursos Humanos y candidatos**.

Comprueba: total al mes $6,542.40 (1,490 + 390 = 1,880 por sede × 3 = 5,640 + IVA 16 % = 902.40). Elige **Corporativo**: el paquete de Recursos Humanos se desmarca («Solo se suma a Esencial y Profesional») y el resumen dice que el plan se cotiza.

## Práctica 2 · Precios y planes

1. Pestaña **Planes** → lápiz de **Esencial** → cambia el precio a 950 → **Guardar**.
2. Toca **Ver la página como la verá el público** y comprueba el precio nuevo.
3. **Nuevo plan**: «Plan de prueba», 500, marca **Caseta y accesos**, deja apagado **Mostrar en la página pública** → **Guardar**. El aviso te recuerda que está oculto.
4. Súbelo un lugar con la flecha y luego **Dar de baja**.

## Práctica 3 · Publicar

1. Pestaña **Ajustes** → enciende **Publicar la página de planes** → **Guardar**.
2. Abre la dirección de la plataforma en una ventana privada (sin sesión): ves la página de planes con **Entrar**.
3. En tu ventana con sesión, abre la misma dirección: ves tu **Inicio**.
4. Apaga el interruptor y guarda: la ventana privada vuelve a mostrar la pantalla de acceso.

## Práctica 4 · Una solicitud

1. Con la página publicada, en la ventana privada toca **Contratar** en un plan, llena tus datos, acepta el aviso de privacidad y toca **Enviar solicitud**. Anota el folio.
2. En tu sesión, abre la campana: «Nueva solicitud SC-…». Tócala.
3. Revisa el **Desglose**, cambia el estado a **Contactada**, escribe una nota interna y toca **Guardar**.

Comprueba: la lista de **Solicitudes** muestra «Sin atender hace …» solo en las nuevas; al guardar se ofrece atender la siguiente. Si cambias después el precio del plan, el desglose de esa solicitud no cambia.

## Práctica 5 · Quién lo ve

1. Entra como `admin.demo`: en **Estructura** no aparece **Catálogo comercial** y la dirección `/catalogo-comercial` responde «No tienes permiso».

## Para recordar

- Todo se cobra **por sede al mes**; los paquetes de **pago único** van aparte. El IVA se suma al final.
- Los precios iniciales son **de ejemplo** y el texto legal es un **borrador** para el abogado.
- Aún no hay cobro en línea: la plataforma registra, avisa y tú anotas el pago.
