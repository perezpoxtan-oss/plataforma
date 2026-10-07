# Lector universal y base de inventarios

Decisión y razones: [ADR-0005](../decisiones/ADR-0005-lector-universal-nfc-rfid-qr.md).

## Piezas

| Pieza | Archivo |
|---|---|
| Normalización de lo leído | `app/Support/Lector/Etiqueta.php` |
| Contrato de un registro identificable | `app/Support/Lector/Identificable.php` |
| `codigo_qr` + `etiqueta_nfc` listos | `app/Models/Concerns/TieneIdentificador.php` |
| Búsqueda | `app/Services/Lector/Lector.php` |
| Tipos registrados | `config/lector.php` |
| Endpoints | `app/Http/Controllers/LectorController.php` |
| Componente | `resources/views/componentes/lector.blade.php` + bloque "Lector universal" en `public/js/plataforma.js` |
| Lectura de QR sin `BarcodeDetector` | `public/vendor/jsqr/jsQR.js` (jsQR 1.4.0, Apache-2.0; se carga solo al abrir la cámara) |
| Bajas con voucher | `app/Services/Inventarios/Vouchers.php`, `app/Models/VoucherReposicion.php` |

## Endpoints

| Método y ruta | Qué hace |
|---|---|
| `GET /lector/resolver?entrada=…&tipos=llave,colaborador` | JSON `{resultados: [{tipo, id, titulo, detalle, activo, sede_id, url}]}`, con un máximo de 5 resultados (primero los activos) |
| `GET /e/{codigo}` | Abre la pantalla del registro (`urlLector()`), o 404 |

- Ambos requieren sesión. Sin sesión, `/e/{codigo}` pide entrar y luego continúa.
- Solo buscan en la empresa de trabajo y en los tipos con permiso `permisoLector()`.
- `resolver` tiene un límite de 120 consultas por minuto.

## Cómo se busca

Para cada lectura se arma una lista de candidatos:

1. **Si es una dirección** `/e/{codigo}` o `/qr/{codigo}`, se busca solo por `codigo_qr`.
2. **Si no, se buscan todas las formas del mismo chip.** Se quitan separadores y se pasa a mayúsculas. Si es decimal, se convierte a hexadecimal (normal y con los bytes invertidos); si es hexadecimal de 4 a 7 bytes, a decimal (con y sin ceros a la izquierda). Ejemplo: `00:bc:61:4e` = `0012345678` = `4E61BC00`.
3. **Coincide** si alguno de los candidatos es igual a `etiqueta_nfc`, a `codigo_qr` o a la columna legible del modelo (`$columnaLegible`: `num_empleado`, `placas`, `nomenclatura`…).

## Usar el componente

```blade
{{-- Buscar: guarda el id elegido en "colaborador_id" --}}
@include('componentes.lector', ['id' => 'prestamo_colaborador', 'etiqueta' => 'Colaborador solicitante',
    'tipos' => 'colaborador', 'nombre' => 'colaborador_id', 'requerido' => true])

{{-- Asignar una tarjeta a un registro: guarda lo leído en "etiqueta_nfc" --}}
@include('componentes.lector', ['id' => 'llave_nfc', 'etiqueta' => 'Etiqueta NFC / RFID (opcional)',
    'modo' => 'capturar', 'nombre' => 'etiqueta_nfc', 'valor' => $llave->etiqueta_nfc])
```

- **Eventos:**
  - `lector:elegido`: `detail` es el registro.
  - `lector:capturado`: `detail` es el texto leído.
- **Al editar,** `window.Lector.elegir(caja, {id, titulo, detalle, activo})` llena un lector de búsqueda.
- **Al cerrar** el `<dialog>` que lo contiene, el lector vuelve a su estado inicial.
- **Al asignar una etiqueta,** el servidor normaliza con el trait y valida que no esté ocupada (`Modelo::etiquetaOcupada($valor, $exceptoId)`). El mensaje debe decir qué registro la tiene.

## Búsqueda sin Enter (Ronda 5, LL-04)

En modo **buscar**, el lector consulta solo mientras se escribe: espera **350 ms** sin teclear y al menos **2 caracteres** (`ESPERA_MS`, `MINIMO` en el bloque "Lector universal"). Reglas:

- **Enter** (lo que mandan los lectores USB/Bluetooth tipo teclado al terminar) cancela la espera y busca al momento; pegar también busca al momento.
- Solo cuenta la respuesta de la última búsqueda (`_turnoLector`); si la persona siguió escribiendo, la respuesta vieja se descarta.
- Con un resultado se elige solo; con varios se muestran para tocar; sin resultados, en la búsqueda automática el aviso es informativo («Sin coincidencias… todavía»), y con Enter es el error de siempre.
- El modo **capturar** no busca: solo guarda lo leído.

## Código e identificación (Ronda 5)

Diálogo común «Código e identificación» para **todas** las fichas con QR, en lugar de abrir otra página (petición del dueño en LL-05 y VE-04).

| Pieza | Archivo |
|---|---|
| Botón de la ficha | `resources/views/componentes/boton-identificacion.blade.php` (parámetros con prefijo `ident…`, porque un `@include` hereda las variables de la vista) |
| Diálogo (uno por página) | `resources/views/componentes/codigo-identificacion.blade.php` |
| Servicio | `app/Services/Lector/Identificacion.php` |
| Controlador | `app/Http/Controllers/IdentificacionController.php` |
| Comportamiento y estilos | bloque "Ajustes Ronda 5" de `plataforma.js`, `plataforma.css` y `modos-pantalla.css` |

