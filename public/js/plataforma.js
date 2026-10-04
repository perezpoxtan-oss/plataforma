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
            // Listas de casillas (name="sedes[]") reciben un arreglo de ids
            if (campo.type === 'checkbox' && campo.name.slice(-2) === '[]') {
                var lista = valores[campo.name.slice(0, -2)];
                campo.checked = Array.isArray(lista) && lista.map(String).indexOf(campo.value) !== -1;
                return;
            }
            var valor = valores[campo.name];
            if (campo.type === 'checkbox') {
                campo.checked = !!valor;
            } else {
                campo.value = (valor === null || valor === undefined) ? '' : String(valor);
                if (campo.hasAttribute('data-nombres-existentes')) { campo.dataset.original = campo.value; }
            }
        });
        // Que los campos dependientes y avisos reflejen los valores cargados
        form.querySelectorAll('[data-oculta-si-marcado], [data-nombres-existentes]').forEach(function (c) {
            c.dispatchEvent(new Event(c.type === 'checkbox' ? 'change' : 'input', { bubbles: true }));
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
   (contenedor data-fichas="clave"; fichas con data-ficha, data-texto y data-estado;
   data-sede puede ser un id o varios separados por espacio)
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
        var pTipo = document.querySelector('[data-filtro-tipo="' + clave + '"][aria-pressed="true"]');
        var tipo = pTipo ? pTipo.dataset.valor : '';
        var visibles = 0;

        contenedor.querySelectorAll('[data-ficha]').forEach(function (f) {
            var ok = (texto === '' || (f.dataset.texto || '').indexOf(texto) !== -1)
                && (estado === 'todas' || f.dataset.estado === estado)
                // data-sede admite una lista separada por espacios (p. ej. turnos en varias sedes)
                && (sede === '' || (' ' + (f.dataset.sede || '') + ' ').indexOf(' ' + sede + ' ') !== -1)
                && (tipo === '' || f.dataset.tipo === tipo);
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
        var clave = el.dataset.filtroTexto || el.dataset.filtroEstado || el.dataset.filtroSede || el.dataset.filtroTipo;
        return document.querySelector('[data-fichas="' + clave + '"]');
    }

    document.addEventListener('input', function (e) {
        if (e.target.matches('[data-filtro-texto]')) { var c = contenedorDe(e.target); if (c) { aplicar(c); } }
    });

    document.addEventListener('change', function (e) {
        if (e.target.matches('[data-filtro-sede]')) { var c = contenedorDe(e.target); if (c) { aplicar(c); } }
    });

    // Píldoras de tipo (Administrativos / Operativos): [data-filtro-tipo="clave"][data-valor]
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-filtro-tipo]');
        if (!b) { return; }
        document.querySelectorAll('[data-filtro-tipo="' + b.dataset.filtroTipo + '"]').forEach(function (x) {
            x.setAttribute('aria-pressed', String(x === b));
            x.classList.toggle('active', x === b);
        });
        var c = contenedorDe(b);
        if (c) { aplicar(c); }
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

/* Casilla que oculta un bloque cuando está marcada (p. ej. "Todas las sedes") */
(function () {
    'use strict';
    function sincronizar(c) {
        var destino = document.querySelector(c.getAttribute('data-oculta-si-marcado'));
        if (destino) { destino.hidden = c.checked; }
    }
    document.addEventListener('change', function (e) {
        if (e.target.matches('[data-oculta-si-marcado]')) { sincronizar(e.target); }
    });
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-oculta-si-marcado]').forEach(sincronizar);
    });
})();

/* ==========================================================================
   Diálogos que se cierran limpios (QA R-01 / U-01)
   Al cerrar un diálogo (Cancelar, X o Esc) sus formularios vuelven al estado
   "de fábrica", para que al abrirlo otra vez no aparezca lo capturado antes.
   - Diálogo normal: cada campo vuelve a lo que el servidor pintó al cargar
     la página (defaultValue / defaultChecked / defaultSelected).
   - Diálogo que se reabrió solo tras un error (data-abrir-al-cargar): lo que
     el servidor pintó son los datos rechazados (old()); la primera vez se ven
     para corregirlos, pero al cerrarlo queda vacío: textos en blanco, casillas
     desmarcadas salvo las que traen data-por-defecto, y en las listas la
     opción con data-por-defecto (o la primera).
   No se tocan los campos ocultos (_token, _method, _dialogo, nivel, padre_id…).
   Para conservar lo capturado: <dialog data-conservar-al-cerrar>.
   ========================================================================== */
