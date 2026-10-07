# Zonas y áreas (Estructura → Zonas y áreas)

Aquí registras la estructura física de cada sede, de lo general a lo particular:

**Zona / Edificio → Piso → Habitación → Áreas de la habitación → Elementos**

Si tu empresa no es hotel, en lugar de "Habitación" verás Oficina, Departamento o Casa.

## 1. Zonas / Edificios

![Zonas](img/espacios/1-zonas.png)

1. Toca **Nueva Zona / Edificio**.
2. Elige la sede y escribe el nombre. El código corto es opcional (por ejemplo `TA`).
3. Toca **Entrar a Pisos** para seguir bajando.

## 2. Pisos

- Toca **Nuevo Piso**.
- Si otro edificio ya tiene los mismos pisos, usa **Copiar pisos** y elige el edificio de origen. Solo se copian los que faltan.

## 3. Habitaciones

![Habitaciones](img/espacios/2-habitaciones.png)

- **Nueva Habitación:** una por una.
- **Crear por Lote:** muchas de una vez.
  - **Rango numérico:** por ejemplo, prefijo `1`, desde 5 hasta 8 → 105, 106, 107, 108.
  - **Lista de nombres:** uno por renglón (Suite A, Suite B…).
  - Las que ya existen se saltan y te avisamos cuántas fueron.

![Crear por lote](img/espacios/3-lote.png)

## 4. Detalle de la habitación

![Detalle](img/espacios/4-detalle.png)

1. **Agregar Área:** elige el tipo (Baño, Recámara, Terraza…). La etiqueta es opcional: si la dejas vacía se usa el tipo y, si se repite, se numera (Baño, Baño 2).
2. Dentro de cada área toca **+ Agregar Elemento** (Cama, Lavabo, Caja fuerte…).
3. ¿Falta un tipo? Abre **"¿No encuentras el tipo…?"** al final y agrégalo. Solo lo verá tu empresa.

## Secciones

Las secciones agrupan habitaciones de una sede, por ejemplo "Vista al mar" o "Torre Norte".

1. En la pestaña **Secciones** toca **Nueva Sección**.
2. Toca **Asignar Habitaciones** y marca las que pertenecen a esa sección. Puedes buscar por número o por piso.
3. También puedes elegir la sección al dar de alta o al editar cada habitación.

![Asignar habitaciones](img/espacios/6-asignar-seccion.png)

## Desactivar

El botón **⊘** desactiva un espacio **y todo lo que tiene dentro**: si desactivas un piso, se desactivan sus habitaciones. El historial se conserva. Para reactivar usa el mismo botón; primero reactiva lo de arriba (no puedes reactivar una habitación si su piso sigue inactivo).

En el celular la pantalla se adapta:

![Celular](img/espacios/5-celular.png)

## Avisos mientras escribes (Ronda 5)

Al dar de alta o editar una zona, un piso, una habitación, un área o un elemento, la ventana te avisa **antes de guardar**:

- **Nombre igual en el mismo lugar** (caja roja): «Ya existe «Torre A» en este mismo lugar». No se podrá guardar.
- **Nombre parecido** (caja amarilla): «Se parece a «Torre A (TA)»». Por ejemplo, «Torre-A» y «Torre A». Es solo un aviso.
- **Código parecido** (caja amarilla): los espacios y guiones no cuentan, así que **TB, T-B y T B se toman como el mismo código**. El aviso dice cuál zona ya lo usa.

![Nombre y código parecidos](img/ronda-5b/zonas-codigo-parecido.png)

- **Ya existe pero está desactivada**: si la zona que quieres crear ya existe y está desactivada, el aviso lo dice y te ofrece **Reactivar**. Tócalo para recuperarla con todo lo que tenía, en lugar de crearla de nuevo.

![Zona desactivada: Reactivar](img/ronda-5b/zonas-inactiva-reactivar.png)

En modo **Sol** y en el celular:

![Modo Sol](img/ronda-5b/zonas-reactivar-sol.png)

![En el celular](img/ronda-5b/zonas-reactivar-movil.png)

- **Tipo que ya existe**: en «¿No encuentras el tipo…?», si escribes un tipo que ya está en la lista te dice «ya existe en la lista: no hace falta agregarlo». Si aun así lo guardas, no se duplica y te avisa que ya existía.

![Tipo que ya existe](img/ronda-5b/zonas-tipo-ya-existe.png)

- **Sección repetida**: en Nueva Sección te avisa si esa sede ya tiene una sección con ese nombre.
