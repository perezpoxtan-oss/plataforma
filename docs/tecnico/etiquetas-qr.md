# Etiquetas QR (impresión masiva)

Ronda 6 de ajustes de QA, observación **LL-06**. Padrones → Inventarios de Seguridad → **Etiquetas QR** (`/etiquetas`). Imprime en bloque las etiquetas QR de **todo lo que se identifica con el lector universal** (`config/lector.php`): llaves, gafetes, equipos de seguridad, equipos de Protección Civil, vehículos, colaboradores, Lost & Found y procedimientos. Un tipo nuevo que se registre en `config/lector.php` aparece solo.

La etiqueta de **un solo** registro sigue en su diálogo «Código e identificación» (ver `docs/tecnico/lector.md`).

## Piezas

| Pieza | Qué hace |
|---|---|
| `App\Services\Lector\EtiquetasMasivas` | Tipos que el usuario puede imprimir, lista filtrada, hoja de impresión (QR en SVG dibujado en el servidor) y tamaños. |
| `App\Http\Controllers\Padrones\EtiquetaController` | `index` y `imprimir`. |
| `resources/views/padrones/etiquetas/index.blade.php` | Píldoras por tipo con conteo, búsqueda, sede, estado, «Marcar todas», tamaño e «Imprimir N». |
| `resources/views/padrones/etiquetas/imprimir.blade.php` | Página propia (sin menús) con la cuadrícula de etiquetas en milímetros. |
| Bloque «Ajustes Ronda 6» de `plataforma.js` | Marcar todas, conteo, botón deshabilitado con 0 o más de 200. |
| Bloque «Ajustes Ronda 6» de `plataforma.css` / `modos-pantalla.css` | Lista, barra fija, hoja y tamaños; Sol y Noche. |

## Endpoints

| Ruta | Nombre | Permiso |
|---|---|---|
| `GET /etiquetas?tipo=&sede=&estado=activos\|todos&q=` | `etiquetas.index` | `etiquetas_qr.ver` |
| `POST /etiquetas/imprimir` (`sel[]=llave-12`, `plantilla=ID` o `tamano=llavero…`) | `etiquetas.imprimir` | `etiquetas_qr.ver` + los del tipo de cada etiqueta. **Ronda 7: POST** (registra la impresión) |

Sin nada marcado, `imprimir` regresa a la lista con «Marca al menos un registro para imprimir sus etiquetas.». Si nada de lo marcado es visible (otra empresa, otra sede, sin permiso): 404. Máximo 200 etiquetas por impresión (`EtiquetasMasivas::MAXIMO`); la lista muestra hasta 500 por tipo (`POR_TIPO`).

## Permisos

- **Entrar**: `etiquetas_qr.ver` (módulo nuevo `etiquetas_qr`, área Seguridad, solo acción «ver»; menú Padrones → Inventarios de Seguridad). Las plantillas lo dan como cualquier «ver» de Padrones (también al Agente).
- **Cada tipo** pide además su propio permiso: `<módulo>.ver` **y** `<módulo>.imprimir`; si el módulo no tiene la acción «imprimir» (Colaboradores, Procedimientos), `<módulo>.editar`. El permiso del módulo sale de `permisoLector()` del modelo; la acción se averigua en el catálogo (`modulo_acciones`).
- **Sedes**: los registros se limitan a las sedes de **ambos** permisos (los que no son de una sede —corporativos— se ven, como en «Código e identificación»). Con alcance «propios», solo los que dio de alta.
- Ejemplo con las plantillas: el **Agente** entra y solo ve **Lost & Found** (tiene `lost_found.imprimir`; en Llaves, Gafetes, Equipos y Vehículos solo consulta).

## Instalaciones existentes

Migración `2026_10_13_000100_ajustes_ronda_6`: en una base que ya tiene catálogo crea el módulo `etiquetas_qr` (ruta `etiquetas.index`, al final de Inventarios de Seguridad), lo activa en las empresas que tenían algún padrón con etiquetas y da `etiquetas_qr.ver` a cada rol que ya tenía `llaves|gafetes|equipos|equipos_pc|vehiculos|lost_found.imprimir` (mismo alcance). En una instalación nueva lo crean `CatalogoSeeder` y `MenuSeeder`.

## Tamaños (`EtiquetasMasivas::TAMANOS`)

| Clave | Nombre | Medida | Para qué |
|---|---|---|---|
| `llavero` | Llavero pequeño | 40 × 25 mm | Llaveros y micas chicas (preelegido en Llaves) |
| `etiqueta` | Etiqueta 50 × 25 mm | 50 × 25 mm | Impresoras de etiquetas comunes (por omisión) |
| `gafete` | Gafete | 86 × 54 mm | Tamaño credencial (preelegido en Gafetes) |
| `calcomania` | Calcomanía vehicular | 100 × 70 mm | Parabrisas (preelegido en Vehículos) |