(function () {
    'use strict';

    var SIN_TEXTO = ['hidden', 'checkbox', 'radio', 'submit', 'button', 'reset', 'image'];

    function limpiarCampo(campo, trasError) {
        var tipo = (campo.type || '').toLowerCase();
        if (tipo === 'hidden') { return; }

        if (typeof campo.setCustomValidity === 'function') { campo.setCustomValidity(''); }

        if (tipo === 'checkbox' || tipo === 'radio') {
            campo.checked = trasError ? campo.hasAttribute('data-por-defecto') : campo.defaultChecked;
            return;
        }

        if (campo.tagName === 'SELECT') {
            var elegido = -1;
            Array.prototype.forEach.call(campo.options, function (op, i) {
                if (elegido === -1 && (trasError ? op.hasAttribute('data-por-defecto') : op.defaultSelected)) { elegido = i; }
            });
            if (campo.multiple) {
                Array.prototype.forEach.call(campo.options, function (op) {
                    op.selected = trasError ? op.hasAttribute('data-por-defecto') : op.defaultSelected;
                });
            } else {
                campo.selectedIndex = elegido === -1 ? 0 : elegido;
            }
            return;
        }

        if (tipo === 'file') { campo.value = ''; return; }

        if (campo.tagName === 'TEXTAREA' || SIN_TEXTO.indexOf(tipo) === -1) {
            campo.value = trasError ? '' : campo.defaultValue;
            if (campo.dataset.original !== undefined) { delete campo.dataset.original; }
        }
    }

    function limpiarDialogo(dialogo) {
        if (dialogo.hasAttribute('data-conservar-al-cerrar')) { return; }
        var formularios = dialogo.querySelectorAll('form');
        if (!formularios.length) { return; }
        var trasError = dialogo.hasAttribute('data-abrir-al-cargar');

        formularios.forEach(function (form) {
            form.querySelectorAll('input, select, textarea').forEach(function (c) { limpiarCampo(c, trasError); });

            // Crear por lote: vuelve al modo inicial (rango)
            var modo = form.querySelector('[data-modo-lote][data-por-defecto]');
            if (modo) { modo.click(); }

            // Casillas que muestran u ocultan bloques: que el bloque se sincronice
            form.querySelectorAll('[data-oculta-si-marcado]').forEach(function (c) {
                c.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });

        // Avisos de validación y de nombre repetido
        dialogo.querySelectorAll('.is-invalid').forEach(function (n) { n.classList.remove('is-invalid'); });
        dialogo.querySelectorAll('.invalid-feedback, [data-error-campo], .alert').forEach(function (n) { n.remove(); });
        dialogo.querySelectorAll('[data-aviso-nombre]').forEach(function (n) { n.hidden = true; n.textContent = ''; });
    }

    // "close" no burbujea: se escucha en fase de captura para todos los diálogos
    document.addEventListener('close', function (evento) {
        if (evento.target instanceof HTMLDialogElement) { limpiarDialogo(evento.target); }
    }, true);
})();

/* Mensaje propio, en español, cuando un número queda por debajo del mínimo:
   <input type="number" min="11" data-mensaje-min="Tu nivel es 10: ..."> */
(function () {
    'use strict';
    function revisar(campo) {
        var minimo = parseFloat(campo.min);
        var valor = campo.value === '' ? NaN : parseFloat(campo.value);
        campo.setCustomValidity(!isNaN(minimo) && !isNaN(valor) && valor < minimo ? campo.getAttribute('data-mensaje-min') : '');
    }
    document.addEventListener('input', function (e) {
        if (e.target.matches('[data-mensaje-min]')) { revisar(e.target); }
    });
    // "invalid" tampoco burbujea; también cubre valores puestos por código
    document.addEventListener('invalid', function (e) {
        if (e.target.matches && e.target.matches('[data-mensaje-min]')) { revisar(e.target); }
    }, true);
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-mensaje-min]').forEach(revisar);
    });
})();

/* Aviso en vivo de nombre repetido, sin consultar al servidor:
   <input data-nombres-existentes='["recepción", ...]'> + <p data-aviso-nombre> en el mismo formulario */
