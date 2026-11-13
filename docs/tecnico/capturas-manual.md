# Capturas del Manual de usuario

Las imágenes de `docs/usuario/img/**` se regeneran con un script de Playwright que recorre
cada captura que usan las páginas `docs/usuario/*.md` y la vuelve a tomar con la versión
actual de la plataforma, **con el mismo nombre de archivo** (los enlaces del manual no cambian).

| Archivo | Qué hace |
|---|---|
| `scripts/capturas-manual/preparar.php` | Deja la base **local** lista para las capturas: cambia usuarios, correos, nombres y empresas de prueba por nombres neutros (`ana.lopez`, «Grupo Hotelero Ejemplo»…), crea el Super Administrador `soporte` con verificación en dos pasos de clave conocida, bloquea una cuenta (para «Cómo desbloquear»), enciende Asistencia en el hotel con entradas y salidas de ejemplo, da de alta dos equipos de acceso, un contratista en sitio con herramienta, un evento de Reservas para hoy y publica la página de planes. Solo corre con `APP_ENV=local` y SQLite. |
| `scripts/capturas-manual/revisar-datos.php` | Busca en la base local textos que no deben verse (`.demo`, dominios, «demo», «migración»…). Solo lee. |
| `scripts/capturas-manual/pantallas.mjs` | Una entrada por captura: archivo, cuenta, pantalla, tamaño de ventana, modo (Normal, Sol, Noche), pasos previos (abrir un diálogo, escribir, desplegar una sección…) y recorte (pantalla, página completa o un elemento). |
| `scripts/capturas-manual/capturas.mjs` | Toma las capturas. Oculta la franja «Desarrollo — los datos son ficticios» solo en la imagen (no toca la app), apaga animaciones y el cursor, y abre la app como `http://plataforma.ejemplo.mx` (el navegador lo dirige a la app local), así los QR y enlaces muestran una dirección de ejemplo. |
| `scripts/capturas-manual/comprimir.py` | Comprime los PNG (Pillow) solo si quedan más chicos. |
| `scripts/capturas-manual/archivos/` | Archivos CSV de ejemplo para las capturas de Importar desde Excel e Importar estructura. |

## Cómo regenerarlas

```bash
# 1. App local con SQLite (DB_CONNECTION=sqlite y DB_DATABASE=<ruta absoluta> en .env;
#    APP_URL=http://plataforma.ejemplo.mx para que los enlaces y QR salgan con la dirección de ejemplo)
php artisan migrate --force && php artisan db:seed --force
PLATAFORMA_CONTRASENA=Prueba.Local.2026 php artisan plataforma:demo
php scripts/capturas-manual/preparar.php
php scripts/capturas-manual/revisar-datos.php     # debe salir solo la ruta interna de una foto

# 2. Servidor local (varios procesos: el script abre varias pantallas seguidas)
PHP_CLI_SERVER_WORKERS=6 php artisan serve --port=8190

# 3. Capturas (Playwright y Chromium ya instalados; nunca «playwright install»)
NODE_PATH=$(npm root -g) node scripts/capturas-manual/capturas.mjs --base http://127.0.0.1:8190
NODE_PATH=$(npm root -g) node scripts/capturas-manual/capturas.mjs --solo accesos/,menus/   # solo algunas
NODE_PATH=$(npm root -g) node scripts/capturas-manual/capturas.mjs --lista                  # ver cuáles hay
```

- Corre el proceso completo sobre una base **recién preparada**: algunas capturas guardan algo
  (un lote de gafetes, un ingreso pendiente, un objeto de Lost & Found, una importación, el área
  de Reservas que se quita, la cuenta que se desbloquea) y van al final de su grupo. Para repetir
  una sola de esas, vuelve a preparar la base.
- Las fechas de los datos de ejemplo son relativas al día en que se corre `plataforma:demo`.
- Al terminar, comprime los PNG con `python3 scripts/capturas-manual/comprimir.py $(git diff --name-only docs/usuario/img)`
  (paleta de 256 colores sin tramado: el texto queda nítido y pesan ~3 veces menos) y revisa
  una muestra: que no se vea la franja de ambiente, `.demo`, «demo», dominios reales ni la palabra «migración».

## Cuándo agregar una entrada

Cada vez que una página del manual estrene una captura, agrega su entrada en `pantallas.mjs`
(junto a las de su módulo). Las ayudas disponibles (`dialogo`, `dialogoDe`, `botonDe`, `enlaceDe`,
`campo`, `opcion`, `escribir`, `enviarSinValidar` para el «Error dentro de la ventana»…) están
descritas en `capturas.mjs`.

## Capturas que no se regeneran solas

Algunas imágenes muestran un estado que los datos de ejemplo no tienen (se dejan como están hasta
que haya datos que lo reproduzcan):

- `altas-por-verificar/30-campana-alta-por-verificar.png` y `31-campana-alta-rechazada.png`: avisos de la campana que solo existen después de que la caseta da un alta y alguien la rechaza.
- `pases-salida/circuito-paso-jefe.png`: el aviso solo sale si el circuito **no** tiene el paso del jefe del departamento (el de ejemplo sí lo tiene).
- `suscripciones/usar-asistencia.png`: el interruptor «Usar Asistencia» solo aparece en un plan que la incluye apagada (Corporativo); ningún cliente de ejemplo lo tiene.
- `traslado/2-avance.png`: el avance de un paquete de traslado mientras se genera.
