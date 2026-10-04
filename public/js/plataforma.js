/* ==========================================================================
   Plataforma — comportamiento común de todas las pantallas
   Sin código en línea (onclick) para poder aplicar una política de
   seguridad de contenido estricta.
   ========================================================================== */
(function () {
    'use strict';

    var CLAVE_CONTRASTE = 'plataforma_alto_contraste';

    /* ---------- Alto contraste (exteriores) ---------- */
    function leerContraste() {
        try { return localStorage.getItem(CLAVE_CONTRASTE) === '1'; } catch (e) { return false; }
    }

    function aplicarContraste(activo) {
        document.body.classList.toggle('alto-contraste', activo);
        document.querySelectorAll('[data-accion="alto-contraste"]').forEach(function (boton) {
            boton.classList.toggle('activo', activo);
            boton.setAttribute('aria-pressed', activo ? 'true' : 'false');
        });
    }

    /* ---------- Mostrar u ocultar contraseña ---------- */
    function alternarContrasena(boton) {
        var campo = document.getElementById(boton.getAttribute('data-objetivo'));
        var icono = boton.querySelector('i');
        if (!campo) { return; }
        var mostrar = campo.type === 'password';
        campo.type = mostrar ? 'text' : 'password';
        if (icono) {
            icono.classList.toggle('bi-eye', !mostrar);
            icono.classList.toggle('bi-eye-slash', mostrar);
        }
    }

    document.addEventListener('click', function (evento) {
        var objetivo = evento.target.closest('[data-accion]');
        if (!objetivo) { return; }

        switch (objetivo.getAttribute('data-accion')) {
            case 'alto-contraste':
                evento.preventDefault();
                var activo = !document.body.classList.contains('alto-contraste');
                try { localStorage.setItem(CLAVE_CONTRASTE, activo ? '1' : '0'); } catch (e) { /* sin almacenamiento */ }
                aplicarContraste(activo);
                break;
            case 'ver-contrasena':
                alternarContrasena(objetivo);
                break;
            case 'seguir-en-sesion':
                if (window.plataformaSesion) { window.plataformaSesion.seguir(); }
                break;
        }
    });

    /* ---------- Sesión: latido mientras hay actividad y aviso antes de expirar ----------
       Mientras haya actividad real (teclear, clic, mover el mouse) un latido
       silencioso cada 3 minutos mantiene viva la sesión, así no se pierde un
       formulario largo a medio llenar. Si no hay actividad, 2 minutos antes
       del cierre aparece un aviso para confirmar que sigues ahí. */
    function vigilarSesion() {
        var datos = document.body.dataset;
        if (!datos.inactividad || !datos.latido) { return; }

        var LIMITE = parseInt(datos.inactividad, 10);
        var AVISO = parseInt(datos.aviso || '120', 10);
        var LATIDO_SI_ACTIVO = 180;
        var REVISAR_CADA_MS = 30 * 1000;

        var ultimaActividad = Date.now();
        var ultimoLatido = Date.now();
        var avisoVisible = false;
        var modal = null;

        ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'].forEach(function (tipo) {
            document.addEventListener(tipo, function () { ultimaActividad = Date.now(); }, { passive: true });
        });

        function segundos(desde) { return (Date.now() - desde) / 1000; }

        function latido() {
            ultimoLatido = Date.now();
            fetch(datos.latido, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (r) {
                    if (r.status === 401) { window.location.href = datos.expirada; return null; }
                    return r.json();
                })
                .then(function (d) { if (d && d.ok) { ocultarAviso(); } })
                .catch(function () { /* sin red: decide el chequeo normal */ });
        }

        function mostrarAviso() {
            var el = document.getElementById('modalSesionPorExpirar');
            avisoVisible = true;
            if (!el || typeof bootstrap === 'undefined') { return; }
            modal = bootstrap.Modal.getOrCreateInstance(el);
            modal.show();
        }

        function ocultarAviso() {
            if (!avisoVisible) { return; }
            avisoVisible = false;
            if (modal) { modal.hide(); }
        }

        window.plataformaSesion = {
            seguir: function () { ultimaActividad = Date.now(); latido(); }
        };

        setInterval(function () {
            var inactivo = segundos(ultimaActividad);
            if (inactivo >= LIMITE) {
                window.location.href = datos.expirada;
            } else if (inactivo >= LIMITE - AVISO) {
                if (!avisoVisible) { mostrarAviso(); }
            } else if (segundos(ultimoLatido) >= LATIDO_SI_ACTIVO && inactivo <= LATIDO_SI_ACTIVO) {
                latido();
            }
        }, REVISAR_CADA_MS);
    }

    document.addEventListener('DOMContentLoaded', function () {
        aplicarContraste(leerContraste());
        vigilarSesion();
    });
})();

