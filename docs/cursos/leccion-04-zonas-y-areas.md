# Lección 4 — Zonas y edificios

**Para:** Administrador del cliente · **Duración:** 20 minutos

## Práctica (en QA, como `admin.demo`)

1. Entra a **Estructura → Zonas y edificios**. Verás una tarjeta por sede con sus conteos; toca **Entrar a la sede** en *Hotel Demo Centro*. Verás la *Torre A* de demo y, arriba, la ruta **Zonas y edificios › Hotel Demo Centro**.
2. Crea la zona **Torre B** con código `TB` (no se pide la sede: queda en la que estás).
3. Entra a *Torre B* y usa **Copiar pisos** desde *Torre A*. Deben aparecer Piso 1 y Piso 2.
4. Entra a *Piso 1* de Torre B y crea por lote del 1 al 6 con prefijo `B1`, con ceros. Deben quedar B101 a B106.
5. Repite el mismo lote: el aviso debe decir que se omitieron 6.
6. Abre *B101*, agrega el área **Baño** sin etiqueta y dentro los elementos **Lavabo** y **Regadera**.
7. Agrega un tipo de elemento propio, **Jacuzzi**, y úsalo en el baño.
8. En **Secciones**, crea "Torre B frente" y asígnale B101 a B103.
9. Desactiva *Piso 1* de Torre B y comprueba que sus habitaciones también quedan inactivas. Luego reactívalo.
10. En *Piso 1* de Torre B toca **Mover a…** en *B106*, elige *Torre B › Piso 2* y toca **Guardar**. El aviso ofrece **Ir a Piso 2**, donde ya aparece B106.
11. Crea por lote B110 a B112 en *Piso 1*, márcalas, toca **Eliminar definitivamente las marcadas**, escribe `3` y toca **Eliminar**. Deben desaparecer las tres.

## Para recordar

- Primero eliges la sede (tarjeta); ahí ves solo sus zonas y secciones. Con una sola sede se entra directo.
- Construye de arriba hacia abajo: zona → piso → habitación → áreas → elementos.
- El lote y la copia de pisos nunca duplican: puedes repetirlos sin miedo.
- Desactivar arrastra todo lo de abajo; reactivar se hace de arriba hacia abajo.
- Si algo quedó en el lugar equivocado, **muévelo**: conserva llaves, equipos e historial.
- **Eliminar definitivamente** solo borra lo que nadie usó; lo usado se da de baja.

## Autoevaluación

1. ¿Qué pasa si dejas vacía la etiqueta de un área? *(Toma el nombre del tipo y, si se repite, lo numera)*
2. ¿Por qué no puedes reactivar una habitación? *(Su piso o su zona siguen inactivos)*
3. ¿Quién ve un tipo de elemento que agregaste? *(Solo tu empresa)*
4. ¿Cómo cambias de sede sin salir del módulo? *(Toca «Zonas y edificios» en la ruta de arriba y elige otra tarjeta)*
5. ¿Por qué no se eliminó una habitación marcada? *(Alguien la usó: por ejemplo, una llave la abre. Se puede dar de baja)*
