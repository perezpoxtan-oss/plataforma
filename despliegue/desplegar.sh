#!/bin/bash
# =============================================================================
# desplegar.sh — instala en el servidor la version publicada en GitHub.
#
#   Uso (desde cron):  /bin/bash $HOME/desplegar.sh qa
#                      /bin/bash $HOME/desplegar.sh prod
#
#   qa   -> instala siempre la ultima entrega aprobada en develop (version "qa")
#   prod -> instala la version escrita en $HOME/desplegar_version.txt (p. ej. v0.1.0)
#           Borrar o cambiar ese archivo = autorizar otra version o regresar a una anterior.
#
# Si no hay nada nuevo no hace nada (sale en silencio). El resultado de cada
# instalacion queda en $HOME/despliegue_<ambiente>_estado.txt (visible en el
# Administrador de archivos) y el detalle en <carpeta>/despliegue.log.
#
# Archivos que se suben una sola vez al inicio de $HOME (el script los guarda en
# un lugar privado y los borra):
#   github_token.txt  token de GitHub de SOLO LECTURA
#   env_<amb>.txt     configuracion (.env) del ambiente, con la contrasena de la base
#   <amb>_inicial.txt (solo QA) correo, nombre y contrasena para crear los usuarios de prueba
# =============================================================================
set -u
# Archivos legibles por el servidor web (Apache necesita leer .htaccess, css, js...).
# Lo privado (.env, token) se protege explicitamente con chmod 600/700.
umask 022

REPO="perezpoxtan-oss/plataforma"
AMB="${1:-}"
case "$AMB" in
  qa)
    APP="$HOME/apps/plataforma-qa"
    PUB="$HOME/qa.vdcp.com.mx"
    URL="https://qa.vdcp.com.mx"
    ;;
  prod)
    APP="$HOME/apps/plataforma-produccion"
    PUB="$HOME/app.vdcp.com.mx"
    URL="https://app.vdcp.com.mx"
    ;;
  *)
    echo "Uso: desplegar.sh qa|prod"
    exit 1
    ;;
esac

PRIVADO="$HOME/.config/plataforma"
ESTADO="$HOME/despliegue_${AMB}_estado.txt"
LOG="$APP/despliegue.log"
mkdir -p "$APP/releases" "$PRIVADO"
chmod 700 "$PRIVADO"
chmod 750 "$APP"

ahora() { date '+%Y-%m-%d %H:%M:%S'; }
log() { echo "[$(ahora)] $*" >> "$LOG"; }
estado() {
  {
    echo "Ambiente:        $AMB ($URL)"
    echo "Ultima revision: $(ahora)"
    echo "Version actual:  $(cat "$APP/.release_actual" 2>/dev/null || echo 'ninguna')"
    echo "Resultado:       $1"
    echo
    echo "Detalle en: $LOG"
  } > "$ESTADO"
}
fallo() {
  log "ERROR: $*"
  estado "ERROR: $*"
  exit 1
}

# Apache lee la carpeta publica con otro usuario: todo debe ser legible (644 / 755)
permisos_publicos() {
  [ -d "$PUB" ] && chmod -R go+rX "$PUB" 2>/dev/null
}
# Copia los archivos publicos de una version ($1) al subdominio y escribe el
# index.php que apunta a la version activa (se usa al instalar y al regresar)
publicar() {
  mkdir -p "$PUB"
  cp -a "$1/public/." "$PUB/"
  rm -f "$PUB/index.html" "$PUB/default.html"
  cat > "$PUB/index.php" <<PHPEOF
<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Generado por desplegar.sh: el codigo vive fuera de la carpeta publica.
// Se lee el destino de "actual" en cada visita (readlink no usa la cache de
// rutas de PHP), asi el cambio de version es inmediato.
\$base = '$APP/'.readlink('$APP/actual');

if (file_exists(\$mantenimiento = \$base.'/storage/framework/maintenance.php')) {
    require \$mantenimiento;
}

require \$base.'/vendor/autoload.php';

/** @var Application \$app */
\$app = require_once \$base.'/bootstrap/app.php';

\$app->handleRequest(Request::capture());
PHPEOF
  ln -sfn "$APP/shared/storage/app/public" "$PUB/storage"
  permisos_publicos
}
responde() {  # codigo HTTP de /up
  curl -s -o /dev/null -w '%{http_code}' --max-time 30 "$URL/up"
}