| Método y ruta | Nombre | Permiso | Qué hace |
|---|---|---|---|
| `GET /identificacion/{tipo}/{id}/qr` | `identificacion.qr` | `<modulo>.ver` | QR en SVG (local, `bacon/bacon-qr-code`) con `/e/{codigo_qr}` |
| `PUT /identificacion/{tipo}/{id}/etiqueta` (`etiqueta_nfc`, vacío = quitar) | `identificacion.etiqueta` | `<modulo>.editar` | Asigna o quita la etiqueta NFC/RFID; JSON `{ok, etiqueta, mensaje}`; 60 por minuto |

- `{tipo}` es la clave de `config/lector.php`; el módulo de permisos sale de `permisoLector()` (`llaves.ver` → `llaves`). Tipo desconocido → 404.
- Empresa de trabajo (tenant), sedes del permiso (el `sede_id` de `resumenLector()`) y alcance «propios» (`creado_por`): fuera de eso → **404**; sin el permiso → **403**.
- La etiqueta se normaliza (`Etiqueta::normalizar`) y no puede estar en **ningún** registro de la empresa de **ningún** tipo registrado (el lector no sabría cuál abrir): «Esa tarjeta o etiqueta NFC/RFID ya está asignada a la llave «HDC-101». Quítala de ahí primero o usa otra.».
- Auditoría: `<modulo>.etiqueta_asignada` / `<modulo>.etiqueta_quitada` con antes y después.
- El diálogo: título y detalle, QR, dirección con **Copiar** (portapapeles o selección), **Imprimir …** (abre la página de impresión del módulo, si hay permiso) y, con permiso de editar, **Asignar etiqueta NFC / RFID** con `componentes.lector` en modo capturar: lo leído con Enter o NFC se guarda solo.
- Fichas que lo usan: Vehículos (Imprimir calcomanía), Llaves (Imprimir etiqueta; se abre solo después de reactivar con `session('identificacion')`), Gafetes, Equipos de seguridad, Equipos de Protección Civil, Colaboradores (sin imprimir), Lost & Found (lista y ficha). Procedimientos aún no existe en `develop`: cuando llegue, solo agrega el botón y el diálogo. Se retiraron los diálogos propios «Ver QR» de Equipos y Equipos PC (las rutas `equipos.qr` y `equipos_pc.qr` siguen existiendo).

```blade
@include('componentes.boton-identificacion', ['identTipo' => 'llave', 'identRegistro' => $l, 'identTitulo' => $l->nomenclatura,
    'identDetalle' => $l->sede?->nombre, 'identImprimir' => route('llaves.imprimir', ['llaves' => [$l->id]]),
    'identImprimirTexto' => 'Imprimir etiqueta', 'identEditable' => $editable])
{{-- …y una vez al final de la página: --}}
@include('componentes.codigo-identificacion')
```

## Agregar un tipo nuevo

1. **Migración:** `codigo_qr` (string 32, único) y `etiqueta_nfc` (string 64, nullable, `unique(['empresa_id','etiqueta_nfc'])`).
2. **Modelo:** `implements Identificable`, `use TieneIdentificador`, `$columnaLegible` opcional, y los métodos `tipoLector()`, `permisoLector()`, `resumenLector()` y `urlLector()`.
3. **Registro:** su línea en `config/lector.php`.
4. **Etiquetas impresas:** el QR codifica `route('lector.ir', $registro->codigo_qr)`. Se genera localmente con `bacon/bacon-qr-code`, como la calcomanía vehicular.
5. **Ficha:** el botón `componentes.boton-identificacion` y el diálogo `componentes.codigo-identificacion` (sección anterior). Agrega el nombre del tipo en `Identificacion::NOMBRES` para el mensaje de etiqueta ocupada.

## Vouchers de reposición (base)

`Vouchers::validar($request->all())` valida el formulario de baja:

- `motivo`: extraviado, dañado o robado;
- `descripcion`;
- `aplica_cobro`;
- `monto` y `colaborador_id`, obligatorios si hay cobro; el colaborador debe ser de la empresa.

`Vouchers::darDeBaja($actor, $origen, 'llave'|'gafete'|'equipo', $descripcion, $datos, fn () => …marcar baja…, $referenciaCosto)`:

- marca la baja y crea el voucher en una sola transacción;
- el folio tiene la forma `VR-aamm-nnnnn`, único;
- recuerda el costo para sugerirlo después (`costoSugerido()`);
- audita `vouchers.creado`.

La pantalla de Vouchers y la impresión triple viven en su propio módulo.

## Qué se corrigió respecto a SEGCAT

- **Web NFC (solo Android)** deja de ser la única vía para NFC: se suman lectores en modo teclado (PC, Mac, iPhone, iPad) y QR con cámara también en iPhone.
- **jsQR** ya no se carga de un CDN en cada pantalla: se aloja en la plataforma y solo se descarga al abrir la cámara.
- **El número de serie** de la tarjeta se guarda normalizado y se reconoce aunque el lector lo entregue en otro formato.
- **Los QR** dejan de llevar consecutivos adivinables (`id`) y ya no se generan en `api.qrserver.com`, que filtraba datos a terceros.
- **La baja con voucher** era código repetido en cada módulo. Ahora es un servicio con transacción y auditoría.

## Ronda 6

- **GV-03**: el componente acepta `'duplicado' => route('identificacion.etiqueta-duplicado', ['<tipo>', 0])` en modo capturar: el aviso «ya la tiene X» sale en vivo debajo del lector al teclear o al leer la tarjeta (ver `avisos-duplicado.md`). Lo usan las ediciones de Llaves, Gafetes, Equipos y Equipos PC y el diálogo «Código e identificación».
- **LL-06**: la impresión masiva de etiquetas de todos los tipos está en Padrones → **Etiquetas QR** (ver `etiquetas-qr.md`). Un tipo nuevo de `config/lector.php` aparece ahí solo.
- El título de los equipos de seguridad es «Serie: …» (EQ-04).
