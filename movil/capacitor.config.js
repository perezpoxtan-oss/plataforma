/*
 * Configuración de la app (Capacitor). Los datos que cambian viven en app.config.json:
 *   appId    identificador de la app en Android (permanente una vez publicada en Google Play)
 *   appName  nombre bajo el ícono
 *   servidor "qa", "produccion" o una dirección https:// completa
 * La variable de entorno SERVIDOR, si existe, manda sobre app.config.json
 * (p. ej. «SERVIDOR=produccion npx cap sync android»).
 * «qa» y «produccion» toman la dirección (APP_URL) de despliegue/env_qa.txt y env_prod.txt.
 * Instrucciones completas: docs/tecnico/app-movil.md
 */
const fs = require('fs');
const path = require('path');

const app = JSON.parse(fs.readFileSync(path.join(__dirname, 'app.config.json'), 'utf8'));

function direccionDesdeDespliegue(archivo) {
    const ruta = path.join(__dirname, '..', 'despliegue', archivo);
    const linea = fs.readFileSync(ruta, 'utf8').split(/\r?\n/).find((l) => /^APP_URL=/.test(l));
    if (!linea) {
        throw new Error('No encontré APP_URL en ' + ruta);
    }
    return linea.slice('APP_URL='.length).trim().replace(/^["']|["']$/g, '');
}

function direccionDelServidor() {
    const elegido = (process.env.SERVIDOR || app.servidor || 'qa').trim();
    let url = elegido;
    if (elegido === 'qa') {
        url = direccionDesdeDespliegue('env_qa.txt');
    } else if (elegido === 'produccion') {
        url = direccionDesdeDespliegue('env_prod.txt');
    }
    url = url.replace(/\/+$/, '');
    if (!/^https:\/\/[^/\s]+/.test(url)) {
        throw new Error('La dirección del servidor debe empezar con https:// (recibí «' + url + '»).');
    }
    return url;
}

/** @type {import('@capacitor/cli').CapacitorConfig} */
module.exports = {
    appId: app.appId,
    appName: app.appName,
    // Páginas locales: solo la pantalla «Sin conexión»; la plataforma se carga desde el servidor
    webDir: 'www',
    // La plataforma reconoce la app por este texto (App\Support\AppMovil)
    appendUserAgent: 'SmartCentinelApp/1',
    server: {
        url: direccionDelServidor(),
        cleartext: false,
        androidScheme: 'https',
        errorPath: 'sin-conexion.html',
        // Sin allowNavigation: cualquier enlace a otro dominio se abre en el navegador del teléfono
    },
    android: {
        allowMixedContent: false,
    },
};