# --- Una sola corrida a la vez -------------------------------------------------
# Seguridad: el candado guarda el numero de proceso. Se considera abandonado solo
# si ese proceso ya no existe (o lleva mas de 3 horas: ninguna corrida tarda eso),
# o si no tiene numero y pasa de 30 minutos. Antes bastaban 30 minutos aunque la
# corrida siguiera viva, y una instalacion lenta podia encimarse con otra.
CANDADO="$APP/.desplegando"
if ! mkdir "$CANDADO" 2>/dev/null; then
  DUENO="$(cat "$CANDADO/pid" 2>/dev/null)"
  if [[ "$DUENO" =~ ^[0-9]+$ ]]; then
    kill -0 "$DUENO" 2>/dev/null && [ -z "$(find "$CANDADO" -maxdepth 0 -mmin +180 2>/dev/null)" ] && exit 0
  elif [ -z "$(find "$CANDADO" -maxdepth 0 -mmin +30 2>/dev/null)" ]; then
    exit 0
  fi
  # Abandonado: se toma con un cambio de nombre atomico (solo una corrida lo logra);
  # si lo movido ya no es el candado abandonado, otra corrida gano: se devuelve
  mv -T "$CANDADO" "$CANDADO.viejo.$$" 2>/dev/null || exit 0
  if [ "$(cat "$CANDADO.viejo.$$/pid" 2>/dev/null)" != "$DUENO" ]; then
    mv -T "$CANDADO.viejo.$$" "$CANDADO" 2>/dev/null
    exit 0
  fi
  rm -rf "$CANDADO.viejo.$$"
  mkdir "$CANDADO" 2>/dev/null || exit 0
fi
echo $$ > "$CANDADO/pid"
# Al salir solo se quita el candado propio
trap '[ "$(cat "$CANDADO/pid" 2>/dev/null)" = "$$" ] && rm -rf "$CANDADO"' EXIT

# --- PHP de linea de comandos ---------------------------------------------------
PHP="$(cat "$HOME/.php_cli_path" 2>/dev/null)"
if [ ! -x "$PHP" ]; then
  for c in /opt/alt/php84/usr/bin/php /opt/cpanel/ea-php84/root/usr/bin/php /usr/local/bin/php; do
    if [ -x "$c" ] && "$c" -v 2>/dev/null | head -1 | grep -Eq "PHP 8\.[3-9].*\(cli\)"; then PHP="$c"; break; fi
  done
fi
[ -x "$PHP" ] || fallo "No se encontro PHP 8.3+ de linea de comandos"
P=("$PHP" -d disable_functions= -d memory_limit=-1)

# --- Archivos de configuracion subidos por el usuario ---------------------------
guardar_privado() {  # $1 = archivo visible en $HOME, $2 = destino privado
  if [ -f "$HOME/$1" ]; then
    tr -d '\r' < "$HOME/$1" > "$2" && chmod 600 "$2" && rm -f "$HOME/$1"
    log "Se guardo $1 en un lugar privado y se borro el original"
  fi
}
guardar_privado github_token.txt "$PRIVADO/github_token"
TOKEN="$(tr -d ' \n' < "$PRIVADO/github_token" 2>/dev/null)"
[ -n "$TOKEN" ] || fallo "Falta el token de GitHub: sube github_token.txt a tu carpeta principal"
# Seguridad: un token de GitHub solo tiene letras, numeros y guion bajo
[[ "$TOKEN" =~ ^[A-Za-z0-9_]+$ ]] || fallo "github_token.txt no parece un token de GitHub (solo letras, numeros y _)"

