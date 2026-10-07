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
| `GET /etiquetas/imprimir?sel[]=llave-12&sel[]=vehiculo-3&tamano=etiqueta` | `etiquetas.imprimir` | `etiquetas_qr.ver` + los del tipo de cada etiqueta |

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