(function () {
    'use strict';
    document.addEventListener('input', function (e) {
        var campo = e.target;
        if (!campo.matches('[data-nombres-existentes]')) { return; }
        var aviso = campo.form && campo.form.querySelector('[data-aviso-nombre]');
        if (!aviso) { return; }
        var nombre = campo.value.trim().replace(/\s+/g, ' ').toLowerCase();
        var original = (campo.dataset.original || '').trim().toLowerCase();
        var existentes = [];
        try { existentes = JSON.parse(campo.getAttribute('data-nombres-existentes') || '[]'); } catch (x) { /* vacío */ }
        if (nombre === '' || nombre === original) { aviso.hidden = true; return; }
        var repetido = existentes.indexOf(nombre) !== -1;
        aviso.hidden = false;
        aviso.className = 'small mb-2 ' + (repetido ? 'text-warning' : 'text-success');
        aviso.textContent = repetido ? 'Ya existe uno con ese nombre en esta empresa.' : 'Disponible.';
    });
})();

/* ==========================================================================
   Colaboradores: filtro de fichas, combos dependientes (sede → departamento
   → puesto), aviso de número repetido, datos personales bajo demanda,
   sedes adicionales, registro rápido y autocompletar en Usuarios.
   ========================================================================== */
(function () {
    'use strict';

    function lista(texto) { return (texto || '').split(',').filter(Boolean); }

    /* ---------- Filtro de fichas: texto, sede (física o adicional) y departamento ---------- */
    var CLAVE_FILTRO = 'plataforma_filtro_colaboradores';

    function filtrarColaboradores() {
        var cont = document.querySelector('[data-colaboradores]');
        if (!cont) { return; }
        var valor = function (tipo) { var el = document.querySelector('[data-filtro-colab="' + tipo + '"]'); return el ? el.value : ''; };
        var texto = valor('texto').toLowerCase().trim();
        var sede = valor('sede');
        var depto = valor('depto');
        var fichas = cont.querySelectorAll('[data-colaborador]');
        var visibles = 0;

        fichas.forEach(function (f) {
            var ok = (texto === '' || (f.dataset.texto || '').indexOf(texto) !== -1)
                && (sede === '' || lista(f.dataset.sedes).indexOf(sede) !== -1)
                && (depto === '' || f.dataset.depto === depto);
            f.style.display = ok ? '' : 'none';
            if (ok) { visibles++; }
        });

        var vacio = cont.querySelector('[data-sin-resultados-colab]');
        if (vacio) { vacio.hidden = visibles !== 0 || fichas.length === 0; }
        try { sessionStorage.setItem(CLAVE_FILTRO, JSON.stringify({ texto: valor('texto'), sede: sede, depto: depto })); } catch (e) { /* sin almacenamiento */ }
    }

    document.addEventListener('input', function (e) { if (e.target.matches('[data-filtro-colab]')) { filtrarColaboradores(); } });
    document.addEventListener('change', function (e) { if (e.target.matches('[data-filtro-colab]')) { filtrarColaboradores(); } });

    /* ---------- Combos dependientes ---------- */
    function mostrarOpcion(opcion, visible) {
        opcion.hidden = !visible;
        opcion.disabled = !visible;
    }

    function acotarPuestos(form) {
        var depto = form.querySelector('[data-colab-depto]');
        var puesto = form.querySelector('[data-colab-puesto]');
        if (!puesto) { return; }
        var d = depto ? depto.value : '';
        Array.prototype.forEach.call(puesto.options, function (o) {
            if (o.value === '') { return; }
            var deps = lista(o.dataset.deps);
            mostrarOpcion(o, d === '' || deps.length === 0 || deps.indexOf(d) !== -1);
        });
        if (puesto.selectedOptions[0] && puesto.selectedOptions[0].disabled) { puesto.value = ''; }
    }

    function acotarDepartamentos(form) {
        var sede = form.querySelector('[data-colab-sede]');
        var depto = form.querySelector('[data-colab-depto]');
        if (sede) {
            // Sedes fuera del alcance o desactivadas: solo se muestran si son la actual
            Array.prototype.forEach.call(sede.options, function (o) {
                if (o.hasAttribute('data-ajena')) { o.hidden = o.value !== sede.value; }
            });
        }
        if (depto) {
            var s = sede ? sede.value : '';
            Array.prototype.forEach.call(depto.options, function (o) {
                if (o.value === '') { return; }
                mostrarOpcion(o, s === '' || o.dataset.todas === '1' || lista(o.dataset.sedes).indexOf(s) !== -1);
            });
            if (depto.selectedOptions[0] && depto.selectedOptions[0].disabled) { depto.value = ''; }
        }
        acotarPuestos(form);
    }

    // El valor guardado nunca se pierde en silencio: si ya no encaja, se deja visible y el servidor avisa
    function conservarActual(select) {
        var o = select.selectedOptions[0];
        if (o && o.value !== '' && o.disabled) { mostrarOpcion(o, true); }
    }

    document.addEventListener('change', function (e) {
        var form = e.target.closest('[data-form-colaborador]');
        if (!form) { return; }
        if (e.target.matches('[data-colab-sede]')) { acotarDepartamentos(form); }
        if (e.target.matches('[data-colab-depto]')) { acotarPuestos(form); }
    });

    /* ---------- Aviso en vivo de número de empleado repetido ---------- */
    function avisarNumero(campo) {
        var aviso = campo.form && campo.form.querySelector('[data-aviso-numero]');
        if (!aviso) { return; }
        var numero = campo.value.trim().toLowerCase();
        var original = (campo.dataset.original || '').trim().toLowerCase();
        var existentes = [];
        try { existentes = JSON.parse(campo.getAttribute('data-numeros-existentes') || '[]'); } catch (x) { /* vacío */ }
        if (numero === '' || numero === original) { aviso.hidden = true; return; }
        var repetido = existentes.indexOf(numero) !== -1;
        aviso.hidden = false;
        aviso.className = 'small mb-2 ' + (repetido ? 'text-warning' : 'text-success');
        aviso.textContent = repetido
            ? 'Ya existe un Colaborador con el número "' + campo.value.trim() + '" en esta empresa.'
            : 'Disponible.';
    }

    document.addEventListener('input', function (e) {
        if (e.target.matches('[data-numeros-existentes]')) { avisarNumero(e.target); }
    });

    /* ---------- Al abrir editar / sedes adicionales (después del llenado genérico) ---------- */
    function cargarDatosPersonales(form, url) {
        var campos = form.querySelectorAll('[data-dato-personal]');
        var cargando = form.querySelector('[data-cargando-datos]');
        var textoCargando = cargando ? (cargando.dataset.textoOriginal || cargando.innerHTML) : '';
        if (cargando) { cargando.dataset.textoOriginal = textoCargando; cargando.innerHTML = textoCargando; cargando.hidden = false; }
        campos.forEach(function (c) { c.value = ''; c.disabled = true; });
        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { if (!r.ok) { throw new Error(String(r.status)); } return r.json(); })
            .then(function (datos) {
                campos.forEach(function (c) {
                    var v = datos[c.name];
                    c.value = (v === null || v === undefined) ? '' : String(v);
                    c.disabled = false;
                });
                if (cargando) { cargando.hidden = true; }
            })
            .catch(function () {
                // Sin datos cargados los campos siguen bloqueados: no se envían y no se borra nada
                if (cargando) { cargando.textContent = 'No se pudieron cargar los datos personales; se conservan sin cambios.'; }
            });
    }

    document.addEventListener('click', function (e) {
        var boton = e.target.closest('[data-accion="editar-registro"]');
        if (!boton) { return; }
        var dialogo = document.getElementById(boton.dataset.dialogo);
        var form = dialogo && dialogo.querySelector('form');
        if (!form) { return; }
        var valores = {};
        try { valores = JSON.parse(boton.dataset.valores || '{}'); } catch (x) { /* sin valores */ }

        if (form.hasAttribute('data-form-colaborador')) {
            // El llenado genérico ya puso los valores; se acotan los combos sin perderlos
            var sede = form.querySelector('[data-colab-sede]');
            var depto = form.querySelector('[data-colab-depto]');
            var puesto = form.querySelector('[data-colab-puesto]');
            if (sede) { sede.value = valores.sede_id ? String(valores.sede_id) : ''; }
            acotarDepartamentos(form);
            if (depto) { depto.value = valores.departamento_id ? String(valores.departamento_id) : ''; conservarActual(depto); }
            acotarPuestos(form);
            if (puesto) { puesto.value = valores.puesto_id ? String(valores.puesto_id) : ''; conservarActual(puesto); }
            var numero = form.querySelector('[data-numeros-existentes]');
            if (numero) { numero.dataset.original = numero.value; avisarNumero(numero); }
            if (boton.dataset.urlDatos) { cargarDatosPersonales(form, boton.dataset.urlDatos); }
        }

        if (boton.dataset.dialogo === 'dialogoSedesColaborador') {
            dialogo.querySelectorAll('[data-colab-nombre]').forEach(function (n) { n.textContent = boton.dataset.nombre || ''; });
            dialogo.querySelectorAll('[data-colab-sede-principal]').forEach(function (n) { n.textContent = boton.dataset.sedePrincipal || ''; });
            // La sede principal no se repite como adicional
            dialogo.querySelectorAll('[data-sede-opcion]').forEach(function (fila) {
                var esPrincipal = String(valores.sede_id || '') === fila.dataset.sedeOpcion;
                fila.hidden = esPrincipal;
                if (esPrincipal) { fila.querySelector('input').checked = false; }
            });
        }

        // Usuarios: vínculo con colaborador (campo oculto que el llenado genérico no toca)
        var oculto = form.querySelector('[data-campo-colaborador]');
        if (oculto) {
            oculto.value = valores.colaborador_id ? String(valores.colaborador_id) : '';
            mostrarVinculo(form);
        }
    });

    /* ---------- Registro rápido (respuesta JSON; avisa con el evento colaborador:registrado) ---------- */
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.matches('[data-registro-rapido-colaborador]')) { return; }
        e.preventDefault();
        var boton = form.querySelector('button[type="submit"]');
        var errores = form.querySelector('[data-errores-rapido]');
        if (boton) { boton.disabled = true; boton.textContent = 'Guardando...'; }
        if (errores) { errores.hidden = true; errores.textContent = ''; }

        function terminar() { if (boton) { boton.disabled = false; boton.textContent = boton.dataset.textoOriginal || 'Registrar'; } }

        fetch(form.action, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData(form)
        })
            .then(function (r) { return r.json().then(function (d) { return { estado: r.status, datos: d }; }); })
            .then(function (res) {
                terminar();
                if (res.estado === 201 && res.datos.ok) {
                    form.reset();
                    var dialogo = form.closest('dialog');
                    if (dialogo) { dialogo.close(); }
                    document.dispatchEvent(new CustomEvent('colaborador:registrado', { detail: res.datos.colaborador }));
                    return;
                }
                var mensajes = [];
                Object.keys(res.datos.errores || {}).forEach(function (k) { mensajes = mensajes.concat(res.datos.errores[k]); });
                if (mensajes.length === 0) { mensajes.push(res.datos.mensaje || res.datos.message || 'No se pudo registrar. Intenta de nuevo.'); }
                if (errores) {
                    mensajes.forEach(function (m) { var div = document.createElement('div'); div.textContent = m; errores.appendChild(div); });
                    errores.hidden = false;
                }
            })
            .catch(function () {
                terminar();
                if (errores) { errores.textContent = 'No se pudo registrar en este momento.'; errores.hidden = false; }
            });
    });

    /* ---------- Usuarios: Núm. Colaborador con autocompletar ---------- */
    var espera = null;

    function mostrarVinculo(form) {
        var oculto = form.querySelector('[data-campo-colaborador]');
        var aviso = form.querySelector('[data-colab-vinculo]');
        if (aviso && oculto) { aviso.hidden = oculto.value === ''; }
    }

    function cerrarResultados(caja, campo) {
        if (!caja) { return; }
        caja.hidden = true;
        caja.textContent = '';
        if (campo) { campo.setAttribute('aria-expanded', 'false'); }
    }

    function elegirColaborador(campo, c) {
        var form = campo.form;
        campo.value = c.num_empleado;
        var oculto = form.querySelector('[data-campo-colaborador]');
        if (oculto) { oculto.value = String(c.id); }
        var nombre = document.getElementById(campo.dataset.destinoNombre);
        if (nombre) { nombre.value = c.nombre_completo; }
        cerrarResultados(document.getElementById(campo.getAttribute('aria-controls')), campo);
        mostrarVinculo(form);
    }

    function pintarResultados(campo, datos) {
        var caja = document.getElementById(campo.getAttribute('aria-controls'));
        if (!caja) { return; }
        caja.textContent = '';
        var resultados = datos.resultados || [];
        if (resultados.length === 0) {
            var vacio = document.createElement('div');
            vacio.className = 'buscador-colab-item ' + (datos.todas_ya_tienen_usuario ? 'text-warning' : 'text-muted');
            vacio.textContent = datos.todas_ya_tienen_usuario
                ? 'Ese colaborador ya tiene una cuenta de usuario: búscalo en la lista principal en vez de crear una nueva.'
                : 'Sin coincidencias: puedes capturar los datos a mano.';
            caja.appendChild(vacio);
        }
        resultados.forEach(function (c) {
            var item = document.createElement('button');
            item.type = 'button';
            item.className = 'buscador-colab-item';
            item.setAttribute('role', 'option');
            item.textContent = c.nombre_completo;
            var detalle = document.createElement('small');
            detalle.textContent = '#' + c.num_empleado + (c.puesto ? ' · ' + c.puesto : '') + (c.sede ? ' · ' + c.sede : '');
            item.appendChild(detalle);
            item.addEventListener('click', function () { elegirColaborador(campo, c); });
            caja.appendChild(item);
        });
        caja.hidden = false;
        campo.setAttribute('aria-expanded', 'true');
    }

    document.addEventListener('input', function (e) {
        var campo = e.target;
        if (!campo.matches('[data-buscar-colaborador]')) { return; }
        // Si se cambia el número a mano, se quita el vínculo (como en SEGCAT)
        var oculto = campo.form.querySelector('[data-campo-colaborador]');
        if (oculto) { oculto.value = ''; mostrarVinculo(campo.form); }

        clearTimeout(espera);
        var texto = campo.value.trim();
        var caja = document.getElementById(campo.getAttribute('aria-controls'));
        if (texto.length < 2) { cerrarResultados(caja, campo); return; }

        espera = setTimeout(function () {
            var url = campo.dataset.buscarColaborador + '?sin_usuario=1&q=' + encodeURIComponent(texto);
            fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (r) { if (!r.ok) { throw new Error(String(r.status)); } return r.json(); })
                .then(function (datos) { pintarResultados(campo, datos); })
                .catch(function () { cerrarResultados(caja, campo); });
        }, 250);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && e.target.matches('[data-buscar-colaborador]')) {
            var caja = document.getElementById(e.target.getAttribute('aria-controls'));
            if (caja && !caja.hidden) { e.preventDefault(); cerrarResultados(caja, e.target); }
        }
    });

    // Cierra la lista de resultados con un clic fuera
    document.addEventListener('click', function (e) {
        document.querySelectorAll('.buscador-colab-resultados').forEach(function (caja) {
            var envoltura = caja.closest('.buscador-colab');
            if (envoltura && !envoltura.contains(e.target)) { cerrarResultados(caja, envoltura.querySelector('[data-buscar-colaborador]')); }
        });
    });

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-form-colaborador]').forEach(function (form) {
            var depto = form.querySelector('[data-colab-depto]');
            var puesto = form.querySelector('[data-colab-puesto]');
            var d = depto ? depto.value : '';
            var p = puesto ? puesto.value : '';
            acotarDepartamentos(form);
            if (depto && d) { depto.value = d; conservarActual(depto); }
            acotarPuestos(form);
            if (puesto && p) { puesto.value = p; conservarActual(puesto); }
        });
        document.querySelectorAll('[data-campo-colaborador]').forEach(function (c) { if (c.form) { mostrarVinculo(c.form); } });

        if (!document.querySelector('[data-colaboradores]')) { return; }
        // ?sede=X (desde la ficha de una sede) manda sobre el filtro guardado
        var sedeUrl = new URLSearchParams(window.location.search).get('sede');
        var selSede = document.querySelector('[data-filtro-colab="sede"]');
        try {
            var g = JSON.parse(sessionStorage.getItem(CLAVE_FILTRO) || 'null');
            if (g && !sedeUrl) {
                var t = document.querySelector('[data-filtro-colab="texto"]');
                var dep = document.querySelector('[data-filtro-colab="depto"]');
                if (t && g.texto) { t.value = g.texto; }
                if (selSede && g.sede) { selSede.value = g.sede; }
                if (dep && g.depto) { dep.value = g.depto; }
            }
        } catch (x) { /* valor guardado dañado: se ignora */ }
        if (sedeUrl && selSede) { selSede.value = sedeUrl; }
        filtrarColaboradores();
    });
})();
