/*
 * Configuración en mosaico (docs/tecnico/configuracion.md).
 *  1. Enlaces viejos a la página única (/configuracion#cfg-…): llevan al tema
 *     que tiene esa tarjeta (/configuracion/{tema}#cfg-…), con el mensaje
 *     verde o rojo que traía la página.
 *  2. Buscador «¿Qué quieres ajustar?»: filtra las tarjetas de los temas y
 *     muestra los ajustes que coinciden al escribir. Sin JavaScript, el
 *     formulario manda ?q= y el servidor filtra lo mismo.
 */
(function () {
    'use strict';

    var raiz = document.querySelector('[data-configuracion]');
    if (!raiz) {
        return;
    }
    var CLAVE_AVISO = 'configuracion.aviso';

    function almacen() {
        try {
            return window.sessionStorage;
        } catch (e) {
            return null;
        }
    }

    // ---------------------------------------------------- 1. Anclas viejas
    var mapa = {};
    try {
        mapa = JSON.parse(raiz.getAttribute('data-anclas') || '{}') || {};
    } catch (e) {
        mapa = {};
    }
    var ancla = '';
    try {
        ancla = decodeURIComponent((window.location.hash || '').slice(1));
    } catch (e) {
        ancla = '';
    }
    if (ancla && !document.getElementById(ancla) && Object.prototype.hasOwnProperty.call(mapa, ancla)) {
        var s = almacen();
        var avisos = raiz.querySelectorAll('.alert.aviso');
        if (s && avisos.length) {
            var html = '';
            avisos.forEach(function (a) { html += a.outerHTML; });
            try { s.setItem(CLAVE_AVISO, html); } catch (e) { /* sin almacenamiento: el mensaje se pierde, el ajuste sí se guardó */ }
        }
        window.location.replace(mapa[ancla] + '#' + encodeURIComponent(ancla));
        return;
    }
    // Mensaje que traía la página única antes de llevarnos aquí
    var guardado = almacen();
    if (guardado) {
        var aviso = guardado.getItem(CLAVE_AVISO);
        if (aviso) {
            guardado.removeItem(CLAVE_AVISO);
            var plantilla = document.createElement('template');
            plantilla.innerHTML = aviso;
            // Solo los avisos de la plataforma (mismo origen, de esta misma sesión)
            var nodos = Array.prototype.filter.call(plantilla.content.children, function (n) { return n.classList.contains('aviso'); });
            nodos.reverse().forEach(function (n) { raiz.insertBefore(n, raiz.firstChild); });
        }
    }

    // ---------------------------------------------------------- 2. Buscador
    var formulario = raiz.querySelector('[data-buscador-config]');
    if (!formulario) {
        return;
    }
    var campo = formulario.querySelector('input[name="q"]');
    var resultados = raiz.querySelector('[data-resultados-config]');
    var sinResultados = raiz.querySelector('[data-sin-resultados]');
    var textoBuscado = raiz.querySelector('[data-texto-buscado]');
    var temas = raiz.querySelectorAll('.mosaico-config li[data-buscar]');
    var ajustes = resultados ? resultados.querySelectorAll('li[data-buscar]') : [];

    function normalizar(texto) {
        return (texto || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/\s+/g, ' ').trim();
    }

    function coincide(busqueda, texto) {
        var t = normalizar(texto);
        return busqueda.split(' ').every(function (p) { return p === '' || t.indexOf(p) !== -1; });
    }

    function filtrar() {
        var busqueda = normalizar(campo.value);
        var temasConAjuste = {};
        var hayAjuste = false;
        Array.prototype.forEach.call(ajustes, function (li) {
            var si = busqueda !== '' && coincide(busqueda, li.getAttribute('data-buscar'));
            li.hidden = !si;
            if (si) {
                hayAjuste = true;
                temasConAjuste[li.getAttribute('data-tema')] = true;
            }
        });
        var hayTema = false;
        Array.prototype.forEach.call(temas, function (li) {
            var si = busqueda === '' || coincide(busqueda, li.getAttribute('data-buscar')) || temasConAjuste[li.getAttribute('data-tema')] === true;
            li.hidden = !si;
            hayTema = hayTema || si;
        });
        if (resultados) {
            resultados.hidden = busqueda === '';
            resultados.querySelector('ul').hidden = !hayAjuste;
        }
        if (sinResultados) {
            sinResultados.hidden = busqueda === '' || hayAjuste || hayTema;
        }
        if (textoBuscado) {
            textoBuscado.textContent = campo.value.trim();
        }
    }

    campo.addEventListener('input', filtrar);
    formulario.addEventListener('submit', function (e) {
        // Ya está filtrado; con un solo ajuste encontrado, Enter lleva directo a él
        e.preventDefault();
        filtrar();
        var visibles = Array.prototype.filter.call(ajustes, function (li) { return !li.hidden; });
        if (visibles.length === 1) {
            window.location.href = visibles[0].querySelector('a').href;
        }
    });
    filtrar();
}());
