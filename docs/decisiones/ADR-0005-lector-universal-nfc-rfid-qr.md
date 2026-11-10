# ADR-0005 — Lector universal: QR, NFC, RFID y código de barras

**Fecha:** 2026-10-05 · **Estado:** aceptada

## Contexto

SEGCAT identifica llaves, gafetes, colaboradores, equipos y puntos de recorrido de tres formas: QR con la cámara (jsQR), el NFC del celular (Web NFC) y tecleando. Web NFC **solo existe en Chrome para Android**. No funciona en ningún navegador de PC (Chrome, Edge, Firefox, Safari) ni en ningún navegador de iPhone o iPad, porque en iOS todos usan el motor de Safari y Apple no lo implementa.

El responsable pidió que los lectores funcionen también en el navegador de PC y, de ser posible, en iPhone.

Dos datos técnicos marcan la decisión:

- **"RFID" son dos tecnologías:**
  - **125 kHz** (tarjetas de proximidad EM4100/HID Prox, las más baratas): ningún celular las lee, solo un lector externo.
  - **13.56 MHz** (NFC: MIFARE, NTAG, DESFire): las leen los celulares y los lectores externos.
- **El iPhone** lee NFC solo desde una app nativa (Core NFC). Hay dos excepciones:
  - **Lectura en segundo plano** (iPhone XS y posteriores): si la etiqueta trae una dirección web, el iPhone la abre en Safari sin ninguna app.
  - **Lector externo Bluetooth** en modo teclado: Safari lo recibe como si se tecleara.
  - Core NFC **no lee MIFARE Classic** (lo más común en tarjetas de hotel). Sí lee NTAG/Ultralight, DESFire e ISO 15693.

## Decisión

1. **Un solo componente, "lector universal"** (`componentes/lector.blade.php` + bloque en `plataforma.js`). Acepta todas las entradas en el mismo campo:
   - **Lectores USB o Bluetooth en modo teclado (HID).** Funcionan en cualquier navegador y sistema: PC, Mac, Android, iPhone y iPad. Escriben el número y un Enter, y el componente busca en lugar de enviar el formulario. **Esta es la vía para PC.**
   - **Cámara.** Usa `BarcodeDetector` cuando el navegador lo trae y, si no (iPhone, Firefox), `jsQR` alojado en la plataforma (sin CDN). Funciona en iPhone desde Safari.
   - **NFC del celular** (Web NFC). El botón aparece solo donde funciona: Android con Chrome.
   - **Teclear** la nomenclatura, las placas o el número de empleado.
2. **Un solo endpoint**, `GET /lector/resolver?entrada=…&tipos=…`, resuelve lo leído contra los módulos registrados en `config/lector.php`:
   - siempre dentro de la empresa de trabajo;
   - solo en los tipos que el usuario puede ver.
3. **Cada registro identificable tiene dos datos:**
   - `codigo_qr`: aleatorio, de 24 caracteres. Va en el QR y se puede grabar en la etiqueta NFC.
   - `etiqueta_nfc`: número de serie del chip o tarjeta asignada; opcional y único por empresa.
4. **El mismo chip puede llegar escrito de varias formas** según el lector: hexadecimal con dos puntos (Web NFC), decimal de 10 dígitos (lectores de 125 kHz) o con los bytes invertidos. Se guarda normalizado y al buscar se prueban todas las formas equivalentes (`App\Support\Lector\Etiqueta`).
5. **Los QR y las etiquetas NFC llevan una dirección**, `https://<plataforma>/e/{codigo}`, y no datos personales. Con ella:
   - el iPhone abre la pantalla del registro al acercar la etiqueta, sin app;
   - cualquier celular lo hace al escanear el QR con su cámara;
   - el lector universal extrae el código de la dirección.
6. **Las apps móviles (pendientes) reutilizarán el mismo endpoint.** La app de iPhone leerá NFC con Core NFC y entregará el texto al mismo componente: el servidor no cambia.

## Etiquetas y lectores recomendados

| Para | Recomendado | Por qué |
|---|---|---|
| Etiquetas nuevas (llaveros, gafetes, equipos, puntos de recorrido) | **NTAG215/216** con la dirección `/e/{codigo}` grabada | Las leen Android (Web NFC), iPhone (sin app y con app) y los lectores USB |
| PC de caseta | Lector USB **13.56 MHz en modo teclado** (y uno de 125 kHz si ya hay tarjetas de proximidad) | No requieren controladores ni software |
| iPhone / iPad sin app | Cámara (QR) o lector **Bluetooth en modo teclado** | Safari no tiene Web NFC |
| Tarjetas que ya existen (MIFARE Classic, 125 kHz) | Se registran con su número de serie en `etiqueta_nfc` | El iPhone no las lee con su antena; un lector externo sí |

## Consecuencias

- El iPhone **no** podrá leer con su propia antena, desde el navegador, tarjetas que no traigan dirección. Para eso hace falta la app (Core NFC) o un lector Bluetooth. MIFARE Classic y 125 kHz requieren lector externo siempre.
- Los módulos nuevos que se identifiquen con etiqueta deben:
  - implementar `Identificable` (o usar el trait `TieneIdentificador`);
  - agregar su línea a `config/lector.php`;
  - incluir las columnas `codigo_qr` y `etiqueta_nfc` con `unique(empresa_id, etiqueta_nfc)`.
- WebHID / Web Serial (lectores que no escriben como teclado) quedan fuera por ahora. Solo existen en Chrome y Edge de escritorio y obligan a programar cada modelo de lector.
