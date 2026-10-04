# Lección 4 — Zonas y áreas

**Para:** Administrador del cliente · **Duración:** 20 minutos

## Práctica (en QA, como `admin.demo`)

1. Entra a **Estructura → Zonas y áreas**. Verás la *Torre A* de demo.
2. Crea la zona **Torre B** en *Hotel Demo Centro* con código `TB`.
3. Entra a *Torre B* y usa **Copiar pisos** desde *Torre A*. Deben aparecer Piso 1 y Piso 2.
4. Entra a *Piso 1* de Torre B y crea por lote del 1 al 6 con prefijo `B1`, con ceros. Deben quedar B101 a B106.
5. Repite el mismo lote: el aviso debe decir que se omitieron 6.
6. Abre *B101*, agrega el área **Baño** sin etiqueta y dentro los elementos **Lavabo** y **Regadera**.
7. Agrega un tipo de elemento propio, **Jacuzzi**, y úsalo en el baño.
8. En **Secciones**, crea "Torre B frente" y asígnale B101 a B103.
9. Desactiva *Piso 1* de Torre B y comprueba que sus habitaciones también quedan inactivas. Luego reactívalo.

## Para recordar

- Construye de arriba hacia abajo: zona → piso → habitación → áreas → elementos.
- El lote y la copia de pisos nunca duplican: puedes repetirlos sin miedo.
- Desactivar arrastra todo lo de abajo; reactivar se hace de arriba hacia abajo.

## Autoevaluación

1. ¿Qué pasa si dejas vacía la etiqueta de un área? *(Toma el nombre del tipo y, si se repite, lo numera)*
2. ¿Por qué no puedes reactivar una habitación? *(Su piso o su zona siguen inactivos)*
3. ¿Quién ve un tipo de elemento que agregaste? *(Solo tu empresa)*
