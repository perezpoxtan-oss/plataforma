/* ==========================================================================
   Plataforma instalable (PWA) y app de Android. Ver docs/tecnico/app-movil.md.
   - En el navegador: registra el service worker (/sw.js).
   - Dentro de la app (user-agent con «SmartCentinelApp/»): no lo registra
     (la app tiene su propia pantalla «Sin conexión») e «Imprimir» usa el
     diálogo de impresión de Android (window.print no hace nada ahí).
   ========================================================================== */
(function () {
    'use strict';

    var enApp = (navigator.userAgent || '').indexOf('SmartCentinelApp/') !== -1;

    if (enApp) {
        document.documentElement.classList.add('en-app');
        var imprimirOriginal = window.print;
        window.print = function () {
            var nativa = window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.AppNativa;
            if (nativa && typeof nativa.imprimir === 'function') {
                nativa.imprimir();
            } else {
                imprimirOriginal.call(window);
            }
        };
        return;
    }

    if (!('serviceWorker' in navigator) || window.isSecureContext === false) { return; }
    window.addEventListener('load', function () {
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(function () { /* sin service worker: la plataforma funciona igual */ });
    });
})();