Cada etiqueta lleva: QR (solo la dirección `/e/{código}`, ningún dato del registro; sin servicios externos), nombre de la empresa, **título** del registro (`resumenLector()['titulo']`: nomenclatura, placas, «Serie: …», nombre…), el tipo y el **código legible** en grupos de 4 para teclearlo si el QR no se puede leer. Los dados de baja se marcan «DE BAJA».

## Sin N+1

Cada tipo carga de una vez las relaciones que usa su `resumenLector()` (`EtiquetasMasivas::CARGAR`). La búsqueda compara el título, el detalle y el código ya armados.

## Qué se corrigió respecto a SEGCAT

- SEGCAT solo imprimía etiquetas en bloque de llaves (`llave_imprimir.php`) y una por una en los demás módulos; el QR se pedía a `api.qrserver.com`. Ahora es una sola pantalla para todos los tipos, con QR dibujado en el servidor y permisos por tipo y sede.

## Pruebas

`tests/Feature/Seguridad/AjustesRonda6Test.php` (`test_ll06_*`): tipos según permisos, sede del Jefe, Agente, filtros, búsqueda, hoja de impresión, otra empresa (no aparece / 404), sin selección y tamaño desconocido.

## Ronda 7: gestor centralizado de impresión

Petición del dueño del proyecto: un solo lugar para imprimir **todas** las etiquetas QR, con plantillas para impresoras térmicas (Zebra, Brother…) y hojas carta/A4, filtros por fecha, tipo y estatus, e **historial con reimpresión**.

### Tablas (migración `2026_10_14_000100_gestor_impresion_etiquetas`)

| Tabla | Columnas principales |
|---|---|
| `etiquetas_plantillas` | `empresa_id`, `sede_id` (nulo = toda la empresa), `clave` (`llavero`, `etiqueta`, `gafete`, `calcomania` = las de siempre), `nombre` (único por empresa), `formato` (`rollo` \| `hoja`), `papel` (`carta` \| `a4`), `ancho_mm`, `alto_mm`, `margen_superior_mm`, `margen_izquierdo_mm`, `separacion_horizontal_mm`, `separacion_vertical_mm`, `columnas`, `filas`, `orientacion` (`horizontal` = QR a la izquierda \| `vertical` = QR arriba), `qr_mm`, `mostrar_titulo`, `mostrar_codigo`, `mostrar_tipo`, `mostrar_ubicacion`, `mostrar_fecha`, `mostrar_logo`, `activo`, auditoría |
| `impresiones_etiquetas` | `empresa_id`, `sede_id` (la de todas sus etiquetas; nulo si son de varias), `plantilla_id`, `plantilla_nombre`, `cantidad`, `reimpresion_de_id`, `creado_por` (quién imprimió), `created_at` (cuándo) |
| `impresiones_etiquetas_items` | `empresa_id`, `impresion_id`, `tipo` (de `config/lector.php`), `registro_id`, `titulo` (como se veía al imprimir), `sede_id`, `orden` |

Modelos `EtiquetaPlantilla`, `ImpresionEtiquetas`, `ImpresionEtiquetasItem` (todos con `PerteneceAEmpresa`). Servicios `App\Services\Lector\PlantillasEtiquetas` y `App\Services\Lector\ImpresionesEtiquetas`.

### Endpoints nuevos

| Ruta | Nombre | Permiso |
|---|---|---|
| `GET /etiquetas/historial?desde&hasta&usuario&plantilla&q` | `etiquetas.historial` | `etiquetas_qr.ver` |
| `GET /etiquetas/impresiones/{impresion}` | `etiquetas.impresion` | `etiquetas_qr.ver` + tipo de cada etiqueta (otra empresa/sede: 404) |
| `POST /etiquetas/impresiones/{impresion}/reimprimir` (`sel[]` opcional, `plantilla` opcional) | `etiquetas.reimprimir` | ídem |
| `GET /etiquetas/plantillas` | `etiquetas.plantillas` | `etiquetas_qr.configurar` |
| `GET /etiquetas/plantillas/nueva` · `POST /etiquetas/plantillas` | `etiquetas.plantillas.create` / `.store` | `etiquetas_qr.configurar` |
| `GET /etiquetas/plantillas/{plantilla}/editar` · `PUT /etiquetas/plantillas/{plantilla}` | `.edit` / `.update` | `etiquetas_qr.configurar` + alcance |
| `PATCH /etiquetas/plantillas/{plantilla}/estado` (`activo=0\|1`) | `.estado` | ídem |
| `GET /etiquetas/plantillas/{plantilla}/prueba` | `.prueba` | ídem (no queda en el historial) |

