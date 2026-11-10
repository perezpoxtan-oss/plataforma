/* ==========================================================================
   Página pública de planes: menú del celular y calculadora en vivo.
   Sin JavaScript en línea (CSP). El cálculo vive en landing-calculo.js.
   Sin JavaScript todo sigue funcionando: la calculadora envía lo elegido
   a /contratar y el servidor muestra el desglose.
   ========================================================================== */
(function () {
    'use strict';

    document.documentElement.classList.remove('sin-js');

    // ---------- Menú del celular ----------
    var botonMenu = document.querySelector('[data-menu-boton]');
    var menu = document.getElementById('lp-menu');
    if (botonMenu && menu) {
        var cerrar = function () { menu.classList.remove('abierta'); botonMenu.setAttribute('aria-expanded', 'false'); };
        botonMenu.addEventListener('click', function () {
            var abierto = menu.classList.toggle('abierta');
            botonMenu.setAttribute('aria-expanded', abierto ? 'true' : 'false');
        });
        menu.addEventListener('click', function (e) { if (e.target.closest('a')) { cerrar(); } });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { cerrar(); } });
    }

    // ---------- Calculadora ----------
    if (!window.CalculoPlanes) { return; }
    var C = window.CalculoPlanes;

    function texto(etiqueta, contenido, clase) {
        var el = document.createElement(etiqueta);
        if (clase) { el.className = clase; }
        el.textContent = contenido;
        return el;
    }

    function renglon(r) {
        var li = document.createElement('li');
        var nombre = document.createElement('span');
        nombre.appendChild(document.createTextNode(r.nombre + ' '));
        var detalle = r.cotizar ? '' : (r.cantidad > 1 ? '× ' + r.cantidad + ' ' : '') + (r.modalidad === 'unico' ? 'pago único' : 'al mes');
        if (detalle) { nombre.appendChild(texto('span', detalle, 'lp-cantidad')); }
        li.appendChild(nombre);
        li.appendChild(texto('span', r.cotizar ? 'A cotizar' : C.formato(r.importe), 'lp-cifra'));
        return li;
    }

    function pintar(ambito, catalogo, r) {
        var lista = ambito.querySelector('[data-resumen-renglones]');
        if (lista) {
            while (lista.firstChild) { lista.removeChild(lista.firstChild); }
            r.renglones.forEach(function (x) { lista.appendChild(renglon(x)); });
            if (r.renglones.length === 0) { lista.appendChild(texto('li', 'Elige un plan para ver el costo.')); }
        }
        var poner = function (clave, valor) {
            ambito.querySelectorAll('[data-resumen="' + clave + '"]').forEach(function (el) { el.textContent = valor; });
        };
        poner('base', C.formato(r.mensual.subtotal * r.meses));
        poner('descuento-anual', '−' + C.formato(r.descuento_anual));
        poner('descuento-promocion', '−' + C.formato(r.descuento_promocion));
        poner('promocion-nombre', r.promocion ? r.promocion.nombre : '');
        poner('promocion-meses', r.promocion && r.promocion.meses !== null ? ' (primeros ' + r.promocion.meses + (r.promocion.meses === 1 ? ' mes)' : ' meses)') : '');
        poner('periodo-iva', C.formato(r.periodo.iva));
        poner('periodo-total', C.formato(r.periodo.total));
        poner('regular-total', C.formato(r.periodo_regular.total));
        ambito.querySelectorAll('[data-resumen-texto="base"]').forEach(function (el) { el.textContent = r.meses === 12 ? '12 meses' : 'Al mes'; });
        ambito.querySelectorAll('[data-resumen-texto="periodo"]').forEach(function (el) { el.textContent = r.meses === 12 ? 'al año' : 'al mes'; });
        poner('unico-subtotal', C.formato(r.unico.subtotal));
        poner('unico-iva', C.formato(r.unico.iva));
        poner('unico-total', C.formato(r.unico.total));
        poner('sedes-cobradas', String(r.sedes_cobradas));
        var mostrar = function (clave, visible) {
            ambito.querySelectorAll('[data-resumen-si="' + clave + '"]').forEach(function (el) { el.hidden = !visible; });
        };
        mostrar('unico', r.unico.subtotal > 0);
        mostrar('cotizar', r.requiere_cotizacion);
        mostrar('minimas', r.sedes_cobradas > r.sedes);
        mostrar('mensual', !r.requiere_cotizacion || r.mensual.subtotal > 0);
        mostrar('anual', r.descuento_anual > 0);
        mostrar('promocion', r.descuento_promocion > 0);
        mostrar('regular', r.periodo.total !== r.periodo_regular.total);
    }

    function normalizarCodigo(valor) {
        return String(valor || '').replace(/\s+/g, '').toUpperCase();
    }

    function iniciar(ambito) {
        var catalogo;
        try { catalogo = JSON.parse(ambito.getAttribute('data-catalogo') || '{}'); } catch (x) { return; }
        if (!catalogo.planes) { return; }
        var sedes = ambito.querySelector('[data-sedes]');
        // Promoción validada por el servidor (al abrir /contratar con su código); si cambian el código, se confirma al enviar
        var promocion = null;
        try { promocion = JSON.parse(ambito.getAttribute('data-promocion') || 'null'); } catch (x) { promocion = null; }
        var campoCodigo = ambito.querySelector('[data-codigo]');
        var ayudaCodigo = ambito.querySelector('[data-codigo-ayuda]');
        var codigoValidado = campoCodigo ? normalizarCodigo(campoCodigo.value) : '';

        function periodicidad() {
            var marcada = ambito.querySelector('input[name="periodicidad"]:checked');
            return marcada ? marcada.value : 'mensual';
        }

        function promocionVigente(planId) {
            if (!promocion || !campoCodigo || normalizarCodigo(campoCodigo.value) !== codigoValidado) { return null; }
            if (promocion.periodicidad && promocion.periodicidad !== 'ambas' && promocion.periodicidad !== periodicidad()) { return null; }
            if (promocion.planes && promocion.planes.length > 0 && promocion.planes.indexOf(planId) === -1) { return null; }
            return promocion;
        }

        function planElegido() {
            var marcado = ambito.querySelector('input[name="plan_id"]:checked');
            return marcado ? parseInt(marcado.value, 10) : null;
        }

        function recalcular() {
            var planId = planElegido();
            var plan = null;
            catalogo.planes.forEach(function (p) { if (p.id === planId) { plan = p; } });
            // Paquetes que no se suman al plan: se desmarcan y se explica por qué
            ambito.querySelectorAll('input[name="paquetes[]"]').forEach(function (casilla) {
                var paquete = null;
                catalogo.paquetes.forEach(function (q) { if (q.id === parseInt(casilla.value, 10)) { paquete = q; } });
                if (!paquete) { return; }
                var ok = C.compatible(paquete, plan);
                casilla.disabled = !ok;
                if (!ok) { casilla.checked = false; }
                var opcion = casilla.closest('.lp-opcion');
                if (opcion) {
                    opcion.classList.toggle('no-disponible', !ok);
                    var aviso = opcion.querySelector('[data-aviso-incompatible]');
                    if (aviso) { aviso.hidden = ok; }
                }
            });
            var ids = [];
            ambito.querySelectorAll('input[name="paquetes[]"]:checked').forEach(function (c) { ids.push(c.value); });
            pintar(ambito, catalogo, C.calcular(catalogo, planId, sedes ? sedes.value : 1, ids, periodicidad(), promocionVigente(planId)));
        }

        if (campoCodigo && ayudaCodigo) {
            campoCodigo.addEventListener('input', function () {
                if (normalizarCodigo(campoCodigo.value) !== codigoValidado) {
                    campoCodigo.removeAttribute('aria-invalid');
                    ayudaCodigo.textContent = campoCodigo.value.trim() === ''
                        ? 'Si tienes uno, escríbelo: el descuento se confirma al enviar.'
                        : 'El descuento de este código se confirma al enviar.';
                }
                recalcular();
            });
        }

        ambito.addEventListener('change', recalcular);
        ambito.addEventListener('input', function (e) { if (e.target === sedes) { recalcular(); } });
        ambito.querySelectorAll('[data-sedes-paso]').forEach(function (b) {
            b.addEventListener('click', function () {
                if (!sedes) { return; }
                var actual = parseInt(sedes.value, 10) || 1;
                sedes.value = String(Math.max(1, Math.min(999, actual + parseInt(b.getAttribute('data-sedes-paso'), 10))));
                recalcular();
            });
        });
        recalcular();
    }

    document.querySelectorAll('[data-calculadora]').forEach(iniciar);
}());
