# Módulo Manual (ayuda dentro de la plataforma)

El manual de usuario (`docs/usuario/*.md`) se publica dentro de la plataforma. Cada usuario ve **solo las páginas de lo que puede usar**: una página se muestra si el usuario puede `ver` alguno de los módulos de su encabezado, o si es general (`modulos: []`).

## Piezas

| Pieza | Archivo |
|---|---|
| Servicio (lectura, filtro, Markdown seguro, caché, imágenes) | `app/Services/Manual/Manual.php`, `PaginaManual.php` |
| Controlador | `app/Http/Controllers/ManualController.php` |
| Vistas | `resources/views/manual/index.blade.php`, `ver.blade.php` |
| Menú y botón «?» | `resources/views/layouts/app.blade.php` (bloques «Manual (lección 37)») |
| JS | bloque «Manual (lección 37)» al final de `public/js/plataforma.js` (coloca el «?», búsqueda en vivo, índice plegado en el celular) |
| CSS | bloque «Manual» al final de `public/css/plataforma.css` y de `public/css/modos-pantalla.css` |
| Guía de redacción (obligatoria) | `docs/usuario/GUIA.md` (no es página del manual) |
| Pruebas | `tests/Feature/Seguridad/ManualTest.php` |

No hay tablas nuevas ni migración: las páginas son archivos del repositorio.

## Encabezado de cada página (front matter)

```
---
titulo: Bitácora de accesos
modulos: [accesos]
seccion: Operación
orden: 10
resumen: Registrar quién entra y sale de la sede.
---
```

- `modulos`: claves del catálogo (`modulos.clave`). `[]` = página general para todos.
- `seccion`: `Primeros pasos`, `Operación`, `Padrones`, `Recursos Humanos`, `Informes` o `Estructura` (en ese orden en el índice).
- Las claves son exactamente esas cinco; el lector es estricto (no hay `symfony/yaml` en el proyecto). Una página sin encabezado válido **no se muestra**.
- El slug es el nombre del archivo (`[a-z0-9-]+`). `GUIA.md` se excluye.

## Rutas y permisos

| Método | Ruta | Nombre | Permiso |
|---|---|---|---|
| GET | `/manual` | `manual.index` | Toda sesión (`?q=` filtra sin JavaScript) |
| GET | `/manual/{pagina}` | `manual.ver` | Toda sesión; 404 si la página no existe o el usuario no puede ver ninguno de sus módulos |
| GET | `/manual/img/{carpeta}/{archivo}` | `manual.imagen` | Toda sesión; solo imágenes dentro de `docs/usuario/img` |

El módulo `manual` existe en el catálogo (área Dirección, ruta `manual.index`) como **tipo plataforma**: no se contrata por empresa ni aparece en la Matriz de permisos, porque no tiene permiso propio (decisión del dueño: «que solo los puedan ver acorde a lo que le toca a cada usuario»). El filtro es por página, con el motor de permisos (`$usuario->can('<modulo>.ver')`), así que respeta módulos contratados, roles y superadministrador. No hay datos de empresa: no aplica alcance de sede ni auditoría de lectura.

## Markdown seguro

`league/commonmark` (ya viene con Laravel) con `html_input = escape` y `allow_unsafe_links = false`, extensiones núcleo + tablas + tachado. Después de convertir se recorre el árbol:

- **Enlaces**: solo `otra-pagina.md[#apartado]` y `#apartado`. Lo demás (internet, rutas del servidor, `javascript:`) queda como texto. Los enlaces a páginas se resuelven **por usuario**: si la página no existe o no la puede ver, queda como `<span class="manual-enlace-sin-acceso">`.
- **Imágenes**: solo `img/<carpeta>/<archivo>.(png|jpg|jpeg|gif|webp)` que existan; se reescriben a `manual.imagen` con `loading="lazy"`. Las demás se quitan.
- **Títulos** `##`/`###` reciben `id` (slug) y los `##` forman el índice «En esta página». El primer `# Título` se quita (la pantalla ya lo muestra).
- Tablas con clase `manual-tabla` (se desplazan de lado en el celular).

**Caché**: la conversión se guarda con la clave `manual.pagina.<md5(ruta|mtime|tamaño)>` y el índice con la huella de todos los archivos; cambiar un archivo invalida su caché. Los enlaces por usuario se resuelven en cada visita sobre el HTML guardado.

## Imágenes

`Manual::rutaImagen()` valida el nombre con expresión regular, usa `realpath` y exige que quede dentro de `docs/usuario/img`. El controlador además compara el tipo real del archivo (`finfo`) con la extensión. Respuesta con `Cache-Control: private, max-age=604800`, `ETag`, `Last-Modified`, `X-Content-Type-Options: nosniff` y `Content-Security-Policy: default-src 'none'; sandbox`. Límite de 600 peticiones por minuto.

## Despliegue

El paquete (`.github/workflows/paquete.yml`) ya no excluye todo `docs/`: viaja `docs/usuario` (sin `GUIA.md`) y se excluye el resto de `docs/`.

## La regla «todo cambio actualiza el manual»

- `CLAUDE.md`: «Toda pantalla nueva o cambio visible actualiza su página en docs/usuario/ siguiendo docs/usuario/GUIA.md».
- Pruebas en `ManualTest`:
  - toda página tiene encabezado válido y sus módulos existen en el catálogo;
  - todo módulo del catálogo con pantalla (`ruta`) está en los `modulos` de alguna página (excepción documentada: `manual`, explicado en las páginas generales);
  - toda imagen referida existe y todo enlace `.md` apunta a una página que existe;
  - ninguna página contiene textos prohibidos (`vdcp`, `qa.`, `neubox`, `cpanel`, `github`, el nombre o correo del autor, `.demo`, `Demo1234`, el nombre del sistema anterior, `ronda`, `PR #`, `migración`).
- **TODO del integrador**: `ManualTest::PENDIENTES_OTRA_RAMA` tolera las páginas de Padrones, Recursos Humanos y Estructura que reescribe la rama `feature/manual-paginas`; al integrarla, borra esa lista.

## Botón «?» de cada pantalla

El layout toma el módulo activo del menú (`ConstructorMenu`) y busca `Manual::paginaDeModulo()` (la página que nombra primero ese módulo, después la de menor `orden`). Si existe y el usuario la ve, deja un enlace oculto `[data-ayuda-contextual]`; `plataforma.js` lo coloca al final del `h1` de `.encabezado-pantalla`. Las pantallas sin ese encabezado no muestran el «?» (el botón del Manual del encabezado siempre está).

## Qué se corrigió respecto al sistema anterior

El sistema anterior no tenía ayuda dentro de la aplicación: los instructivos eran documentos sueltos, iguales para todos y sin imágenes actualizadas. Ahora:

- La ayuda vive en la misma plataforma, filtrada por permisos (un agente no ve cómo administrar usuarios).
- Cada pantalla enlaza a su página («?»).
- Las páginas siguen una estructura fija a prueba de errores (para qué sirve, antes de empezar, pasos numerados, qué debes ver, si algo sale mal, preguntas frecuentes).
- Las pruebas impiden publicar un manual con imágenes rotas, módulos sin página o datos de infraestructura.
- Las capturas usan usuarios y empresa de ejemplo (sin dominios, usuarios de prueba ni la franja de ambiente).
