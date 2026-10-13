/* ==========================================================================
   Zonas y edificios: «Mover a…» y «Eliminar definitivamente las marcadas».
   (Sin JavaScript en línea: lo cargan las vistas de organizacion/espacios.)

   Mover a…: botón [data-accion="mover-espacio"] data-url data-destinos data-nombre.
     Pide al servidor los lugares válidos (GET data-destinos) y llena la lista
     del diálogo #dialogoMoverEspacio; el formulario se envía a data-url (PATCH).

   Eliminar varias: casillas [data-marcar-espacio] (form="formEliminarVarias"),
     «Marcar todas» [data-marcar-todas-espacios] (solo las visibles con el
     buscador), botón [data-abrir-eliminar-varias] y diálogo #dialogoEliminarVarias
     que pide escribir la cantidad marcada o ELIMINAR.
   ========================================================================== */
(function () {
    'use strict';

    if (window.espaciosJsCargado) { return; }
    window.espaciosJsCargado = true;

    // ---------------------------------------------------------------- Mover a…
    function pasoMover(dialogo, nombre) {
        dialogo.querySelectorAll('[data-mover-paso]').forEach(function (p) { p.hidden = p.getAttribute('data-mover-paso') !== nombre; });
    }

    function abrirMover(boton) {
        var dialogo = document.getElementById('dialogoMoverEspacio');
        if (!dialogo) { return; }
        var form = dialogo.querySelector('[data-mover-form]');
        var lista = dialogo.querySelector('[data-mover-destino]');
        var enviar = dialogo.querySelector('[data-mover-enviar]');
        var mensaje = dialogo.querySelector('[data-mover-mensaje]');

        form.setAttribute('action', boton.getAttribute('data-url'));
        dialogo.querySelector('[data-mover-nombre]').textContent = boton.getAttribute('data-nombre') || '';
        lista.textContent = '';
        enviar.disabled = true;
        mensaje.textContent = 'No hay otro lugar activo del nivel correcto en esta sede.';
        pasoMover(dialogo, 'cargando');
        if (!dialogo.open && typeof dialogo.showModal === 'function') { dialogo.showModal(); }

        fetch(boton.getAttribute('data-destinos'), { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { if (!r.ok) { throw new Error(String(r.status)); } return r.json(); })
            .then(function (datos) {
                var destinos = datos.destinos || [];
                if (destinos.length === 0) { pasoMover(dialogo, 'vacio'); return; }
                var vacia = document.createElement('option');
                vacia.value = '';
                vacia.textContent = '— Elige el nuevo lugar —';
                lista.appendChild(vacia);
                destinos.forEach(function (d) {
                    var opcion = document.createElement('option');
                    opcion.value = String(d.id);
                    opcion.textContent = d.nombre;
                    lista.appendChild(opcion);
                });
                pasoMover(dialogo, 'elegir');
                lista.focus();
            })
            .catch(function () {
                mensaje.textContent = 'No se pudieron cargar los lugares. Revisa tu conexión e intenta de nuevo.';
                pasoMover(dialogo, 'vacio');
            });
    }

    // ------------------------------------------------- Eliminar varias a la vez
    function visible(casilla) {
        var ficha = casilla.closest('[data-ficha]');
        return !ficha || ficha.style.display !== 'none';
    }

    function marcadas() {
        return Array.prototype.filter.call(document.querySelectorAll('[data-marcar-espacio]'), function (c) { return c.checked; });
    }

    function actualizarBarra() {
        var n = marcadas().length;
        var texto = document.querySelector('[data-marcadas-texto]');
        var boton = document.querySelector('[data-abrir-eliminar-varias]');
        if (texto) { texto.textContent = n === 0 ? 'Ninguna marcada' : (n === 1 ? '1 marcada' : n + ' marcadas'); }
        if (boton) { boton.disabled = n === 0; }
        var todas = document.querySelector('[data-marcar-todas-espacios]');
        if (todas) {
            var visibles = Array.prototype.filter.call(document.querySelectorAll('[data-marcar-espacio]'), visible);
            todas.checked = visibles.length > 0 && visibles.every(function (c) { return c.checked; });
        }
    }

    function confirmacionValida(dialogo) {
        var entrada = dialogo.querySelector('[data-eliminar-varias-entrada]');
        var valor = String(entrada.value || '').trim().toUpperCase();
        return valor === 'ELIMINAR' || valor === String(marcadas().length);
    }

    function abrirEliminarVarias() {
        var dialogo = document.getElementById('dialogoEliminarVarias');
        var n = marcadas().length;
        if (!dialogo || n === 0) { return; }
        dialogo.querySelectorAll('[data-marcadas-cantidad], [data-marcadas-clave]').forEach(function (e) { e.textContent = String(n); });
        dialogo.querySelector('[data-eliminar-varias-entrada]').value = '';
        dialogo.querySelector('[data-eliminar-varias-enviar]').disabled = true;
        if (!dialogo.open && typeof dialogo.showModal === 'function') { dialogo.showModal(); }
        dialogo.querySelector('[data-eliminar-varias-entrada]').focus();
    }

    document.addEventListener('click', function (evento) {
        var mover = evento.target.closest('[data-accion="mover-espacio"]');
        if (mover) { abrirMover(mover); return; }
        if (evento.target.closest('[data-abrir-eliminar-varias]')) { abrirEliminarVarias(); }
    });

    document.addEventListener('change', function (evento) {
        var t = evento.target;
        if (t.matches('[data-mover-destino]')) {
            var dialogo = t.closest('dialog');
            if (dialogo) { dialogo.querySelector('[data-mover-enviar]').disabled = t.value === ''; }
            return;
        }
        if (t.matches('[data-marcar-todas-espacios]')) {
            document.querySelectorAll('[data-marcar-espacio]').forEach(function (c) { if (visible(c)) { c.checked = t.checked; } });
            actualizarBarra();
            return;
        }
        if (t.matches('[data-marcar-espacio]')) { actualizarBarra(); }
    });

    document.addEventListener('input', function (evento) {
        if (!evento.target.matches('[data-eliminar-varias-entrada]')) { return; }
        var dialogo = evento.target.closest('dialog');
        dialogo.querySelector('[data-eliminar-varias-enviar]').disabled = !confirmacionValida(dialogo);
    });

    document.addEventListener('submit', function (evento) {
        if (evento.target.id !== 'formEliminarVarias') { return; }
        if (!confirmacionValida(evento.target.closest('dialog'))) { evento.preventDefault(); }
    });

    document.addEventListener('DOMContentLoaded', actualizarBarra);
}());
