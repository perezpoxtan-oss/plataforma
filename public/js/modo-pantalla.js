/* ==========================================================================
   Modo de pantalla: Normal → Sol → Noche → Normal
   - Sol: alto contraste para exteriores (pleno sol).
   - Noche: colores oscuros para turnos nocturnos.
   Se carga en <head> (sin defer) para poner la clase en <html> antes de
   pintar la página y evitar el destello del modo anterior. El ajuste se
   recuerda en el equipo (localStorage); sin almacenamiento queda Normal.
   ========================================================================== */
(function () {
    'use strict';

    var CLAVE = 'plataforma_modo_pantalla';
    var CLAVE_ANTERIOR = 'plataforma_alto_contraste'; // versión con solo "alto contraste"
    var ORDEN = ['normal', 'sol', 'noche'];
    var MODOS = {
        normal: { nombre: 'Normal', icono: 'bi-sun' },
        sol: { nombre: 'Sol', icono: 'bi-brightness-high-fill' },
        noche: { nombre: 'Noche', icono: 'bi-moon-stars-fill' }
    };

    function leer() {
        try {
            var modo = localStorage.getItem(CLAVE);
            if (MODOS.hasOwnProperty(modo)) { return modo; }

            // Quien tenía activado el alto contraste pasa al modo Sol
            var anterior = localStorage.getItem(CLAVE_ANTERIOR);
            if (anterior !== null) {
                localStorage.removeItem(CLAVE_ANTERIOR);
                if (anterior === '1') {
                    localStorage.setItem(CLAVE, 'sol');
                    return 'sol';
                }
            }
        } catch (e) { /* sin almacenamiento: modo Normal */ }

        return 'normal';
    }

    function guardar(modo) {
        try { localStorage.setItem(CLAVE, modo); } catch (e) { /* sin almacenamiento */ }
    }

    function siguiente(modo) {
        return ORDEN[(ORDEN.indexOf(modo) + 1) % ORDEN.length];
    }

    function actualizarBotones(modo) {
        var info = MODOS[modo];
        var proximo = MODOS[siguiente(modo)].nombre;

        document.querySelectorAll('[data-accion="modo-pantalla"]').forEach(function (boton) {
            boton.setAttribute('title', 'Modo de pantalla: ' + info.nombre);
            boton.setAttribute('aria-label', 'Modo de pantalla: ' + info.nombre + '. Cambiar a ' + proximo);
            boton.setAttribute('data-modo', modo);
            boton.classList.toggle('activo', modo !== 'normal');

            var icono = boton.querySelector('i.bi');
            if (icono) { icono.className = 'bi ' + info.icono; }

            var etiqueta = boton.querySelector('[data-modo-etiqueta]');
            if (etiqueta) { etiqueta.textContent = 'Modo de pantalla: ' + info.nombre; }
        });
    }

    function aplicar(modo) {
        var raiz = document.documentElement;
        raiz.classList.remove('modo-sol', 'modo-noche');
        if (modo !== 'normal') { raiz.classList.add('modo-' + modo); }
        raiz.setAttribute('data-modo-pantalla', modo);
        // Bootstrap (alertas, ventanas, menú lateral) tiene su propio tema oscuro
        if (modo === 'noche') { raiz.setAttribute('data-bs-theme', 'dark'); } else { raiz.removeAttribute('data-bs-theme'); }
        actualizarBotones(modo);
    }

    // Lo antes posible: antes de que se pinte el <body>
    var actual = leer();
    aplicar(actual);

    document.addEventListener('click', function (evento) {
        var boton = evento.target.closest('[data-accion="modo-pantalla"]');
        if (!boton) { return; }
        evento.preventDefault();
        actual = siguiente(actual);
        guardar(actual);
        aplicar(actual);
    });

    document.addEventListener('DOMContentLoaded', function () { actualizarBotones(actual); });

    window.plataformaModoPantalla = {
        actual: function () { return actual; },
        cambiar: function (modo) {
            if (!MODOS.hasOwnProperty(modo)) { return; }
            actual = modo;
            guardar(modo);
            aplicar(modo);
        }
    };
})();