# Seguridad: el token viaja a curl por la entrada estandar (-K -), no como argumento,
# para que no aparezca en la lista de procesos del servidor compartido
github() {
  printf 'header = "Authorization: Bearer %s"\nheader = "X-GitHub-Api-Version: 2022-11-28"\n' "$TOKEN" | curl -K - "$@"
}

mkdir -p "$APP/shared/storage/app/public" "$APP/shared/storage/framework/cache/data" \
         "$APP/shared/storage/framework/sessions" "$APP/shared/storage/framework/views" \
         "$APP/shared/storage/logs"

# Seguridad: en la carpeta de imágenes subidas (public/storage) no se ejecuta
# ni se sirve nada que no sea imagen, y el navegador no adivina el tipo
cat > "$APP/shared/storage/app/public/.htaccess" <<'HTEOF'
<FilesMatch "(?i)\.(php[0-9]?|phtml|phar|pht|pl|py|cgi|sh|s?html?|svg|svgz|xml|js|htaccess)$">
    Require all denied
</FilesMatch>
<IfModule mod_headers.c>
    Header set X-Content-Type-Options "nosniff"
    Header set Content-Security-Policy "default-src 'none'; sandbox"
</IfModule>
HTEOF

if [ -f "$HOME/env_${AMB}.txt" ]; then
  if grep -q "\[CONTRASENA" "$HOME/env_${AMB}.txt"; then
    fallo "env_${AMB}.txt todavia dice [CONTRASENA...]: escribe la contrasena real de la base y guarda"
  fi
  CLAVE="$(grep -E '^APP_KEY=base64:' "$APP/shared/.env" 2>/dev/null | head -1)"
  [ -n "$CLAVE" ] || CLAVE="APP_KEY=base64:$(head -c 32 /dev/urandom | base64)"
  [ -f "$APP/shared/.env" ] && cp "$APP/shared/.env" "$APP/shared/.env.respaldo.$(date +%Y%m%d%H%M%S)"
  # Seguridad: el .env nace con permisos 600 (umask 077), sin un instante legible por otros
  ( umask 077; { echo "$CLAVE"; tr -d '\r' < "$HOME/env_${AMB}.txt" | grep -v '^APP_KEY='; } > "$APP/shared/.env.nuevo" ) \
    && mv -f "$APP/shared/.env.nuevo" "$APP/shared/.env"
  chmod 600 "$APP/shared/.env" "$APP/shared/.env.respaldo."* 2>/dev/null
  # Seguridad: cada respaldo del .env tiene la contrasena de la base; se guardan solo los 3 mas recientes
  ls -1t "$APP/shared/".env.respaldo.* 2>/dev/null | tail -n +4 | while read -r viejo; do rm -f "$viejo"; done
  rm -f "$HOME/env_${AMB}.txt"
  log "Configuracion (.env) actualizada desde env_${AMB}.txt (APP_KEY conservada)"
  ENV_NUEVO=1
fi
[ -f "$APP/shared/.env" ] || fallo "Falta la configuracion: sube env_${AMB}.txt a tu carpeta principal"

# Seguridad: Produccion nunca corre en modo depuracion ni con otro nombre de ambiente
# (APP_ENV distinto de "production" desactiva los candados de Produccion).
valor_env() { grep -E "^$1=" "$APP/shared/.env" | tail -1 | cut -d= -f2- | tr -d "\"' \r"; }
if [ "$AMB" = "prod" ]; then
  [ "$(valor_env APP_ENV)" = "production" ] || fallo "env_prod.txt debe decir APP_ENV=production (dice: $(valor_env APP_ENV))"
  case "$(valor_env APP_DEBUG)" in
    ""|false|0) ;;
    *) fallo "env_prod.txt debe decir APP_DEBUG=false: en Produccion los errores no deben mostrar detalles internos" ;;
  esac
  case "$(valor_env APP_URL)" in
    https://*) ;;
    *) fallo "env_prod.txt debe tener APP_URL con https://" ;;
  esac
