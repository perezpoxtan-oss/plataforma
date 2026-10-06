# Firmas autógrafas

Las usan Responsivas, Préstamo de llaves, Lost & Found, Pases de salida, Bitácora de transporte y Accidentes.

## Componente

```blade
@include('componentes.firma', ['id' => 'firma_colaborador', 'nombre' => 'firma', 'etiqueta' => 'Firma del colaborador', 'requerido' => true])
```

- **Captura:** se firma con el dedo, un lápiz o el mouse (pointer events). Al soltar, la imagen pasa al campo oculto como JPEG con fondo blanco; pesa pocos kilobytes, para que el firewall del hosting no rechace el envío.
- **Firma obligatoria:** si está vacía, el formulario no se envía y el recuadro se pone en rojo.
- **Limpiar:** el botón **Limpiar firma** borra el recuadro.
- **Al cerrar el `<dialog>`** que contiene la firma, se borra.
- **Recuadros que aparecen después** (filas dinámicas): se preparan solos al tocarlos, o con `window.Firma.preparar(caja)`.

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
