# Lección 37 · El Manual dentro de la plataforma

**Duración:** 15 minutos · **Para:** todos (caseta, supervisores, Recursos Humanos, administradores) y quien escribe el manual.

Aprenderás:

1. A abrir el manual y buscar una página.
2. A usar el botón **?** de cada pantalla.
3. Por qué cada usuario ve páginas distintas.
4. (Quien escribe el manual) Cómo agregar o cambiar una página sin romper nada.

## Antes de empezar

Usa el demo (`php artisan plataforma:demo`), contraseña `Demo1234!`. Usuarios: **agente.demo**, **admin.demo**, **rh.demo**.

## Práctica 1 · Abrir y buscar (agente.demo, computadora)

1. Arriba, junto a la campana, toca el botón **?**. Se abre **Manual**.
2. Fíjate en los grupos: **Primeros pasos**, **Operación**, **Padrones**… No aparece **Estructura**: el Agente no usa esas pantallas.
3. Escribe «llave» en el buscador: quedan **Préstamo de llaves** (y Catálogo de llaves, si ya está su página).
4. Abre **Préstamo de llaves**. Usa **En esta página** para saltar a «Cómo recibir una llave».
5. Toca **Imprimir**: la hoja sale sin menús.

## Práctica 2 · El botón «?» de cada pantalla (agente.demo)

1. Entra a **Operación → Caseta → Bitácora de accesos**.
2. Junto al título **Control de Accesos** toca el **?** redondo: se abre la página **Bitácora de accesos**.
3. Repite en **Novedades** y **Procedimientos**.

## Práctica 3 · Cada quien ve lo suyo (admin.demo y rh.demo)

1. Entra como **admin.demo** y abre el manual: aparecen más grupos (Estructura, Recursos Humanos).
2. Entra como **rh.demo**: ve Recursos Humanos y Procedimientos, pero no las páginas de la caseta que no usa.
3. En una página general (por ejemplo **Primeros pasos**), los enlaces a páginas que no puedes ver salen como texto normal.

## Práctica 4 · En el celular, Sol y Noche (agente.demo)

1. En el celular toca **Menú → Ayuda → Manual**.
2. Abre una página: **En esta página** viene cerrado; tócalo para ver la lista.
3. Cambia a **Sol** y **Noche** con el botón del sol: el manual se adapta.

## Práctica 5 · Escribir o cambiar una página (quien mantiene el manual)

1. Lee **docs/usuario/GUIA.md**: es obligatoria (estructura, redacción y lo que está prohibido).
2. Cada página empieza con su encabezado:

   ```
   ---
   titulo: Préstamo de llaves
   modulos: [prestamo_llaves]
   seccion: Operación
   orden: 20
   resumen: Prestar una llave a un colaborador con su garantía y recibirla cuando la regresa.
   ---
   ```

   `modulos` son las claves del catálogo: la página se muestra a quien puede **ver** alguno. `[]` = para todos.
3. Las capturas van en `docs/usuario/img/<carpeta>/` y se citan como `![Descripción](img/<carpeta>/<archivo>.png)`. Tómalas con usuarios y empresa de ejemplo, sin la franja de ambiente.
4. Los enlaces entre páginas se escriben `[Préstamo de llaves](prestamo-llaves.md)`. Los de internet no se publican.
5. Corre `php artisan test --filter=ManualTest`. Falla si: falta el encabezado, un módulo no existe, una imagen no está, un enlace apunta a una página que no existe, un módulo con pantalla no tiene página, o la página tiene datos prohibidos (dominios, usuarios de prueba, «migración»…).

**Regla del proyecto (CLAUDE.md):** toda pantalla nueva o cambio visible actualiza su página en `docs/usuario/`.

## Para el técnico

- Ver `docs/tecnico/manual.md`: Markdown seguro (sin HTML), caché por fecha del archivo, imágenes solo de `docs/usuario/img`, `docs/usuario` viaja en el paquete de despliegue.

## Comprueba lo aprendido

- ¿Por qué el Agente no ve la página de Usuarios? → Porque su rol no puede ver ese módulo.
- ¿Dónde está la ayuda de la pantalla en la que estoy? → En el **?** junto al título.
- ¿Qué pasa si subo una página con una imagen que no existe? → La prueba `ManualTest` falla y la imagen no se muestra.
