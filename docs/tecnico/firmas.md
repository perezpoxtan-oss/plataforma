# Firmas autógrafas

Las usan Responsivas, Préstamo de llaves, Lost & Found, Pases de salida, Bitácora de transporte y Accidentes.

## Componente

```blade
@include('componentes.firma', ['id' => 'firma_colaborador', 'nombre' => 'firma', 'etiqueta' => 'Firma del colaborador', 'requerido' => true])
```

- **Captura:** se firma con el dedo, un lápiz o el mouse (pointer events). Al soltar, la imagen pasa al campo oculto como JPEG con fondo blanco; pesa pocos kilobytes. Al enviar, viaja como archivo (ver «Envío como archivo»).
- **Firma obligatoria:** si está vacía, el formulario no se envía y el recuadro se pone en rojo.
- **Limpiar:** el botón **Limpiar firma** borra el recuadro.
- **Al cerrar el `<dialog>`** que contiene la firma, se borra.
- **Recuadros que aparecen después** (filas dinámicas): se preparan solos al tocarlos, o con `window.Firma.preparar(caja)`.

## Envío como archivo (`__firmas[]`)

**Por qué.** En el hospedaje compartido, el firewall (ModSecurity / Imunify360) bloquea o redirige los envíos cuyo cuerpo `application/x-www-form-urlencoded` trae un texto largo `data:image/...;base64,...`: el servidor recibía la dirección del envío como página (GET), sin datos, y la firma no se guardaba (acuse «Leí y entendí» en el celular; antes el expediente del Accidente, NV-03). En local con Playwright todo guardaba bien. SEGCAT enviaba la firma como **archivo** (`firma_archivo`, multipart) y eso sí pasa el firewall.

**Navegador (`public/js/plataforma.js`, bloque «Firma autógrafa»).**

- Todo formulario POST que contiene un recuadro de firma se marca `enctype="multipart/form-data"` al prepararse el recuadro y otra vez al enviarse (no cambia nada visible).
- En el evento **`formdata`** (se dispara al armar los datos del formulario: envío normal, `form.submit()` y `new FormData(form)` de los envíos por `fetch`), cada valor `data:image/(png|jpeg);base64,...` —el campo oculto del componente o cualquier otro, como `f_afectado` del Accidente o `taxis[0][firma]` de Transporte— se convierte en un `File` y se agrega como `__firmas[]`, con el nombre de su campo en `__firmas_campos[]`; el campo original va vacío. Las validaciones de siempre (firma obligatoria vacía, NV-03) corren antes, en el evento `submit`, sobre el campo oculto, que no se modifica.
- Se usa `formdata` y no un `<input type="file">` con `DataTransfer` porque así no depende del orden de los manejadores de `submit` y cubre también los envíos por `fetch` y `form.submit()` sin tocarlos uno por uno.
- Si el navegador no tiene `FormDataEvent` (navegadores muy viejos), la firma se envía como antes, como texto.
- La imagen ya es ligera: el lienzo mide 600 × 200 sin multiplicar por la densidad de pantalla y se exporta como JPEG con fondo blanco, calidad 0.7 (unos 5–15 KB).

**Servidor (`App\Http\Middleware\FirmasComoArchivo`, grupo `web`, antes de los controladores).**

- Por cada par `__firmas[i]` / `__firmas_campos[i]` (máximo 20): el archivo debe ser una subida válida de 1 byte a 1 MB y una imagen **PNG o JPEG real** (`getimagesizefromstring`, la misma revisión de tipo que `Firmas`); el nombre de campo solo letras, números, `_` y `-`, con hasta 4 niveles `[...]` y sin empezar con `_` (nunca `_token` ni `_method`).
- Si es válido, se vuelve a poner en la petición como `data:image/<tipo>;base64,...` en su campo original (`taxis[0][firma]` → `taxis.0.firma`). `Firmas::guardar()` hace después todas sus revisiones (300 KB, dimensiones, re-dibujo con GD).
- Si no es válido, no se inyecta nada: el controlador da su error normal («Falta la firma…»).
- `__firmas` y `__firmas_campos` se quitan de la entrada y de los archivos.
- No registra nada.
- El envío de siempre con el texto en el campo sigue funcionando (compatibilidad).

**Aviso si un envío no llega.** Si aun así el servidor recibe como página una dirección que solo sirve para enviar, `App\Support\RegresoTrasEnvio` regresa a la pantalla de origen con `?envio=incompleto` (antes del `#ancla`) y `layouts/app.blade.php` muestra la franja «Tu envío no llegó completo y no se guardó. Revisa los datos y vuelve a guardar. Si se repite, avisa a tu administrador con la hora.». En el registro del servidor («Dirección de envío abierta como página (405)») quedan la ruta, de dónde venía, el navegador y el `Content-Type` / `Content-Length` recibidos (nunca el cuerpo), para cruzarlo con la hora que reporte el usuario.

Pruebas: `tests/Feature/Nucleo/FirmasComoArchivoTest.php` (middleware y aviso) y, con el ayudante `Tests\Feature\EnviaFirmasComoArchivo`, el acuse de Procedimientos, el expediente del Accidente, las firmas de caseta de Pases de salida y las firmas anidadas de los taxis en Transporte.

## Servidor (`App\Services\Firmas\Firmas`)

- **`guardar($dataUrl, 'carpeta', 'campo', 'la firma del …')`:**
  - Valida que sea JPEG o PNG real, de hasta 300 KB, y que no esté vacía. Si no, lanza un error de validación en ese campo, con un mensaje claro.
  - No guarda los bytes tal como llegaron: la imagen se vuelve a dibujar con GD (`App\Support\ImagenSegura`), lo que descarta código pegado a la imagen y metadatos.
  - La guarda en el disco **privado** `local`, en `firmas/<empresa>/<carpeta>/<año>/<mes>/<uuid>.jpg`.
  - Devuelve la ruta para guardarla en la base.
- **`viene($dataUrl)`:** dice si llegó una firma, para las opcionales.
- **`respuesta($ruta)`:** muestra la imagen. Solo entrega firmas de la empresa activa. **Antes, el controlador del módulo revisa el permiso y el alcance de sede del registro.**
- **`borrar($ruta)`.**

## Qué se corrigió respecto a SEGCAT

En SEGCAT las firmas se guardaban en `uploads/`, dentro de la carpeta pública, con nombres predecibles: cualquiera que adivinara la dirección podía verlas. Además, no se comprobaba que el archivo fuera una imagen.
