/* ==========================================================================
   Plataforma — comportamiento común de todas las pantallas
   Sin código en línea (onclick) para poder aplicar una política de
   seguridad de contenido estricta.
   ========================================================================== */
(function () {
    'use strict';

    // El modo de pantalla (Normal, Sol, Noche) vive en modo-pantalla.js, que se carga en <head>

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
            var destino = document.getElementById(abrirEn.getAttribute('data-abrir-dialogo'));
            // Un mismo diálogo para varios contenedores (p. ej. elementos de cada área)
            if (destino && abrirEn.hasAttribute('data-padre')) {
                destino.querySelectorAll('[data-campo-padre]').forEach(function (c) { c.value = abrirEn.getAttribute('data-padre'); });
                destino.querySelectorAll('[data-padre-nombre]').forEach(function (c) { c.textContent = abrirEn.getAttribute('data-padre-nombre') || ''; });
            }
            abrir(destino);
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

/* ==========================================================================
   Edición genérica en diálogo y filtro de fichas (Usuarios y siguientes)
   ========================================================================== */
(function () {
    'use strict';

    // data-accion="editar-registro" data-dialogo="id" data-url="..." data-id="..." data-valores='{"campo": valor}'
    document.addEventListener('click', function (evento) {
        var boton = evento.target.closest('[data-accion="editar-registro"]');
        if (!boton) { return; }

        var dialogo = document.getElementById(boton.dataset.dialogo);
        var form = dialogo && dialogo.querySelector('form');
        if (!form) { return; }

        var valores = {};
        try { valores = JSON.parse(boton.dataset.valores || '{}'); } catch (e) { /* sin valores */ }

        form.action = boton.dataset.url;
        var marca = form.querySelector('[data-campo-dialogo]');
        if (marca) { marca.value = 'editar-' + boton.dataset.id; }

        form.querySelectorAll('input[name], select[name], textarea[name]').forEach(function (campo) {
            if (campo.type === 'hidden' || campo.name.charAt(0) === '_') { return; }
            if (campo.type === 'password') { campo.value = ''; return; }
            var valor = valores[campo.name];
            if (campo.type === 'checkbox') {
                campo.checked = !!valor;
            } else {
                campo.value = (valor === null || valor === undefined) ? '' : String(valor);
            }
        });

        if (typeof dialogo.showModal === 'function') { dialogo.showModal(); }
    });

    // Filtro de usuarios por texto y sede; se recuerda en la pestaña tras guardar
    var CLAVE = 'plataforma_filtro_usuarios';

    function filtrar() {
        var lista = document.getElementById('listaUsuarios');
        if (!lista) { return; }
        var texto = (document.getElementById('filtroTexto') || {}).value || '';
        var sede = (document.getElementById('filtroSede') || {}).value || '';
        texto = texto.toLowerCase().trim();
        var visibles = 0;

        lista.querySelectorAll('[data-usuario]').forEach(function (ficha) {
            var ok = (texto === '' || ficha.dataset.texto.indexOf(texto) !== -1)
                && (sede === '' || ficha.dataset.sede === sede);
            ficha.style.display = ok ? '' : 'none';
            if (ok) { visibles++; }
        });

        var vacio = document.getElementById('sinResultados');
        if (vacio) { vacio.hidden = visibles !== 0 || lista.querySelectorAll('[data-usuario]').length === 0; }

        try { sessionStorage.setItem(CLAVE, JSON.stringify({ texto: texto, sede: sede })); } catch (e) { /* sin almacenamiento */ }
    }

    document.addEventListener('input', function (e) { if (e.target.matches('[data-filtro-usuarios]')) { filtrar(); } });
    document.addEventListener('change', function (e) { if (e.target.matches('[data-filtro-usuarios]')) { filtrar(); } });

    document.addEventListener('DOMContentLoaded', function () {
        if (!document.getElementById('listaUsuarios')) { return; }
        try {
            var guardado = JSON.parse(sessionStorage.getItem(CLAVE) || 'null');
            if (guardado) {
                var t = document.getElementById('filtroTexto');
                var s = document.getElementById('filtroSede');
                if (t && guardado.texto) { t.value = guardado.texto; }
                if (s && guardado.sede) { s.value = guardado.sede; }
            }
        } catch (e) { /* valor guardado dañado: se ignora */ }
        filtrar();
    });
})();

/* ==========================================================================
   Identidad: vista previa en vivo y selector de color
   ========================================================================== */
(function () {
    'use strict';

    var COLOR = /^#[0-9a-fA-F]{6}$/;

    document.addEventListener('input', function (evento) {
        var el = evento.target;
        var vista = document.getElementById('vistaPrevia');
        if (!vista) { return; }

        // El cuadro de color y el campo de texto se mantienen iguales
        if (el.matches('[data-color-de]')) {
            var texto = document.getElementById(el.dataset.colorDe);
            if (texto) { texto.value = el.value; texto.dispatchEvent(new Event('input', { bubbles: true })); }
            return;
        }

        var campo = el.dataset.vistaPrevia;
        if (!campo) { return; }

        if (campo === 'color_primario' || campo === 'color_acento') {
            if (!COLOR.test(el.value)) { return; }
            vista.style.setProperty(campo === 'color_primario' ? '--vp-primario' : '--vp-acento', el.value);
            var cuadro = document.querySelector('[data-color-de="' + campo + '"]');
            if (cuadro) { cuadro.value = el.value; }
            return;
        }

        vista.querySelectorAll('[data-vp="' + campo + '"]').forEach(function (n) { n.textContent = el.value; });
    });

    // Vista previa del símbolo elegido, sin subirlo todavía
    document.addEventListener('change', function (evento) {
        var el = evento.target;
        if (!el.matches('[data-vista-previa-imagen]') || !el.files || !el.files[0]) { return; }
        var url = URL.createObjectURL(el.files[0]);
        document.querySelectorAll('[data-vp-simbolo]').forEach(function (n) {
            n.textContent = '';
            var img = document.createElement('img');
            img.src = url;
            img.alt = '';
            n.appendChild(img);
        });
    });
})();

/* ==========================================================================
   Filtro genérico de fichas: buscador + Todas / Activas / Inactivas
   (contenedor data-fichas="clave"; fichas con data-ficha, data-texto y data-estado)
   ========================================================================== */
(function () {
    'use strict';

    var ESTILOS = { todas: ['btn-dark', 'btn-outline-dark'], 1: ['btn-success', 'btn-outline-success'], 0: ['btn-danger', 'btn-outline-danger'] };

    function aplicar(contenedor) {
        var clave = contenedor.dataset.fichas;
        var buscador = document.querySelector('[data-filtro-texto="' + clave + '"]');
        var activo = document.querySelector('[data-filtro-estado="' + clave + '"][aria-pressed="true"]');
        var selSede = document.querySelector('[data-filtro-sede="' + clave + '"]');
        var texto = buscador ? buscador.value.toLowerCase().trim() : '';
        var estado = activo ? activo.dataset.valor : 'todas';
        var sede = selSede ? selSede.value : '';
        var visibles = 0;

        contenedor.querySelectorAll('[data-ficha]').forEach(function (f) {
            var ok = (texto === '' || (f.dataset.texto || '').indexOf(texto) !== -1)
                && (estado === 'todas' || f.dataset.estado === estado)
                && (sede === '' || f.dataset.sede === sede);
            f.style.display = ok ? '' : 'none';
            if (ok) { visibles++; }
        });

        var vacio = document.querySelector('[data-sin-resultados="' + clave + '"]');
        if (vacio) { vacio.hidden = visibles !== 0 || contenedor.querySelectorAll('[data-ficha]').length === 0; }

        document.querySelectorAll('[data-filtro-estado="' + clave + '"]').forEach(function (b) {
            var estilos = ESTILOS[b.dataset.valor] || ESTILOS.todas;
            var on = b.getAttribute('aria-pressed') === 'true';
            b.classList.toggle(estilos[0], on);
            b.classList.toggle(estilos[1], !on);
        });

        try { sessionStorage.setItem('plataforma_filtro_' + clave, JSON.stringify({ texto: texto, estado: estado })); } catch (e) {}
    }

    function contenedorDe(el) {
        var clave = el.dataset.filtroTexto || el.dataset.filtroEstado || el.dataset.filtroSede;
        return document.querySelector('[data-fichas="' + clave + '"]');
    }

    document.addEventListener('input', function (e) {
        if (e.target.matches('[data-filtro-texto]')) { var c = contenedorDe(e.target); if (c) { aplicar(c); } }
    });

    document.addEventListener('change', function (e) {
        if (e.target.matches('[data-filtro-sede]')) { var c = contenedorDe(e.target); if (c) { aplicar(c); } }
    });

    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-filtro-estado]');
        if (!b) { return; }
        document.querySelectorAll('[data-filtro-estado="' + b.dataset.filtroEstado + '"]').forEach(function (x) {
            x.setAttribute('aria-pressed', String(x === b));
        });
        var c = contenedorDe(b);
        if (c) { aplicar(c); }
    });

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-fichas]').forEach(function (c) {
            var clave = c.dataset.fichas;
            try {
                var g = JSON.parse(sessionStorage.getItem('plataforma_filtro_' + clave) || 'null');
                if (g) {
                    var t = document.querySelector('[data-filtro-texto="' + clave + '"]');
                    if (t && g.texto) { t.value = g.texto; }
                    var b = document.querySelector('[data-filtro-estado="' + clave + '"][data-valor="' + g.estado + '"]');
                    if (b) {
                        document.querySelectorAll('[data-filtro-estado="' + clave + '"]').forEach(function (x) { x.setAttribute('aria-pressed', String(x === b)); });
                    }
                }
            } catch (e) { /* ignorado */ }
            aplicar(c);
        });

        // Abrir y resaltar una ficha enlazada (#sede-12)
        if (location.hash && /^#[a-z]+-\d+$/.test(location.hash)) {
            var objetivo = document.querySelector(location.hash);
            if (objetivo) { objetivo.classList.add('ficha-resaltada'); objetivo.scrollIntoView({ block: 'center' }); }
        }
    });
})();

/* Crear por lote: cambiar entre rango numérico y lista de nombres */
document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-modo-lote]');
    if (!b) { return; }
    var form = b.closest('form');
    var modo = b.dataset.modoLote;
    form.querySelector('[data-modo-actual]').value = modo;
    form.querySelectorAll('[data-modo-lote]').forEach(function (x) {
        x.classList.toggle('btn-dark', x === b);
        x.classList.toggle('btn-outline-dark', x !== b);
    });
    form.querySelectorAll('[data-panel-lote]').forEach(function (p) { p.hidden = p.dataset.panelLote !== modo; });
});