/* ==========================================================================
   Pantallas de administración: diálogos, confirmaciones y matriz de permisos
   ========================================================================== */
(function () {
    'use strict';

    function abrir(dialogo) {
        if (dialogo && typeof dialogo.showModal === 'function' && !dialogo.open) { dialogo.showModal(); }
    }

    document.addEventListener('click', function (evento) {
        var abrirEn = evento.target.closest('[data-abrir-dialogo]');
        if (abrirEn) {
            abrir(document.getElementById(abrirEn.getAttribute('data-abrir-dialogo')));
            return;
        }

        var cerrar = evento.target.closest('[data-cerrar-dialogo]');
        if (cerrar) {
            var d = cerrar.closest('dialog');
            if (d) { d.close(); }
            return;
        }

        // Editar rol: se llena el diálogo con los datos de la ficha
        var editar = evento.target.closest('[data-accion="editar-rol"]');
        if (editar) {
            var dialogo = document.getElementById('dialogoEditarRol');
            var form = document.getElementById('formEditarRol');
            if (!dialogo || !form) { return; }
            form.action = editar.dataset.url;
            form.querySelector('[data-campo="dialogo"]').value = 'editar-' + editar.dataset.id;
            form.querySelector('[data-campo="nombre"]').value = editar.dataset.nombre || '';
            form.querySelector('[data-campo="descripcion"]').value = editar.dataset.descripcion || '';
            form.querySelector('[data-campo="nivel"]').value = editar.dataset.nivel || '';
            form.querySelector('[data-campo="activo"]').checked = editar.dataset.activo === '1';
            abrir(dialogo);
            return;
        }

        // Matriz: toda la celda es área de toque (44 px), no solo la casilla
        var celda = evento.target.closest('td.celda-casilla');
        if (celda && evento.target === celda) {
            var casilla = celda.querySelector('input[type="checkbox"]:not(:disabled)');
            if (casilla) { casilla.click(); }
        }
    });

    // Confirmación antes de enviar formularios sensibles
    document.addEventListener('submit', function (evento) {
        var mensaje = evento.target.getAttribute('data-confirmar');
        if (mensaje && !window.confirm(mensaje)) { evento.preventDefault(); }
    });

    document.addEventListener('change', function (evento) {
        var el = evento.target;

        if (el.matches('[data-enviar-al-cambiar]')) {
            el.form.submit();
            return;
        }

        // Matriz de permisos: cualquier acción implica "Ver"; quitar "Ver" quita todo
        if (el.matches('[data-accion-permiso]')) {
            var fila = el.closest('[data-fila-permisos]');
            if (!fila) { return; }
            var ver = fila.querySelector('[data-accion-permiso="ver"]');
            if (el.dataset.accionPermiso === 'ver') {
                if (!el.checked) {
                    fila.querySelectorAll('[data-accion-permiso]:not(:disabled)').forEach(function (c) { c.checked = false; });
                }
            } else if (el.checked && ver && !ver.disabled) {
                ver.checked = true;
            }
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        // Un formulario que regresó con errores vuelve a abrir su diálogo
        document.querySelectorAll('dialog[data-abrir-al-cargar]').forEach(abrir);
    });
})();
