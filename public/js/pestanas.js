/* ==========================================================================
   Pestañas accesibles (ficha del colaborador y las que se agreguen).

   Marcado:
     <div role="tablist" data-pestanas data-inicial="datos">
       <button role="tab" id="pestana-datos" aria-controls="panel-datos" data-pestana="datos">…</button>
     </div>
     <section role="tabpanel" id="panel-datos" aria-labelledby="pestana-datos" data-panel="datos">…</section>

   - Clic o toque: abre la pestaña y deja la dirección con #clave (se puede
     copiar o recargar y abre la misma pestaña).
   - Teclado: flechas izquierda/derecha, Inicio y Fin
     mueven y abren; solo la pestaña activa recibe el foco con Tab.
   - Dirección con #clave (#expediente) o con el id de algo que está dentro
     de un panel (#documentos): abre esa pestaña al cargar y al cambiar.
   Sin JavaScript en línea (CSP): este archivo se carga con @push('scripts').
   ========================================================================== */
(function () {
    'use strict';

    function botones(grupo) {
        return Array.prototype.slice.call(grupo.querySelectorAll('[role="tab"][data-pestana]'));
    }

    function panel(clave) {
        return document.querySelector('[role="tabpanel"][data-panel="' + clave + '"]');
    }

    function activar(grupo, clave, opciones) {
        opciones = opciones || {};
        var lista = botones(grupo);
        var elegido = null;
        lista.forEach(function (b) { if (b.getAttribute('data-pestana') === clave) { elegido = b; } });
        if (!elegido) { return false; }

        lista.forEach(function (b) {
            var activa = b === elegido;
            b.classList.toggle('activa', activa);
            b.setAttribute('aria-selected', activa ? 'true' : 'false');
            b.setAttribute('tabindex', activa ? '0' : '-1');
            var p = panel(b.getAttribute('data-pestana'));
            if (p) { p.hidden = !activa; }
        });
        if (opciones.foco) { elegido.focus(); }
        if (opciones.recordar) {
            try { window.history.replaceState(null, '', '#' + clave); } catch (x) { /* navegador antiguo */ }
        }
        // En el celular la barra se desliza: la pestaña elegida queda a la vista
        if (elegido.scrollIntoView && grupo.scrollWidth > grupo.clientWidth) {
            try { elegido.scrollIntoView({ block: 'nearest', inline: 'nearest' }); } catch (x) { /* sin opciones */ }
        }
        return true;
    }

    // #clave de una pestaña, o el id de algo dentro de un panel
    function desdeDireccion(grupo) {
        var hash = decodeURIComponent((window.location.hash || '').replace('#', ''));
        if (hash === '') { return false; }
        if (activar(grupo, hash)) { return true; }
        var destino = /^[A-Za-z][\w-]*$/.test(hash) ? document.getElementById(hash) : null;
        var contenedor = destino && destino.closest('[role="tabpanel"][data-panel]');
        if (contenedor && activar(grupo, contenedor.getAttribute('data-panel'))) {
            destino.scrollIntoView();
            return true;
        }
        return false;
    }

    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-pestanas] [role="tab"][data-pestana]');
        if (!b) { return; }
        activar(b.closest('[data-pestanas]'), b.getAttribute('data-pestana'), { recordar: true });
    });

    document.addEventListener('keydown', function (e) {
        var b = e.target.closest && e.target.closest('[data-pestanas] [role="tab"][data-pestana]');
        if (!b) { return; }
        var grupo = b.closest('[data-pestanas]');
        var lista = botones(grupo);
        var i = lista.indexOf(b);
        var siguiente = null;
        switch (e.key) {
            case 'ArrowRight': siguiente = lista[(i + 1) % lista.length]; break;
            case 'ArrowLeft': siguiente = lista[(i - 1 + lista.length) % lista.length]; break;
            case 'Home': siguiente = lista[0]; break;
            case 'End': siguiente = lista[lista.length - 1]; break;
            default: return;
        }
        e.preventDefault();
        activar(grupo, siguiente.getAttribute('data-pestana'), { foco: true, recordar: true });
    });

    window.addEventListener('hashchange', function () {
        document.querySelectorAll('[data-pestanas]').forEach(desdeDireccion);
    });

    function iniciar() {
        document.querySelectorAll('[data-pestanas]').forEach(function (grupo) {
            if (!desdeDireccion(grupo)) {
                activar(grupo, grupo.getAttribute('data-inicial') || (botones(grupo)[0] && botones(grupo)[0].getAttribute('data-pestana')));
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
