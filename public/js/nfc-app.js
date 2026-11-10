/* ==========================================================================
   NFC dentro de la app de Android. Ver docs/tecnico/app-movil.md («NFC en la app»).
   El WebView de Android no trae Web NFC (NDEFReader): aquí se ofrece uno mínimo
   con la misma forma, que usa el lector nativo de la app (plugin «LectorNfc»).
   Así el lector universal (plataforma.js) funciona igual que en Chrome, sin
   permisos del navegador.
   - scan({signal}) resuelve si el teléfono tiene NFC y está encendido; si no,
     rechaza con NotSupportedError / NotAllowedError (propiedad «codigo»:
     'sin-plugin', 'sin-nfc' o 'nfc-apagado') y un mensaje claro.
   - Eventos «reading» (onreading / addEventListener) con serialNumber y
     message.records[{recordType, mediaType, data (DataView), encoding, lang}];
     «readingerror» si la etiqueta se alejó a media lectura.
   - write() y makeReadOnly() rechazan: la app solo lee (la plataforma no graba etiquetas).
   - Si nadie está leyendo y la etiqueta trae una dirección de esta plataforma,
     se abre aquí mismo (la etiqueta nunca saca a la persona de la app).
   Se carga en <head> (sin defer) para existir antes que plataforma.js.
   ========================================================================== */