fi

# --- Que version toca instalar ---------------------------------------------------
if [ "$AMB" = "prod" ]; then
  # Primera linea: "v0.1.0" y, opcionalmente, la huella autorizada: "v0.1.0 sha256:<64 hex>"
  ETIQUETA=""; HUELLA_AUTORIZADA=""
  read -r ETIQUETA HUELLA_AUTORIZADA _ < <(head -1 "$HOME/desplegar_version.txt" 2>/dev/null | tr -d '\r')
  [ -n "$ETIQUETA" ] || { estado "Sin version autorizada (falta desplegar_version.txt)"; exit 0; }
  [[ "$ETIQUETA" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || fallo "desplegar_version.txt debe decir algo como v0.1.0 (dice: ${ETIQUETA:0:40})"
  [ -z "$HUELLA_AUTORIZADA" ] || [[ "$HUELLA_AUTORIZADA" =~ ^sha256:[0-9a-f]{64}$ ]] \
    || fallo "La huella en desplegar_version.txt debe tener la forma sha256:<64 caracteres 0-9a-f>"
else
  ETIQUETA="qa"
fi

# PLATAFORMA_API / PLATAFORMA_URL solo se usan para probar el script fuera del servidor
API="${PLATAFORMA_API:-https://api.github.com}/repos/$REPO"
URL="${PLATAFORMA_URL:-$URL}"
JSON="$(github -fsS --max-time 60 -H "Accept: application/vnd.github+json" "$API/releases/tags/$ETIQUETA" 2>&1)" \
  || fallo "GitHub no respondio la version $ETIQUETA (revisa el token o que la version exista): $(echo "$JSON" | head -c 200)"

read -r ACTIVO_ID DIGESTO <<< "$(printf '%s' "$JSON" | "$PHP" -r '
  $r = json_decode(stream_get_contents(STDIN), true);
  foreach ($r["assets"] ?? [] as $a) {
      if ($a["name"] === "paquete.tar.gz") { echo $a["id"], " ", ($a["digest"] ?? "-"); exit; }
  }
  echo "- -";')"
[ "$ACTIVO_ID" != "-" ] || fallo "La version $ETIQUETA no tiene paquete.tar.gz todavia"
# Seguridad: el numero del paquete forma rutas (releases/<nombre>) y la huella es obligatoria:
# sin ella no hay forma de saber que lo descargado es lo que publico la CI
[[ "$ACTIVO_ID" =~ ^[0-9]+$ ]] || fallo "GitHub devolvio un identificador de paquete no valido"
[[ "$DIGESTO" =~ ^sha256:[0-9a-f]{64}$ ]] || fallo "GitHub no dio la huella sha256 del paquete de $ETIQUETA; no se instala sin poder verificarlo"

# Seguridad (Produccion): una version autorizada no cambia de contenido. Si el paquete
# de la misma etiqueta se reemplaza en GitHub (otra huella), no se instala.
HUELLAS="$APP/.huellas"
if [ "$AMB" = "prod" ]; then
  if [ -n "$HUELLA_AUTORIZADA" ] && [ "$HUELLA_AUTORIZADA" != "$DIGESTO" ]; then
    fallo "El paquete de $ETIQUETA no coincide con la huella escrita en desplegar_version.txt"
  fi
  CONOCIDA="$(awk -v t="$ETIQUETA" '$1 == t { print $2 }' "$HUELLAS" 2>/dev/null | tail -1)"
  if [ -n "$CONOCIDA" ] && [ "$CONOCIDA" != "$DIGESTO" ]; then
    fallo "El paquete de $ETIQUETA cambio en GitHub despues de autorizarse (huella distinta); publica una version nueva (otra etiqueta)"
  fi
fi

NOMBRE="${ETIQUETA}-${ACTIVO_ID}"
ACTUAL="$(cat "$APP/.release_actual" 2>/dev/null)"

# Una version que ya fallo no se reintenta en cada corrida (se espera la siguiente)
if [ "$NOMBRE" != "$ACTUAL" ] && [ -z "${ENV_NUEVO:-}" ] && [ "$NOMBRE" = "$(cat "$APP/.release_fallida" 2>/dev/null)" ]; then
  estado "ERROR: la version $NOMBRE fallo y se mantiene $ACTUAL; se espera una version corregida"
  exit 0
fi

# --- Instalar la version nueva ---------------------------------------------------
if [ "$NOMBRE" != "$ACTUAL" ] || [ -n "${ENV_NUEVO:-}" ]; then
  log "=== Instalando $NOMBRE (antes: ${ACTUAL:-ninguna}) ==="
  REL="$APP/releases/$NOMBRE"

  if [ "$NOMBRE" != "$ACTUAL" ]; then
    TMP="$APP/paquete.tmp.tar.gz"
    rm -f "$TMP"
    github -fsSL --max-time 600 -H "Accept: application/octet-stream" \
      "$API/releases/assets/$ACTIVO_ID" -o "$TMP" || fallo "No se pudo descargar el paquete"
    [ "sha256:$(sha256sum "$TMP" | cut -d' ' -f1)" = "$DIGESTO" ] || { rm -f "$TMP"; fallo "El paquete descargado no coincide con su huella (sha256)"; }
    if [ "$AMB" = "prod" ] && ! awk -v t="$ETIQUETA" '$1 == t { e = 1 } END { exit !e }' "$HUELLAS" 2>/dev/null; then
      echo "$ETIQUETA $DIGESTO" >> "$HUELLAS"
    fi
    rm -rf "$REL" && mkdir -p "$REL"
    tar -xzf "$TMP" -C "$REL" || fallo "No se pudo descomprimir el paquete"
    rm -f "$TMP"
    log "Paquete descargado y verificado: $(head -2 "$REL/VERSION" 2>/dev/null | tr '\n' ' ')"
  fi

  # Piezas compartidas entre versiones: configuracion, archivos subidos, sesiones y bitacoras
  rm -rf "$REL/storage"
  ln -sfn "$APP/shared/storage" "$REL/storage"
  ln -sfn "$APP/shared/.env" "$REL/.env"
  echo "$PUB" > "$REL/.public_path"
  mkdir -p "$REL/bootstrap/cache"
  # Cache de arranque siempre limpia: se regenera abajo con la configuracion de este servidor
  rm -f "$REL"/bootstrap/cache/*.php

  cd "$REL" || fallo "No existe $REL"
  "${P[@]}" artisan package:discover >> "$LOG" 2>&1 || fallo "Laravel no arranca con esta version (package:discover)"

  # Modo mantenimiento mientras se actualiza la base (lo comparten ambas versiones)
  [ -n "$ACTUAL" ] && "${P[@]}" artisan down --retry=30 >> "$LOG" 2>&1

  # Respaldo de la base antes de tocarla (si no hay comando aun, se sigue: versiones viejas)
  if [ -n "$ACTUAL" ] && "${P[@]}" artisan list --raw 2>/dev/null | grep -q '^plataforma:respaldar'; then
    "${P[@]}" artisan plataforma:respaldar --motivo=antes-de-actualizar >> "$LOG" 2>&1 \
      || log "ADVERTENCIA: no se pudo respaldar antes de actualizar (se continua)"
  fi

  if ! "${P[@]}" artisan migrate --force >> "$LOG" 2>&1; then
    "${P[@]}" artisan up >> "$LOG" 2>&1
    echo "$NOMBRE" > "$APP/.release_fallida"
    fallo "Fallo la actualizacion de la base (migrate); se queda la version anterior"
  fi
  "${P[@]}" artisan db:seed --force >> "$LOG" 2>&1 || log "Aviso: db:seed reporto un problema"
  "${P[@]}" artisan config:cache >> "$LOG" 2>&1
  "${P[@]}" artisan route:cache >> "$LOG" 2>&1
  "${P[@]}" artisan view:cache >> "$LOG" 2>&1

  # Archivos publicos al subdominio + index.php que apunta a la version activa
  publicar "$REL"

  # Cambio instantaneo a la version nueva
  ln -sfn "releases/$NOMBRE" "$APP/actual.nuevo" && mv -T "$APP/actual.nuevo" "$APP/actual"
  echo "$NOMBRE" > "$APP/.release_actual"
  "${P[@]}" artisan up >> "$LOG" 2>&1

  # Comprobar que el sitio responde; si no, regresar a la version anterior
  sleep 2
  CODIGO="$(responde)"
  if [ "$CODIGO" != "200" ]; then
    if [ -n "$ACTUAL" ] && [ "$ACTUAL" != "$NOMBRE" ] && [ -d "$APP/releases/$ACTUAL" ]; then
      echo "$NOMBRE" > "$APP/.release_fallida"
      ln -sfn "releases/$ACTUAL" "$APP/actual.nuevo" && mv -T "$APP/actual.nuevo" "$APP/actual"
      echo "$ACTUAL" > "$APP/.release_actual"
      # Seguridad/continuidad: tambien regresan .htaccess, css y js de la version anterior
      publicar "$APP/releases/$ACTUAL"
      rm -rf "$REL"
      fallo "El sitio respondio $CODIGO con $NOMBRE; se regreso a $ACTUAL"
    fi
    # Sin version anterior (primera instalacion): queda instalada y se vuelve a
    # comprobar en cada revision (p. ej. mientras se emite el certificado SSL)
    fallo "El sitio respondio $CODIGO con $NOMBRE; se volvera a comprobar en la siguiente revision"
  fi

  # Conservar solo las 3 versiones mas recientes (para poder regresar)
  cd "$APP/releases" && ls -1t | grep -vx "$NOMBRE" | tail -n +3 | while read -r viejo; do rm -rf "$APP/releases/$viejo"; done

  rm -f "$APP/.release_fallida"
  log "Instalada $NOMBRE; el sitio responde 200"
  estado "OK: instalada $NOMBRE"

  # El propio script se actualiza con la copia que trae el paquete
  if [ -f "$REL/despliegue/desplegar.sh" ] && ! cmp -s "$REL/despliegue/desplegar.sh" "$HOME/desplegar.sh"; then
    cp "$REL/despliegue/desplegar.sh" "$HOME/desplegar.sh.nuevo" && mv "$HOME/desplegar.sh.nuevo" "$HOME/desplegar.sh"
    log "desplegar.sh se actualizo con la version del paquete"
  fi
else
  # Sin version nueva: se revisa que el sitio siga respondiendo
  permisos_publicos
  CODIGO="$(responde)"
  if [ "$CODIGO" = "200" ]; then
    estado "OK: sin cambios"
  else
    log "ADVERTENCIA: el sitio responde $CODIGO"
    estado "ADVERTENCIA: el sitio responde $CODIGO (si es 000, revisa el certificado SSL; si es 403/500, avisame)"
  fi
fi

# --- QA: completar los datos demo con cada version nueva ------------------------
# Los modulos nuevos traen sus datos de ejemplo (y a veces usuarios demo nuevos,
# como rh.demo). Se agregan una vez por version; lo que ya existe no se toca.
# Si no hay contraseña guardada, las cuentas nuevas reciben la de admin.demo.
if [ "$AMB" = "qa" ] && [ -d "$APP/actual" ] \
   && [ "$(cat "$APP/.release_actual" 2>/dev/null)" != "$(cat "$APP/.demo_completado" 2>/dev/null)" ]; then
  PLATAFORMA_CONTRASENA="$(grep -E '^CONTRASENA=' "$PRIVADO/qa_inicial" 2>/dev/null | cut -d= -f2-)"
  export PLATAFORMA_CONTRASENA
  [ -n "$PLATAFORMA_CONTRASENA" ] || unset PLATAFORMA_CONTRASENA
  SALIDA_DEMO="$(cd "$APP/actual" && "${P[@]}" artisan plataforma:demo 2>&1)"; RC_DEMO=$?
  printf '%s\n' "$SALIDA_DEMO" >> "$LOG"
  if [ $RC_DEMO -eq 0 ]; then
    cat "$APP/.release_actual" > "$APP/.demo_completado"
    log "Datos demo completados para $(cat "$APP/.release_actual")"
    NUEVOS="$(printf '%s\n' "$SALIDA_DEMO" | grep -o 'Usuario demo creado: [a-z0-9.]*' | sed 's/Usuario demo creado: //' | tr '\n' ' ')"
    SIN="$(printf '%s\n' "$SALIDA_DEMO" | grep 'Partes del demo sin completar' | head -1)"
    estado "OK: $(cat "$APP/.release_actual") · datos demo al dia${NUEVOS:+ · usuarios nuevos: $NUEVOS}${SIN:+ · $SIN}"
  else
    log "ADVERTENCIA: no se pudieron completar los datos demo"
    estado "ADVERTENCIA: no se pudieron completar los datos demo: $(printf '%s\n' "$SALIDA_DEMO" | grep -v '^\s*$' | tail -1 | cut -c1-200)"
  fi
  unset PLATAFORMA_CONTRASENA
fi

# --- Respaldo diario de la base (uno por dia, despues de las 3:00) ---------------
if [ -d "$APP/actual" ] && (cd "$APP/actual" && "${P[@]}" artisan list --raw 2>/dev/null | grep -q '^plataforma:respaldar'); then
  (cd "$APP/actual" && "${P[@]}" artisan plataforma:respaldar --si-toca >> "$LOG" 2>&1) \
    || estado "ADVERTENCIA: no se pudo hacer el respaldo diario (revisa despliegue.log)"
fi

# --- Usuarios de prueba (solo QA, una vez) ---------------------------------------
if [ "$AMB" = "qa" ] && [ -f "$HOME/qa_inicial.txt" ] && grep -Eq "EscribeAqui|tu-correo@" "$HOME/qa_inicial.txt"; then
  estado "ERROR: qa_inicial.txt todavia tiene los datos de ejemplo; escribe tu correo y una contrasena nueva"
elif [ "$AMB" = "qa" ] && [ -f "$HOME/qa_inicial.txt" ]; then
  INICIAL="$PRIVADO/qa_inicial"
  guardar_privado qa_inicial.txt "$INICIAL"
  CORREO="$(grep -E '^CORREO=' "$INICIAL" | cut -d= -f2- | tr -d ' ')"
  NOMBRE_SA="$(grep -E '^NOMBRE=' "$INICIAL" | cut -d= -f2-)"
  USUARIO_SA="$(grep -E '^USUARIO=' "$INICIAL" | cut -d= -f2- | tr -d ' ')"
  PLATAFORMA_CONTRASENA="$(grep -E '^CONTRASENA=' "$INICIAL" | cut -d= -f2-)"
  export PLATAFORMA_CONTRASENA
  cd "$APP/actual" || fallo "No hay version instalada para crear usuarios"
  if [ -n "$CORREO" ] && [ -n "$PLATAFORMA_CONTRASENA" ] \
     && "${P[@]}" artisan plataforma:superadmin --nombre="${NOMBRE_SA:-Super Administrador}" --usuario="${USUARIO_SA}" -- "$CORREO" >> "$LOG" 2>&1 \
     && "${P[@]}" artisan plataforma:demo >> "$LOG" 2>&1; then
    log "Usuarios de prueba creados (Super Administrador y empresa demo)"
    estado "OK: $(cat "$APP/.release_actual") · usuarios de prueba creados"
  else
    log "ERROR: no se pudieron crear los usuarios de prueba (revisa CORREO y CONTRASENA en qa_inicial.txt)"
    estado "ERROR: no se pudieron crear los usuarios de prueba"
  fi
  unset PLATAFORMA_CONTRASENA
  rm -f "$INICIAL"
fi

exit 0