`GET /etiquetas` acepta además `desde`, `hasta` (fecha de **alta**, días en la hora local; `EtiquetasMasivas::rangoAlta`), `estado=activos|baja|todos` y `tipo` (también como lista).

### Permisos

- Acción nueva **`etiquetas_qr.configurar`** (sub-pantalla Plantillas). En una instalación nueva la crean `CatalogoSeeder` (línea de `etiquetas_qr`) y `RolesPlantillaSeeder::reglaEtiquetasQr` (bloque delimitado «Etiquetas QR (Ronda 7)»): **Administrador** y **Director** con alcance de empresa, **Jefe de seguridad** con alcance de sede; nadie más (el Supervisor no, aunque la regla general de Seguridad se lo daría). En una base existente la migración agrega la acción, la da a los roles con esos nombres (plantillas y roles de cada empresa) y crea las 4 plantillas de siempre en cada empresa.
- **Plantillas de toda la empresa** (`sede_id` nulo): solo las cambia quien configura con alcance de empresa; el Jefe las ve («solo la cambia quien administra toda la empresa») y las usa. **De una sede**: las cambia quien configura esa sede; de otra sede: 404.
- **Al imprimir** se ofrecen las activas de toda la empresa y de las sedes del usuario (`etiquetas_qr.ver`).
- **Historial**: alcance de empresa = todas; de sede = las de sus sedes y las suyas; «propios» = las suyas. Siempre solo las que traen etiquetas de algún tipo que el usuario puede imprimir (y solo esos títulos). Ver la hoja o reimprimir vuelve a revisar cada etiqueta con `EtiquetasMasivas::paraImprimir()`.

### Reglas

- Las 4 de siempre se crean solas la primera vez que una empresa abre Etiquetas QR (`PlantillasEtiquetas::asegurar()`; empresas nuevas) y la migración las crea en las existentes. Se pueden ajustar o desactivar; no se borran. No se puede desactivar la **última activa de toda la empresa**.
- Validación en mm con mensajes que dicen qué cambiar: QR mínimo 8 mm y que quepa (`min(ancho, alto) − 3`), espacio para el texto según la orientación, al menos nombre o código visibles, nombre único por empresa y que la planilla quepa en la hoja (`margen + columnas × ancho + separaciones ≤ ancho de la hoja`, igual a lo largo). Acepta coma decimal.
- **Hoja de impresión** (`padrones/etiquetas/imprimir.blade.php`): `@page { size: … }` en mm desde la plantilla (en un `<style>`, permitido por la CSP; ningún script en línea). Rollo: cada etiqueta es una página del tamaño de la etiqueta (+ avance). Hoja: páginas carta/A4 con la rejilla `columnas × filas`, márgenes y separaciones. Los tamaños de letra salen de `PlantillasEtiquetas::medidasTexto()` (alto y ancho libres).
- **Se recuerda la última plantilla** de cada usuario (la de su impresión más reciente); con un tipo filtrado se preelige la de siempre de ese tipo (llavero, gafete, calcomanía), como en la Ronda 6.
- «Imprimir» es un **POST** que registra la impresión y redirige a su hoja (otra pestaña). Volver a abrir la misma hoja no crea otra impresión; **Reimprimir** sí (con `reimpresion_de_id`).
- Auditoría: `etiquetas_qr.impreso`, `etiquetas_qr.reimpreso` (con `reimpresion_de`), `etiquetas_qr.plantilla_creada|plantilla_actualizada|plantilla_desactivada|plantilla_reactivada` (`LectorAuditoria::REGISTROS` los nombra).

### JS y CSS

Bloque «Ajustes Ronda 7» al final de `plataforma.js` (descripción de la plantilla elegida, diálogo Reimprimir, campos de la hoja según el formato sin borrar lo escrito y vista previa a escala con aviso «no cabe») y de `plataforma.css` / `modos-pantalla.css` (Sol y Noche).

### Qué se corrigió respecto a SEGCAT

- SEGCAT tenía tamaños fijos en cada pantalla de impresión y no guardaba qué se imprimía. Ahora las medidas son plantillas configurables por empresa/sede, hay hoja de prueba para calibrar, historial de quién imprimió qué y reimpresión de solo las etiquetas dañadas.

### Pruebas

`tests/Feature/Seguridad/EtiquetasQrTest.php` (plantillas de siempre, permisos por rol, alta/edición/desactivar/reactivar con auditoría, validación de medidas, alcance de sede y empresa, última activa, impresión con historial y `@page`, permisos del historial, reimprimir todas o algunas, hoja de prueba, filtros por fecha, tipo y estatus). `AjustesRonda6Test` pasó a usar el POST.