(function () {
    'use strict';

    if ((navigator.userAgent || '').indexOf('SmartCentinelApp/') === -1) { return; }

    var activos = [];
    var escuchando = false;

    function plugin() {
        var c = window.Capacitor;
        return c && c.Plugins && c.Plugins.LectorNfc ? c.Plugins.LectorNfc : null;
    }

    function error(nombre, mensaje, codigo) {
        var e;
        try { e = new DOMException(mensaje, nombre); } catch (x) { e = new Error(mensaje); e.name = nombre; }
        if (codigo) { try { e.codigo = codigo; } catch (x) { /* sin código: queda el mensaje */ } }
        return e;
    }

    function vista(base64) {
        var binario = '';
        try { binario = window.atob(base64 || ''); } catch (x) { binario = ''; }
        var bytes = new Uint8Array(binario.length);
        for (var i = 0; i < binario.length; i++) { bytes[i] = binario.charCodeAt(i); }
        return new DataView(bytes.buffer);
    }

    function crearEvento(tipo, datos) {
        var ev;
        try { ev = new Event(tipo); } catch (x) { ev = { type: tipo }; }
        ev.serialNumber = (datos && datos.serialNumber) || '';
        ev.message = {
            records: ((datos && datos.records) || []).map(function (r) {
                return {
                    recordType: r.recordType || 'unknown',
                    mediaType: r.mediaType || undefined,
                    id: undefined,
                    encoding: r.encoding || undefined,
                    lang: r.lang || undefined,
                    data: vista(r.datos),
                    toRecords: function () { return null; }
                };
            })
        };
        if (datos && datos.mensaje) { ev.mensaje = datos.mensaje; }
        return ev;
    }

    function quitar(lector) {
        var i = activos.indexOf(lector);
        if (i !== -1) { activos.splice(i, 1); }
    }

    // Dirección de esta misma plataforma que traiga la etiqueta (o null)
    function direccionPropia(datos) {
        var registros = (datos && datos.records) || [];
        for (var i = 0; i < registros.length; i++) {
            var r = registros[i];
            if (['url', 'absolute-url', 'smart-poster'].indexOf(r.recordType) === -1 || !r.texto) { continue; }
            try {
                var u = new URL(r.texto, window.location.href);
                if (u.origin === window.location.origin) { return u.href; }
            } catch (x) { /* no es una dirección */ }
        }
        return null;
    }

    function recibir(datos) {
        if (!datos) { return; }
        if (activos.length) {
            var ev = crearEvento('reading', datos);
            activos.slice().forEach(function (lector) { lector.dispatchEvent(ev); });
            return;
        }
        var destino = direccionPropia(datos);
        if (destino) { window.location.assign(destino); }
    }

    function recibirError(datos) {
        var ev = crearEvento('readingerror', datos);
        activos.slice().forEach(function (lector) { lector.dispatchEvent(ev); });
    }

    function escuchar() {
        var p = plugin();
        if (escuchando || !p || typeof p.addListener !== 'function') { return; }
        escuchando = true;
        p.addListener('lectura', function (datos) { recibir(datos); });
        p.addListener('lecturaError', function (datos) { recibirError(datos); });
    }

    function NDEFReader() {
        this.onreading = null;
        this.onreadingerror = null;
        this._oyentes = { reading: [], readingerror: [] };
    }

    NDEFReader.prototype.addEventListener = function (tipo, fn) {
        var lista = this._oyentes[tipo];
        if (lista && typeof fn === 'function' && lista.indexOf(fn) === -1) { lista.push(fn); }
    };

    NDEFReader.prototype.removeEventListener = function (tipo, fn) {
        var lista = this._oyentes[tipo];
        var i = lista ? lista.indexOf(fn) : -1;
        if (i !== -1) { lista.splice(i, 1); }
    };

    NDEFReader.prototype.dispatchEvent = function (ev) {
        var lector = this;
        var propio = lector['on' + ev.type];
        if (typeof propio === 'function') { propio.call(lector, ev); }
        (lector._oyentes[ev.type] || []).slice().forEach(function (fn) { fn.call(lector, ev); });
        return true;
    };

    NDEFReader.prototype.scan = function (opciones) {
        var lector = this;
        var senal = opciones && opciones.signal;
        if (senal && senal.aborted) { return Promise.reject(error('AbortError', 'Se canceló la lectura.')); }
        var p = plugin();
        if (!p || typeof p.disponible !== 'function') {
            return Promise.reject(error('NotSupportedError', 'Actualiza la app para leer etiquetas NFC.', 'sin-plugin'));
        }
        return Promise.resolve(p.disponible()).then(function (estado) {
            if (!estado || !estado.nfc) { throw error('NotSupportedError', 'Este teléfono no tiene NFC.', 'sin-nfc'); }
            if (!estado.encendido) { throw error('NotAllowedError', 'Enciende el NFC del teléfono.', 'nfc-apagado'); }
            if (senal && senal.aborted) { throw error('AbortError', 'Se canceló la lectura.'); }
            escuchar();
            if (activos.indexOf(lector) === -1) { activos.push(lector); }
            if (senal) { senal.addEventListener('abort', function () { quitar(lector); }); }
        }, function () {
            throw error('NotSupportedError', 'Actualiza la app para leer etiquetas NFC.', 'sin-plugin');
        });
    };

    NDEFReader.prototype.write = function () {
        return Promise.reject(error('NotSupportedError', 'Para grabar etiquetas usa una app de grabación de NFC: esta app solo las lee.', 'solo-lectura'));
    };

    NDEFReader.prototype.makeReadOnly = NDEFReader.prototype.write;

    try {
        Object.defineProperty(window, 'NDEFReader', { value: NDEFReader, writable: true, configurable: true });
    } catch (x) {
        window.NDEFReader = NDEFReader;
    }

    // Para el aviso «Enciende el NFC del teléfono» del lector universal
    window.LectorNfcApp = {
        abrirAjustes: function () {
            var p = plugin();
            if (!p || typeof p.abrirAjustesNfc !== 'function') { return Promise.reject(error('NotSupportedError', 'Actualiza la app para leer etiquetas NFC.', 'sin-plugin')); }
            return Promise.resolve(p.abrirAjustesNfc());
        }
    };

    // Desde que abre la página: una etiqueta con dirección de la plataforma se abre aquí aunque no se esté leyendo
    escuchar();
    if (!escuchando) { document.addEventListener('DOMContentLoaded', escuchar); }
})();
