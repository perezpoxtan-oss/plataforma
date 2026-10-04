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
umask 027

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

# --- Una sola corrida a la vez -------------------------------------------------
CANDADO="$APP/.desplegando"
if ! mkdir "$CANDADO" 2>/dev/null; then
  # Un candado de mas de 30 minutos es de una corrida que se interrumpio
  if [ -n "$(find "$CANDADO" -maxdepth 0 -mmin +30 2>/dev/null)" ]; then
    rmdir "$CANDADO"; mkdir "$CANDADO" || exit 0
  else
    exit 0
  fi
fi
trap 'rmdir "$CANDADO" 2>/dev/null' EXIT

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

mkdir -p "$APP/shared/storage/app/public" "$APP/shared/storage/framework/cache/data" \
         "$APP/shared/storage/framework/sessions" "$APP/shared/storage/framework/views" \
         "$APP/shared/storage/logs"

if [ -f "$HOME/env_${AMB}.txt" ]; then
  if grep -q "\[CONTRASENA" "$HOME/env_${AMB}.txt"; then
    fallo "env_${AMB}.txt todavia dice [CONTRASENA...]: escribe la contrasena real de la base y guarda"
  fi
  CLAVE="$(grep -E '^APP_KEY=base64:' "$APP/shared/.env" 2>/dev/null | head -1)"
  [ -n "$CLAVE" ] || CLAVE="APP_KEY=base64:$(head -c 32 /dev/urandom | base64)"
  [ -f "$APP/shared/.env" ] && cp "$APP/shared/.env" "$APP/shared/.env.respaldo.$(date +%Y%m%d%H%M%S)"
  { echo "$CLAVE"; tr -d '\r' < "$HOME/env_${AMB}.txt" | grep -v '^APP_KEY='; } > "$APP/shared/.env"
  chmod 600 "$APP/shared/.env" "$APP/shared/.env.respaldo."* 2>/dev/null
  rm -f "$HOME/env_${AMB}.txt"
  log "Configuracion (.env) actualizada desde env_${AMB}.txt (APP_KEY conservada)"
  ENV_NUEVO=1
fi
[ -f "$APP/shared/.env" ] || fallo "Falta la configuracion: sube env_${AMB}.txt a tu carpeta principal"

# --- Que version toca instalar ---------------------------------------------------
if [ "$AMB" = "prod" ]; then
  ETIQUETA="$(tr -d ' \r\n' < "$HOME/desplegar_version.txt" 2>/dev/null)"
  [ -n "$ETIQUETA" ] || { estado "Sin version autorizada (falta desplegar_version.txt)"; exit 0; }
  [[ "$ETIQUETA" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || fallo "desplegar_version.txt debe decir algo como v0.1.0 (dice: $ETIQUETA)"
else
  ETIQUETA="qa"
fi

# PLATAFORMA_API / PLATAFORMA_URL solo se usan para probar el script fuera del servidor
API="${PLATAFORMA_API:-https://api.github.com}/repos/$REPO"
URL="${PLATAFORMA_URL:-$URL}"
CABECERAS=(-H "Authorization: Bearer $TOKEN" -H "X-GitHub-Api-Version: 2022-11-28")
JSON="$(curl -fsS --max-time 60 "${CABECERAS[@]}" -H "Accept: application/vnd.github+json" "$API/releases/tags/$ETIQUETA" 2>&1)" \
  || fallo "GitHub no respondio la version $ETIQUETA (revisa el token o que la version exista): $(echo "$JSON" | head -c 200)"

read -r ACTIVO_ID DIGESTO <<< "$(printf '%s' "$JSON" | "$PHP" -r '
  $r = json_decode(stream_get_contents(STDIN), true);
  foreach ($r["assets"] ?? [] as $a) {
      if ($a["name"] === "paquete.tar.gz") { echo $a["id"], " ", ($a["digest"] ?? "-"); exit; }
  }
  echo "- -";')"
[ "$ACTIVO_ID" != "-" ] || fallo "La version $ETIQUETA no tiene paquete.tar.gz todavia"

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
    curl -fsSL --max-time 600 "${CABECERAS[@]}" -H "Accept: application/octet-stream" \
      "$API/releases/assets/$ACTIVO_ID" -o "$TMP" || fallo "No se pudo descargar el paquete"
    if [[ "$DIGESTO" == sha256:* ]]; then
      [ "sha256:$(sha256sum "$TMP" | cut -d' ' -f1)" = "$DIGESTO" ] || fallo "El paquete descargado no coincide con su huella (sha256)"
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
  mkdir -p "$PUB"
  cp -a "$REL/public/." "$PUB/"
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

  # Cambio instantaneo a la version nueva
  ln -sfn "releases/$NOMBRE" "$APP/actual.nuevo" && mv -T "$APP/actual.nuevo" "$APP/actual"
  echo "$NOMBRE" > "$APP/.release_actual"
  "${P[@]}" artisan up >> "$LOG" 2>&1

  # Comprobar que el sitio responde; si no, regresar a la version anterior
  sleep 2
  CODIGO="$(curl -s -o /dev/null -w '%{http_code}' --max-time 30 "$URL/up")"
  if [ "$CODIGO" != "200" ]; then
    echo "$NOMBRE" > "$APP/.release_fallida"
    if [ -n "$ACTUAL" ] && [ "$ACTUAL" != "$NOMBRE" ] && [ -d "$APP/releases/$ACTUAL" ]; then
      ln -sfn "releases/$ACTUAL" "$APP/actual.nuevo" && mv -T "$APP/actual.nuevo" "$APP/actual"
      echo "$ACTUAL" > "$APP/.release_actual"
      rm -rf "$REL"
      fallo "El sitio respondio $CODIGO con $NOMBRE; se regreso a $ACTUAL"
    fi
    fallo "El sitio respondio $CODIGO con $NOMBRE (no hay version anterior a cual regresar)"
  fi

  # Conservar solo las 3 versiones mas recientes (para poder regresar)
  cd "$APP/releases" && ls -1t | grep -vx "$NOMBRE" | tail -n +3 | while read -r viejo; do rm -rf "$APP/releases/$viejo"; done

  rm -f "$APP/.release_fallida"
  log "Instalada $NOMBRE; el sitio responde 200"
  estado "OK: instalada $NOMBRE"
else
  estado "OK: sin cambios"
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
     && "${P[@]}" artisan plataforma:superadmin "$CORREO" --nombre="${NOMBRE_SA:-Super Administrador}" --usuario="${USUARIO_SA}" >> "$LOG" 2>&1 \
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
