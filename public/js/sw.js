/* ==========================================================================
   Service worker de la plataforma (se sirve en /sw.js, ver PwaController).
   Solo guarda el «cascarón» estático (css, js, íconos) y la página «Sin
   conexión». NUNCA guarda páginas con sesión, formularios ni consultas:
   - Pantallas (navegación): siempre a la red; sin red, «Sin conexión».
   - css / js / vendor / íconos: red primero; sin red, la copia guardada.
   - Todo lo demás (POST, /storage, consultas, descargas): ni lo toca.
   ========================================================================== */
'use strict';

var VERSION = '__VERSION__';
var CACHE = 'cascaron-' + VERSION;
var SIN_CONEXION = '/sin-conexion';
var INICIAL = [SIN_CONEXION, '/iconos/app-192.png', '/iconos/app-512.png'];
var CARPETAS = ['/css/', '/js/', '/vendor/', '/iconos/'];

self.addEventListener('install', function (e) {
    e.waitUntil(
        caches.open(CACHE)
            .then(function (c) { return c.addAll(INICIAL); })
            .then(function () { return self.skipWaiting(); })
    );
});

self.addEventListener('activate', function (e) {
    e.waitUntil(
        caches.keys()
            .then(function (claves) {
                return Promise.all(claves.filter(function (k) {
                    return k.indexOf('cascaron-') === 0 && k !== CACHE;
                }).map(function (k) { return caches.delete(k); }));
            })
            .then(function () { return self.clients.claim(); })
    );
});

function esCascaron(url) {
    return CARPETAS.some(function (c) { return url.pathname.indexOf(c) === 0; });
}

self.addEventListener('fetch', function (e) {
    var peticion = e.request;
    if (peticion.method !== 'GET') { return; }
    var url = new URL(peticion.url);
    if (url.origin !== self.location.origin) { return; }

    if (peticion.mode === 'navigate') {
        e.respondWith(fetch(peticion).catch(function () { return caches.match(SIN_CONEXION); }));
        return;
    }
    if (!esCascaron(url)) { return; }

    // Se guarda sin «?v=…» para no acumular una copia por versión
    e.respondWith(
        fetch(peticion)
            .then(function (respuesta) {
                if (respuesta.ok && respuesta.type === 'basic') {
                    var copia = respuesta.clone();
                    caches.open(CACHE).then(function (c) { return c.put(url.pathname, copia); });
                }
                return respuesta;
            })
            .catch(function () { return caches.match(url.pathname); })
    );
});
