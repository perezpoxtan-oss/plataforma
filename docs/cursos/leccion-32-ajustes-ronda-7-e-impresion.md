# Lección 32 — Ronda 7: un solo aviso de duplicados y el gestor de impresión QR

## Lo que aprenderás

1. Cómo se terminó de unificar el aviso de «ya existe» (`data-duplicado`) en los últimos padrones.
2. Cómo una pantalla agrega una acción propia al aviso genérico sin copiarlo (evento `duplicado:pintado`).
3. Cómo funciona una hoja de impresión con medidas reales en milímetros (`@page`).
4. Cómo se guarda un historial de impresiones y se reimprime.

## 1. Un solo mecanismo

Antes, Llaves, Departamentos, Puestos y Turnos tenían la lista de nombres escondida en la página (`data-nombres-existentes`) y Usuarios tenía su caja de homónimos. Ahora todos preguntan al servidor:

```blade
<input name="nomenclatura" data-duplicado="{{ route('llaves.duplicado') }}" data-duplicado-con="sede_id">
```

El servidor (`DuplicadoCatalogosController`) responde siempre con `AvisoDuplicado`: `existe`, `parecido`, `libre` o `nada`. Ventajas: no se manda la lista completa al navegador, se respetan las sedes del usuario y el mensaje es igual en toda la plataforma.

**Ejercicio**: abre Turnos → Alta, escribe «matutina» y observa el aviso amarillo. Luego desactiva un turno y escribe su nombre: aparece **Reactivar**.

## 2. Acciones propias: «Vincular»

El bloque genérico de la Ronda 5 ahora avisa cuando terminó de pintar:

```js
campo.dispatchEvent(new CustomEvent('duplicado:pintado', { bubbles: true, detail: datos }));
```

Usuarios escucha ese evento y, si la respuesta trae `vincular`, agrega el botón **Vincular** a esa coincidencia. Así no hay dos mecanismos: el aviso es el mismo, solo se le agrega un botón.

## 3. Plantillas en milímetros

Una plantilla guarda ancho, alto, márgenes, separación, columnas × filas, tamaño del QR y qué datos lleva. La hoja de impresión escribe:

```css
@page { size: 50.8mm 25.4mm; margin: 0; }   /* rollo: una etiqueta por página */
@page { size: 215.9mm 279.4mm; margin: 0; } /* carta: la planilla completa */
```

Las impresoras térmicas entienden cada página como una etiqueta. En carta/A4 las etiquetas se acomodan con CSS Grid. El servidor revisa que el QR y la planilla **quepan** antes de guardar y la vista previa avisa mientras escribes.

**Ejercicio**: crea una plantilla «Planilla 3 × 10» en hoja carta con 66.7 × 25.4 mm, margen de arriba 12.7 y separación 3.2 entre columnas. Pon 11 filas: verás el aviso «no cabe». Cámbialo a 10 y usa **Hoja de prueba**.

## 4. Historial y reimpresión

«Imprimir» ahora es un formulario **POST**: el servidor guarda la impresión (`impresiones_etiquetas` + sus etiquetas) y redirige a su hoja. **Reimprimir** crea otra impresión que apunta a la original. Al ver o reimprimir, cada etiqueta se vuelve a revisar con los permisos de su tipo: si ya no tienes permiso, no sale.

## Preguntas de repaso

1. ¿Por qué el aviso de llaves manda también `sede_id`?
2. ¿Qué pasa si el Jefe de seguridad intenta editar una plantilla de toda la empresa?
3. ¿Qué diferencia hay entre «Ver hoja» y «Reimprimir» en el historial?
4. ¿Por qué el Agente no ve en el historial las impresiones de llaves?

(Respuestas: 1. porque el nombre es único por sede; 2. recibe «solo la cambia quien administra toda la empresa» (403); 3. «Ver hoja» abre la misma impresión, «Reimprimir» crea una nueva en el historial; 4. porque no puede imprimir llaves.)
