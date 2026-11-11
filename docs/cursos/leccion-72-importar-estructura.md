# Lección 72 · Importar estructura

**Duración:** 25 minutos · **Para:** Administrador del cliente (y Super Administrador al dar de alta una empresa nueva).

Aprenderás:

1. A descargar la plantilla de **Importar estructura** y llenarla con un renglón por lugar.
2. A leer la vista previa: **Nueva**, **Ya existe** y **Con error**.
3. A corregir errores y guardar todo de una vez.
4. A descargar la estructura que ya tienes y completarla.

## Antes de empezar

Necesitas una cuenta con el permiso **Importar** de *Sedes* (el Administrador de la empresa lo tiene de fábrica) y Excel u otra hoja de cálculo. Usa una empresa de práctica: lo que guardes se queda (no se borra con otro archivo).

## Práctica 1 · Descargar y llenar la plantilla

1. Entra a **Estructura → Sedes** y toca **Importar estructura**.
2. Toca **Descargar plantilla** y abre el archivo. Lee la hoja **Instrucciones**.
3. En la hoja **Estructura** borra los ejemplos y escribe:

| Sede | Ciudad | Estado | Zona o edificio | Piso | Habitación | Área de la habitación |
|---|---|---|---|---|---|---|
| Hotel Práctica | Puebla | Puebla | Torre A | Piso 1 | 101 | |
| Hotel Práctica | | | Torre A | Piso 1 | 102 | Terraza |
| Hotel Práctica | | | Torre A | Piso 1 | 102 | Baño |
| Hotel Práctica | | | Zona exterior | | Alberca | |

4. Guarda el archivo.

Comprueba: cada renglón trae el camino completo desde la sede; la ciudad y el estado solo van en el primero.

## Práctica 2 · Revisar la vista previa

1. En **Sube tu archivo** elige tu archivo y toca **Revisar archivo**.
2. Lee **Guarda la estructura**: dice cuántas sedes, zonas, pisos, habitaciones y áreas se van a crear.
3. Revisa la columna **Detalle** de cada renglón («Se crea: Torre A › Piso 1 › 101.»).

Comprueba: todas salen como **Nueva** y en **Sedes** todavía no aparece «Hotel Práctica» (nada se guarda hasta tocar **Guardar**).

## Práctica 3 · Corregir un error

1. En tu archivo agrega un renglón con **Piso 2** y **201**, pero sin **Zona o edificio**.
2. Súbelo de nuevo (**Importar otro archivo**).
3. Toca **Con error**: el renglón dice «Hay piso pero falta la zona o edificio…». Observa que **Guardar** no aparece: se guarda todo o nada.
4. Escribe **Torre A** en ese renglón, súbelo otra vez y toca **Guardar**.

Comprueba: «Listo: 1 sede, 2 zonas o edificios, 2 pisos, 4 habitaciones y 2 áreas.» En **Zonas y edificios** ves la Torre A con sus pisos.

## Práctica 4 · Subirlo otra vez y completar

1. Sube **el mismo archivo** otra vez: todos los renglones salen **Ya existe** y no hay nada que guardar.
2. Toca **Descargar lo que ya hay**: llega tu estructura en el mismo formato.
3. Agrega un renglón «Hotel Práctica · Torre A · Piso 1 · 103», súbelo y guárdalo.

Comprueba: solo se creó la habitación 103.

## Para recordar

- Un renglón por lugar, con su camino completo. Solo **Sede** es obligatoria.
- Lo que ya existe se usa; importar nunca borra, da de baja ni cambia nada.
- Con un solo error no se guarda nada: corrige y vuelve a subir.
- En corporativo la columna dice **Oficina**; en condominio, **Vivienda** (con **Área común** para la alberca o el salón).
