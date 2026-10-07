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

        /* Seguridad: el cierre se pide por POST con el token CSRF (un GET ya no cierra la sesión) */
        var cerrando = false;
        function cerrarPorInactividad() {
            if (cerrando) { return; }
            cerrando = true;
            var meta = document.querySelector('meta[name="csrf-token"]');
            if (!meta) { window.location.href = datos.expirada; return; }
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = datos.expirada;
            form.hidden = true;
            var token = document.createElement('input');
            token.type = 'hidden';
            token.name = '_token';
            token.value = meta.getAttribute('content');
            form.appendChild(token);
            document.body.appendChild(form);
            form.submit();
        }

        window.plataformaSesion = {
            seguir: function () { ultimaActividad = Date.now(); latido(); }
        };

        setInterval(function () {
            var inactivo = segundos(ultimaActividad);
            if (inactivo >= LIMITE) {
                cerrarPorInactividad();
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
        // y, si el diálogo no trae sus propios errores, se copian dentro los de la
        // página (si no, quedarían escondidos detrás del diálogo)
        var erroresPagina = document.querySelector('.alert-danger.aviso');
        document.querySelectorAll('dialog[data-abrir-al-cargar]').forEach(function (d) {
            var form = d.querySelector('form');
            if (erroresPagina && form && !d.querySelector('.alert-danger:not([hidden])')) {
                form.insertBefore(erroresPagina.cloneNode(true), form.firstChild);
            }
            abrir(d);
        });
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
        if (marca) { marca.value = (marca.dataset.prefijoDialogo || 'editar-') + boton.dataset.id; }

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
        dialogo.querySelectorAll('.invalid-feedback, [data-error-campo]').forEach(function (n) { n.remove(); });
        // Los avisos que pinta el servidor se quitan; los contenedores que reutiliza
        // el registro rápido (data-errores-...) solo se vacían y se ocultan
        dialogo.querySelectorAll('.alert').forEach(function (n) {
            var reutilizable = Array.prototype.some.call(n.attributes, function (a) { return a.name.indexOf('data-errores') === 0; });
            if (reutilizable) { n.hidden = true; n.textContent = ''; } else { n.remove(); }
        });
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
        var registro = valor('registro');
        var fichas = cont.querySelectorAll('[data-colaborador]');
        var visibles = 0;

        fichas.forEach(function (f) {
            var ok = (texto === '' || (f.dataset.texto || '').indexOf(texto) !== -1)
                && (sede === '' || lista(f.dataset.sedes).indexOf(sede) !== -1)
                && (depto === '' || f.dataset.depto === depto)
                && (registro === '' || f.dataset.registro === registro);
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
                // Alta provisional: ya hay alguien con ese nombre. Se ofrece usarlo o confirmar que es otra persona.
                if (res.estado === 409 && res.datos.parecidos) {
                    mostrarParecidos(form, res.datos);
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

    function mostrarParecidos(form, datos) {
        var caja = form.querySelector('[data-parecidos-rapido]');
        if (!caja) { return; }
        caja.textContent = '';
        var titulo = document.createElement('p');
        titulo.className = 'fw-semibold mb-2';
        titulo.textContent = datos.mensaje;
        caja.appendChild(titulo);
        datos.parecidos.forEach(function (c) {
            var boton = document.createElement('button');
            boton.type = 'button';
            boton.className = 'opcion-parecido';
            boton.textContent = c.nombre_completo + (c.num_empleado ? ' · #' + c.num_empleado : ' · provisional') + (c.puesto ? ' · ' + c.puesto : '') + (c.sede ? ' · ' + c.sede : '');
            boton.addEventListener('click', function () {
                caja.hidden = true;
                form.reset();
                var dialogo = form.closest('dialog');
                if (dialogo) { dialogo.close(); }
                document.dispatchEvent(new CustomEvent('colaborador:registrado', { detail: c }));
            });
            caja.appendChild(boton);
        });
        var otra = document.createElement('button');
        otra.type = 'button';
        otra.className = 'btn btn-outline-dark btn-sm fw-bold mt-2';
        otra.textContent = 'Es otra persona: registrarla';
        otra.addEventListener('click', function () {
            var confirmar = form.querySelector('[data-confirmar-nuevo]');
            if (confirmar) { confirmar.value = '1'; }
            caja.hidden = true;
            form.requestSubmit();
        });
        caja.appendChild(otra);
        caja.hidden = false;
    }

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
        // ?registro=provisional (aviso del Inicio): abre directo en "Por validar"
        var registroUrl = new URLSearchParams(window.location.search).get('registro');
        var selRegistro = document.querySelector('[data-filtro-colab="registro"]');
        if (registroUrl && selRegistro) { selRegistro.value = registroUrl; }
        filtrarColaboradores();
    });
})();

/* Registro rápido: al cerrar, se olvida la confirmación "es otra persona" y la lista de parecidos */
document.addEventListener('close', function (e) {
    if (!e.target.querySelector) { return; }
    var confirmar = e.target.querySelector('[data-confirmar-nuevo]');
    if (confirmar) { confirmar.value = '0'; }
    var parecidos = e.target.querySelector('[data-parecidos-rapido]');
    if (parecidos) { parecidos.hidden = true; parecidos.textContent = ''; }
}, true);

/* En la pantalla de Colaboradores, un alta (rápida o provisional) se ve al recargar la lista */
document.addEventListener('colaborador:registrado', function () {
    if (document.querySelector('[data-colaboradores]')) { window.location.reload(); }
});

/* Bitácora de auditoría: detalle de un movimiento (antes / después) */
document.addEventListener('click', function (e) {
    var boton = e.target.closest('[data-detalle-auditoria]');
    if (!boton) { return; }
    var dialogo = document.getElementById('dialogoDetalleAuditoria');
    if (!dialogo) { return; }
    var datos = {};
    try { datos = JSON.parse(boton.getAttribute('data-detalle-auditoria')); } catch (x) { return; }
    dialogo.querySelector('[data-aud-titulo]').textContent = datos.titulo || '';
    dialogo.querySelector('[data-aud-registro]').textContent = datos.registro || '';
    var cuerpo = dialogo.querySelector('[data-aud-filas]');
    cuerpo.textContent = '';
    (datos.filas || []).forEach(function (f) {
        var tr = document.createElement('tr');
        if (f.cambio) { tr.className = 'cambio'; }
        [f.campo, f.antes, f.despues].forEach(function (v) { var td = document.createElement('td'); td.textContent = v; tr.appendChild(td); });
        cuerpo.appendChild(tr);
    });
    if (!datos.filas || datos.filas.length === 0) {
        var vacio = document.createElement('tr');
        var td = document.createElement('td'); td.colSpan = 3; td.textContent = 'Sin detalle de campos.'; vacio.appendChild(td); cuerpo.appendChild(vacio);
    }
    dialogo.showModal();
});

/* Matriz de permisos: cada área (Dirección, Recursos Humanos, Seguridad) se abre y se cierra.
   Se recuerda qué áreas dejó abiertas en la pestaña del navegador. */
(function () {
    'use strict';
    var CLAVE = 'plataforma_areas_abiertas';

    function abiertas() {
        try { return JSON.parse(sessionStorage.getItem(CLAVE) || '[]'); } catch (x) { return []; }
    }
    function guardar() {
        var ids = [];
        document.querySelectorAll('[data-alternar-area][aria-expanded="true"]').forEach(function (b) { ids.push(b.dataset.alternarArea); });
        try { sessionStorage.setItem(CLAVE, JSON.stringify(ids)); } catch (x) { /* sin almacenamiento */ }
    }
    function poner(boton, abrir) {
        var cuerpo = document.getElementById(boton.dataset.alternarArea);
        if (!cuerpo) { return; }
        cuerpo.hidden = !abrir;
        boton.setAttribute('aria-expanded', String(abrir));
    }

    document.addEventListener('click', function (e) {
        var boton = e.target.closest('[data-alternar-area]');
        if (boton) {
            poner(boton, boton.getAttribute('aria-expanded') !== 'true');
            guardar();
            return;
        }
        var todas = e.target.closest('[data-areas-todas]');
        if (todas) {
            document.querySelectorAll('[data-alternar-area]').forEach(function (b) { poner(b, todas.dataset.areasTodas === 'abrir'); });
            guardar();
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        var botones = document.querySelectorAll('[data-alternar-area]');
        if (botones.length === 0) { return; }
        var previas = abiertas();
        botones.forEach(function (b) { poner(b, previas.indexOf(b.dataset.alternarArea) !== -1); });
    });
})();
/* ==========================================================================
   Padrones: Proveedores — alta rápida "Nueva Empresa Externa" desde otros
   módulos (respuesta JSON; avisa con el evento proveedor:registrado)
   ========================================================================== */
(function () {
    'use strict';

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.matches('[data-alta-rapida-proveedor]')) { return; }
        e.preventDefault();
        var boton = form.querySelector('button[type="submit"]');
        var errores = form.querySelector('[data-errores-proveedor]');
        if (boton) { boton.disabled = true; boton.textContent = 'Guardando...'; }
        if (errores) { errores.hidden = true; errores.textContent = ''; }

        function terminar() { if (boton) { boton.disabled = false; boton.textContent = boton.dataset.textoOriginal || 'Guardar'; } }

        fetch(form.action, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData(form)
        })
            .then(function (r) { return r.json().then(function (d) { return { estado: r.status, datos: d }; }); })
            .then(function (res) {
                terminar();
                if ((res.estado === 201 || res.estado === 200) && res.datos.ok) {
                    form.reset();
                    var dialogo = form.closest('dialog');
                    if (dialogo) { dialogo.close(); }
                    var detalle = res.datos.proveedor;
                    detalle.ya_existia = !!res.datos.ya_existia;
                    document.dispatchEvent(new CustomEvent('proveedor:registrado', { detail: detalle }));
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

    // Al cerrar, se olvidan los errores de la captura anterior
    document.addEventListener('close', function (e) {
        var errores = e.target.querySelector && e.target.querySelector('[data-errores-proveedor]');
        if (errores) { errores.hidden = true; errores.textContent = ''; }
    }, true);
})();
/* Fin Padrones: Proveedores */
/* ==========================================================================
   Padrón de personas: campos según el tipo (categoría solo para visitantes;
   empresa que representa solo para proveedor/contratista; texto libre de
   procedencia si no está en el directorio), regreso a la ficha del proveedor
   y registro rápido (evento persona:registrada).
   ========================================================================== */
(function () {
    'use strict';

    function sincronizar(form) {
        if (!form) { return; }
        var tipo = form.querySelector('[data-persona-tipo]');
        var proveedor = form.querySelector('[data-persona-proveedor]');
        var esVisitante = !tipo || tipo.value === 'visitante';
        form.querySelectorAll('[data-solo-visitante]').forEach(function (n) { n.hidden = !esVisitante; });
        form.querySelectorAll('[data-solo-empresa]').forEach(function (n) { n.hidden = esVisitante; });
        var conProveedor = !esVisitante && proveedor && proveedor.value !== '';
        form.querySelectorAll('[data-solo-sin-proveedor]').forEach(function (n) { n.hidden = conProveedor; });
    }

    document.addEventListener('change', function (e) {
        var form = e.target.form;
        if (!form || !form.matches('[data-form-persona]')) { return; }
        // Al elegir la empresa, el tipo sigue a su categoría (contratista o proveedor)
        if (e.target.matches('[data-persona-proveedor]') && e.target.value !== '') {
            var op = e.target.options[e.target.selectedIndex];
            var tipo = form.querySelector('[data-persona-tipo]');
            if (tipo && op) { tipo.value = op.getAttribute('data-categoria') === 'contratista' ? 'contratista' : 'proveedor'; }
        }
        if (e.target.matches('[data-persona-tipo], [data-persona-proveedor]')) { sincronizar(form); }
    });

    // Después de que el llenado genérico pone los valores de la ficha
    document.addEventListener('click', function (e) {
        var boton = e.target.closest('[data-accion="editar-registro"]');
        if (!boton) { return; }
        var dialogo = document.getElementById(boton.dataset.dialogo);
        if (dialogo) { sincronizar(dialogo.querySelector('[data-form-persona]')); }
    });

    // Al cerrar: ya no regresa a la ficha del proveedor y se olvida la respuesta del registro rápido
    document.addEventListener('close', function (e) {
        if (!(e.target instanceof HTMLDialogElement)) { return; }
        var volver = e.target.querySelector('[data-volver-proveedor]');
        if (volver) { volver.value = ''; }
        e.target.querySelectorAll('[data-aviso-volver]').forEach(function (n) { n.hidden = true; });
        e.target.querySelectorAll('[data-errores-rapido-persona], [data-existente-rapido-persona]').forEach(function (n) { n.hidden = true; n.textContent = ''; });
        sincronizar(e.target.querySelector('[data-form-persona]'));
    }, true);

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-form-persona]').forEach(sincronizar);
    });

    function avisar(persona) {
        document.dispatchEvent(new CustomEvent('persona:registrada', { detail: persona }));
    }

    function mostrarExistente(form, datos) {
        var caja = form.querySelector('[data-existente-rapido-persona]');
        if (!caja) { return; }
        var p = datos.persona || {};
        caja.textContent = '';
        var titulo = document.createElement('p');
        titulo.className = 'fw-semibold mb-2';
        titulo.textContent = datos.mensaje || 'Ese folio ya está registrado.';
        caja.appendChild(titulo);
        if (p.activo) {
            var usar = document.createElement('button');
            usar.type = 'button';
            usar.className = 'opcion-parecido';
            usar.textContent = 'Usar a esta persona: ' + p.nombre_completo + (p.folio ? ' · ' + p.folio : '') + (p.empresa ? ' · ' + p.empresa : '');
            usar.addEventListener('click', function () {
                form.reset();
                var dialogo = form.closest('dialog');
                if (dialogo) { dialogo.close(); }
                avisar(p);
            });
            caja.appendChild(usar);
        } else {
            var baja = document.createElement('p');
            baja.className = 'small m-0';
            baja.textContent = 'Está dada de baja: pide que la reactiven en el Padrón de personas.';
            caja.appendChild(baja);
        }
        caja.hidden = false;
    }

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.matches('[data-registro-rapido-persona]')) { return; }
        e.preventDefault();
        var boton = form.querySelector('button[type="submit"]');
        var errores = form.querySelector('[data-errores-rapido-persona]');
        var existente = form.querySelector('[data-existente-rapido-persona]');
        if (boton) { boton.disabled = true; boton.textContent = 'Guardando...'; }
        if (errores) { errores.hidden = true; errores.textContent = ''; }
        if (existente) { existente.hidden = true; existente.textContent = ''; }

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
                    avisar(res.datos.persona);
                    return;
                }
                if (res.estado === 409 && res.datos.persona) { mostrarExistente(form, res.datos); return; }
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
})();
/* Fin Padrón de personas */
/* ==========================================================================
   Padrón Vehicular: campos que dependen de otro, filtro de fichas, aviso de
   placas repetidas, alta desde la ficha de un proveedor, registro rápido
   (evento vehiculo:registrado) e impresión de la calcomanía.
   ========================================================================== */
(function () {
    'use strict';

    /* ---------- Campos que dependen de otro (genérico) ----------
       <div data-mostrar-si='{"propiedad":["a","b"],"tipo":["otro"]}'> se ve si
       ALGUNA condición se cumple (el campo con ese name tiene uno de esos
       valores). Oculto: sus campos se vacían y se deshabilitan (no se envían).
       <input data-requerido-si='{"tipo":["otro"]}'> es obligatorio solo si se cumple. */
    function leer(texto) { try { return JSON.parse(texto || '{}'); } catch (x) { return {}; } }

    function cumple(form, condiciones) {
        return Object.keys(condiciones).some(function (campo) {
            var el = form.elements[campo];
            return !!el && condiciones[campo].indexOf(el.value) !== -1;
        });
    }

    function sincronizarDependientes(form) {
        form.querySelectorAll('[data-mostrar-si]').forEach(function (caja) {
            var visible = cumple(form, leer(caja.getAttribute('data-mostrar-si')));
            caja.hidden = !visible;
            caja.querySelectorAll('input, select, textarea').forEach(function (c) {
                if (!visible) { c.value = ''; }
                c.disabled = !visible;
            });
        });
        form.querySelectorAll('[data-requerido-si]').forEach(function (c) {
            c.required = !c.disabled && cumple(form, leer(c.getAttribute('data-requerido-si')));
        });
    }

    document.addEventListener('change', function (e) {
        var form = e.target.form;
        if (form && form.querySelector('[data-mostrar-si], [data-requerido-si]')) { sincronizarDependientes(form); }
    });

    /* ---------- Aviso en vivo de placas ya registradas ---------- */
    function placas(texto) { return (texto || '').replace(/[\s\-.]+/g, '').toUpperCase(); }

    function avisarPlacas(campo) {
        var aviso = campo.form && campo.form.querySelector('[data-aviso-placas]');
        if (!aviso) { return; }
        var valor = placas(campo.value);
        var existentes = leer(campo.getAttribute('data-placas-existentes'));
        if (!Array.isArray(existentes)) { existentes = []; }
        if (valor === '' || valor === placas(campo.dataset.original)) { aviso.hidden = true; return; }
        var repetidas = existentes.indexOf(valor) !== -1;
        aviso.hidden = false;
        aviso.className = 'small mb-2 ' + (repetidas ? 'text-warning' : 'text-success');
        aviso.textContent = repetidas ? 'Las placas ' + valor + ' ya están registradas en el padrón.' : 'Se guardarán como ' + valor + '.';
    }

    document.addEventListener('input', function (e) {
        if (e.target.matches('[data-placas-existentes]')) { avisarPlacas(e.target); }
    });

    // Editar: después del llenado genérico, se acomodan los campos y el aviso
    document.addEventListener('click', function (e) {
        var boton = e.target.closest('[data-accion="editar-registro"]');
        if (!boton) { return; }
        var dialogo = document.getElementById(boton.dataset.dialogo);
        var form = dialogo && dialogo.querySelector('[data-form-vehiculo]');
        if (!form) { return; }
        var valores = leer(boton.dataset.valores);
        // Los campos dependientes se llenan de nuevo (pudieron quedar vacíos al ocultarse) y se acomodan
        form.querySelectorAll('[data-mostrar-si] input, [data-mostrar-si] select').forEach(function (c) {
            c.disabled = false;
            c.value = (valores[c.name] === null || valores[c.name] === undefined) ? '' : String(valores[c.name]);
        });
        sincronizarDependientes(form);
        var campo = form.querySelector('[data-placas-existentes]');
        if (campo) { campo.dataset.original = campo.value; avisarPlacas(campo); }
    });

    // Al cerrar (y limpiarse) un diálogo, sus campos dependientes vuelven a acomodarse;
    // el regreso a la ficha del proveedor solo vale para la primera alta
    document.addEventListener('close', function (e) {
        if (!(e.target instanceof HTMLDialogElement)) { return; }
        e.target.querySelectorAll('[data-form-vehiculo]').forEach(function (form) {
            var volver = form.querySelector('[data-volver]');
            if (volver) { volver.value = ''; }
            var aviso = form.querySelector('[data-aviso-placas]');
            if (aviso) { aviso.hidden = true; }
            sincronizarDependientes(form);
        });
    }, true);

    /* ---------- Filtro de fichas: texto (placas sin guiones ni espacios) y píldoras ---------- */
    var CLAVE = 'plataforma_filtro_vehiculos';

    function filtrarVehiculos() {
        var cont = document.querySelector('[data-vehiculos]');
        if (!cont) { return; }
        var buscador = document.querySelector('[data-filtro-vehiculos]');
        var pill = document.querySelector('[data-filtro-tipo="vehiculos"][aria-pressed="true"]');
        var texto = buscador ? buscador.value.toLowerCase().trim() : '';
        var compacto = placas(texto).toLowerCase();
        var grupo = pill ? pill.dataset.valor : '';
        var fichas = cont.querySelectorAll('[data-vehiculo]');
        var visibles = 0;
        fichas.forEach(function (f) {
            var t = f.dataset.texto || '';
            var ok = (texto === '' || t.indexOf(texto) !== -1 || (compacto !== '' && t.indexOf(compacto) !== -1))
                && (grupo === '' || f.dataset.grupo === grupo);
            f.style.display = ok ? '' : 'none';
            if (ok) { visibles++; }
        });
        var vacio = cont.querySelector('[data-sin-resultados-vehiculos]');
        if (vacio) { vacio.hidden = visibles !== 0 || fichas.length === 0; }
        try { sessionStorage.setItem(CLAVE, JSON.stringify({ texto: buscador ? buscador.value : '', grupo: grupo })); } catch (x) { /* sin almacenamiento */ }
    }

    document.addEventListener('input', function (e) { if (e.target.matches('[data-filtro-vehiculos]')) { filtrarVehiculos(); } });
    // La píldora ya cambió su estado en el manejador genérico de data-filtro-tipo
    document.addEventListener('click', function (e) { if (e.target.closest('[data-filtro-tipo="vehiculos"]')) { filtrarVehiculos(); } });

    function elegirPildora(valor) {
        document.querySelectorAll('[data-filtro-tipo="vehiculos"]').forEach(function (x) {
            var on = x.dataset.valor === valor;
            x.setAttribute('aria-pressed', String(on));
            x.classList.toggle('active', on);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-form-vehiculo]').forEach(sincronizarDependientes);
        if (!document.querySelector('[data-vehiculos]')) { return; }
        // Al llegar a una ficha (#vehiculo-12, p. ej. desde el QR) se muestran todas
        if (!/^#vehiculo-\d+$/.test(location.hash)) {
            try {
                var g = JSON.parse(sessionStorage.getItem(CLAVE) || 'null');
                var b = document.querySelector('[data-filtro-vehiculos]');
                if (g && b && g.texto) { b.value = g.texto; }
                if (g && g.grupo) { elegirPildora(g.grupo); }
            } catch (x) { /* valor guardado dañado: se ignora */ }
        }
        filtrarVehiculos();
    });

    /* ---------- Registro rápido (respuesta JSON; avisa con el evento vehiculo:registrado) ---------- */
    function avisarRegistrado(form, vehiculo) {
        form.reset();
        sincronizarDependientes(form);
        var dialogo = form.closest('dialog');
        if (dialogo) { dialogo.close(); }
        document.dispatchEvent(new CustomEvent('vehiculo:registrado', { detail: vehiculo }));
    }

    function mostrarExistente(form, datos) {
        var caja = form.querySelector('[data-existente-vehiculo]');
        if (!caja) { return; }
        caja.textContent = '';
        var titulo = document.createElement('p');
        titulo.className = 'fw-semibold mb-2';
        titulo.textContent = datos.mensaje;
        caja.appendChild(titulo);
        var v = datos.vehiculo;
        var boton = document.createElement('button');
        boton.type = 'button';
        boton.className = 'opcion-parecido';
        boton.textContent = 'Usar ' + v.placas + (v.descripcion ? ' · ' + v.descripcion : '') + ' · ' + v.propiedad_etiqueta;
        boton.addEventListener('click', function () { caja.hidden = true; avisarRegistrado(form, v); });
        caja.appendChild(boton);
        caja.hidden = false;
    }

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.matches('[data-registro-rapido-vehiculo]')) { return; }
        e.preventDefault();
        var boton = form.querySelector('button[type="submit"]');
        var errores = form.querySelector('[data-errores-rapido-vehiculo]');
        var existente = form.querySelector('[data-existente-vehiculo]');
        if (boton) { boton.disabled = true; boton.textContent = 'Guardando...'; }
        if (errores) { errores.hidden = true; errores.textContent = ''; }
        if (existente) { existente.hidden = true; }

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
                if (res.estado === 201 && res.datos.ok) { avisarRegistrado(form, res.datos.vehiculo); return; }
                if (res.estado === 409 && res.datos.vehiculo) { mostrarExistente(form, res.datos); return; }
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

    /* ---------- Calcomanía: imprimir ---------- */
    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-accion="imprimir"]')) { window.print(); }
    });
})();
/* Fin Padrón Vehicular */
/* ==========================================================================
   Lector universal (componentes/lector.blade.php): QR con cámara, NFC del
   celular (Android + Chrome) y lectores USB/Bluetooth que "escriben como
   teclado" (RFID, NFC, código de barras). Avisa con el evento
   "lector:elegido" (detail = registro) o "lector:capturado" (detail = texto).
   ========================================================================== */
(function () {
    'use strict';

    var hayCamara = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
    var hayNfc = 'NDEFReader' in window;

    function partes(caja) {
        return {
            entrada: caja.querySelector('[data-lector-entrada]'),
            id: caja.querySelector('[data-lector-id]'),
            elegido: caja.querySelector('[data-lector-elegido]'),
            titulo: caja.querySelector('[data-lector-titulo]'),
            opciones: caja.querySelector('[data-lector-opciones]'),
            estado: caja.querySelector('[data-lector-estado]')
        };
    }

    function estado(caja, texto, tipo) {
        var e = partes(caja).estado;
        if (!e) { return; }
        e.hidden = !texto;
        e.textContent = texto || '';
        e.className = 'lector-estado' + (tipo ? ' ' + tipo : '');
    }

    function elegir(caja, registro) {
        var p = partes(caja);
        if (p.id) { p.id.value = registro.id; }
        if (p.titulo) { p.titulo.textContent = registro.titulo + (registro.detalle ? ' · ' + registro.detalle : ''); }
        if (p.elegido) { p.elegido.hidden = false; }
        if (p.opciones) { p.opciones.hidden = true; p.opciones.textContent = ''; }
        p.entrada.value = '';
        estado(caja, registro.activo ? '' : 'Atención: este registro está dado de baja.', registro.activo ? '' : 'aviso');
        caja.dispatchEvent(new CustomEvent('lector:elegido', { bubbles: true, detail: registro }));
    }

    function limpiar(caja) {
        var p = partes(caja);
        if (p.id) { p.id.value = ''; }
        if (p.elegido) { p.elegido.hidden = true; }
        if (p.opciones) { p.opciones.hidden = true; p.opciones.textContent = ''; }
        estado(caja, '');
    }

    function resolver(caja, texto) {
        texto = (texto || '').trim();
        if (!texto) { return; }
        var p = partes(caja);

        if (caja.dataset.modo === 'capturar') {
            p.entrada.value = texto;
            estado(caja, 'Etiqueta leída: ' + texto, 'ok');
            caja.dispatchEvent(new CustomEvent('lector:capturado', { bubbles: true, detail: texto }));
            return;
        }

        estado(caja, 'Buscando…', 'info');
        var url = caja.dataset.url + '?entrada=' + encodeURIComponent(texto) + (caja.dataset.tipos ? '&tipos=' + encodeURIComponent(caja.dataset.tipos) : '');
        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.json(); })
            .then(function (datos) {
                var lista = datos.resultados || [];
                if (lista.length === 1) { elegir(caja, lista[0]); return; }
                if (!lista.length) { estado(caja, 'No se encontró nada con «' + texto + '». Revisa la etiqueta o búscalo escribiendo.', 'error'); return; }
                // Varias coincidencias: que la persona elija
                estado(caja, 'Hay ' + lista.length + ' coincidencias, elige una:', 'info');
                p.opciones.textContent = '';
                lista.forEach(function (r) {
                    var b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'lector-opcion' + (r.activo ? '' : ' inactiva');
                    var t = document.createElement('strong'); t.textContent = r.titulo;
                    var d = document.createElement('span'); d.textContent = r.detalle || '';
                    b.appendChild(t); b.appendChild(d);
                    b.addEventListener('click', function () { elegir(caja, r); });
                    p.opciones.appendChild(b);
                });
                p.opciones.hidden = false;
            })
            .catch(function () { estado(caja, 'No se pudo consultar. Revisa tu conexión e intenta de nuevo.', 'error'); });
    }

    /* ---------- Cámara: BarcodeDetector si existe; si no (iPhone, Firefox), jsQR ---------- */
    var dialogoCamara = null;
    var corriendo = false;

    function cargarJsQR(url) {
        return new Promise(function (ok, falla) {
            if (window.jsQR) { ok(); return; }
            var s = document.createElement('script');
            s.src = url; s.onload = ok; s.onerror = falla;
            document.head.appendChild(s);
        });
    }

    function abrirCamara(caja) {
        if (!dialogoCamara) {
            dialogoCamara = document.createElement('dialog');
            dialogoCamara.className = 'dialogo dialogo-camara';
            dialogoCamara.setAttribute('aria-label', 'Leer código QR');
            dialogoCamara.innerHTML = '<div class="dialogo-cabecera"><h2><i class="bi bi-qr-code-scan me-2" aria-hidden="true"></i>Apunta al código QR</h2>' +
                '<button type="button" class="btn-cerrar" data-cerrar-camara aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button></div>' +
                '<div class="dialogo-cuerpo"><video playsinline muted></video><p class="lector-estado info" data-camara-estado>Abriendo la cámara…</p></div>';
            document.body.appendChild(dialogoCamara);
            dialogoCamara.querySelector('[data-cerrar-camara]').addEventListener('click', function () { dialogoCamara.close(); });
            dialogoCamara.addEventListener('close', detenerCamara);
        }
        var video = dialogoCamara.querySelector('video');
        var aviso = dialogoCamara.querySelector('[data-camara-estado]');
        dialogoCamara.showModal();

        var detector = ('BarcodeDetector' in window) ? new window.BarcodeDetector({ formats: ['qr_code'] }) : null;
        var preparar = detector ? Promise.resolve() : cargarJsQR(caja.dataset.jsqr);
        var lienzo = document.createElement('canvas');
        var ctx = lienzo.getContext('2d', { willReadFrequently: true });

        preparar
            .then(function () { return navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false }); })
            .then(function (flujo) {
                video.srcObject = flujo;
                return video.play();
            })
            .then(function () {
                aviso.textContent = 'Centra el código dentro de la imagen.';
                corriendo = true;
                var cuadro = function () {
                    if (!corriendo) { return; }
                    if (video.readyState < 2) { requestAnimationFrame(cuadro); return; }
                    var listo = function (texto) {
                        if (texto) { dialogoCamara.close(); resolver(caja, texto); } else { requestAnimationFrame(cuadro); }
                    };
                    if (detector) {
                        detector.detect(video).then(function (c) { listo(c.length ? c[0].rawValue : null); }).catch(function () { requestAnimationFrame(cuadro); });
                    } else {
                        lienzo.width = video.videoWidth; lienzo.height = video.videoHeight;
                        ctx.drawImage(video, 0, 0, lienzo.width, lienzo.height);
                        var img = ctx.getImageData(0, 0, lienzo.width, lienzo.height);
                        var r = window.jsQR(img.data, img.width, img.height, { inversionAttempts: 'dontInvert' });
                        listo(r ? r.data : null);
                    }
                };
                requestAnimationFrame(cuadro);
            })
            .catch(function (err) {
                if (window.console) { console.warn('Lector (cámara):', err); }
                aviso.className = 'lector-estado error';
                aviso.textContent = 'No se pudo usar la cámara. Revisa que la página tenga permiso para usarla (y que sea https).';
            });
    }

    function detenerCamara() {
        corriendo = false;
        if (!dialogoCamara) { return; }
        var video = dialogoCamara.querySelector('video');
        if (video.srcObject) { video.srcObject.getTracks().forEach(function (t) { t.stop(); }); video.srcObject = null; }
    }

    /* ---------- NFC del celular (Web NFC: Android + Chrome) ---------- */
    function leerNfc(caja) {
        var capturar = caja.dataset.modo === 'capturar';
        estado(caja, 'Acerca la tarjeta o etiqueta a la parte trasera del celular…', 'info');
        var control = new AbortController();
        var lector = new window.NDEFReader();
        lector.scan({ signal: control.signal }).then(function () {
            lector.onreading = function (evento) {
                var texto = '';
                // Al asignar una tarjeta se usa su número de serie; al buscar, primero lo que traiga escrito (texto o dirección)
                if (!capturar) {
                    for (var i = 0; i < evento.message.records.length; i++) {
                        var reg = evento.message.records[i];
                        if (reg.recordType === 'text' || reg.recordType === 'url' || reg.recordType === 'absolute-url') {
                            texto = new TextDecoder(reg.encoding || 'utf-8').decode(reg.data);
                            break;
                        }
                    }
                }
                if (!texto) { texto = evento.serialNumber || ''; }
                control.abort();
                if (texto) { resolver(caja, texto); } else { estado(caja, 'La etiqueta no trae datos legibles.', 'error'); }
            };
            lector.onreadingerror = function () { estado(caja, 'No se pudo leer la etiqueta. Intenta de nuevo sin moverla.', 'error'); };
        }).catch(function () {
            estado(caja, 'No se pudo activar el NFC: revisa que esté encendido y que la página tenga permiso.', 'error');
        });
        setTimeout(function () { control.abort(); }, 30000);
    }

    /* ---------- Conexión con la página ---------- */
    function preparar(caja) {
        var c = caja.querySelector('[data-lector-camara]');
        var n = caja.querySelector('[data-lector-nfc]');
        // Al asignar una tarjeta se lee su número de serie: la cámara no aplica
        if (c) { c.hidden = !hayCamara || caja.dataset.modo === 'capturar'; }
        if (n) { n.hidden = !hayNfc; }
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-lector]').forEach(preparar);
    });

    document.addEventListener('keydown', function (e) {
        var entrada = e.target.closest && e.target.closest('[data-lector-entrada]');
        if (!entrada || e.key !== 'Enter') { return; }
        // Los lectores que escriben como teclado terminan con Enter: busca en lugar de enviar el formulario
        e.preventDefault();
        resolver(entrada.closest('[data-lector]'), entrada.value);
    });

    document.addEventListener('paste', function (e) {
        var entrada = e.target.closest && e.target.closest('[data-lector-entrada]');
        if (!entrada) { return; }
        setTimeout(function () { resolver(entrada.closest('[data-lector]'), entrada.value); }, 0);
    });

    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-lector-camara], [data-lector-nfc], [data-lector-limpiar]');
        if (!b) { return; }
        var caja = b.closest('[data-lector]');
        if (b.hasAttribute('data-lector-camara')) { abrirCamara(caja); }
        else if (b.hasAttribute('data-lector-nfc')) { leerNfc(caja); }
        else { limpiar(caja); partes(caja).entrada.focus(); }
    });

    // Al cerrar el diálogo que lo contiene, el lector vuelve a su estado inicial
    document.addEventListener('close', function (e) {
        if (!(e.target instanceof HTMLDialogElement) || e.target === dialogoCamara) { return; }
        e.target.querySelectorAll('[data-lector]').forEach(function (caja) {
            if (caja.dataset.modo !== 'capturar') { limpiar(caja); }
            estado(caja, '');
        });
    }, true);

    // Para pantallas que crean el lector después de cargar o que llenan uno al editar
    window.Lector = { preparar: preparar, elegir: elegir, limpiar: limpiar };
})();
/* Fin Lector universal */
/* ==========================================================================
   Catálogo de llaves: filtros de la lista, selección para imprimir etiquetas,
   exportación con los filtros elegidos, formulario (sede → departamento →
   puesto, lugares según el alcance, horarios, responsable) y baja con
   voucher de reposición.
   ========================================================================== */
(function () {
    'use strict';

    function leer(texto) { try { return JSON.parse(texto || '{}'); } catch (x) { return {}; } }

    /* ---------- Lista: filtros, selección y exportación ---------- */
    var CLAVE = 'plataforma_filtro_llaves';

    function valorFiltro(nombre) {
        var el = document.querySelector('[data-filtro-llaves="' + nombre + '"]');
        return el ? el.value : '';
    }

    function estadoElegido() {
        var b = document.querySelector('[data-filtro-llaves-estado][aria-pressed="true"]');
        return b ? b.dataset.filtroLlavesEstado : '';
    }

    function contar() {
        var marcadas = document.querySelectorAll('[data-chk-llave]:checked').length;
        document.querySelectorAll('[data-conteo-llaves]').forEach(function (n) { n.textContent = marcadas ? '(' + marcadas + ')' : ''; });
        document.querySelectorAll('[data-imprimir-llaves]').forEach(function (b) {
            b.disabled = marcadas === 0;
            b.title = marcadas === 0 ? 'Marca primero las llaves (casilla de cada ficha o botón Todo)' : '';
        });
        document.querySelectorAll('[data-chk-llave]').forEach(function (c) {
            var ficha = c.closest('[data-llave]');
            if (ficha) { ficha.classList.toggle('marcada', c.checked); }
        });
    }

    function actualizarExportar(filtros) {
        var enlace = document.querySelector('[data-exportar-llaves]');
        if (!enlace) { return; }
        var partes = [];
        Object.keys(filtros).forEach(function (k) {
            if (filtros[k] !== '') { partes.push(encodeURIComponent(k) + '=' + encodeURIComponent(filtros[k])); }
        });
        enlace.href = enlace.dataset.base + (partes.length ? '?' + partes.join('&') : '');
    }

    function filtrar() {
        var cont = document.querySelector('[data-llaves]');
        if (!cont) { return; }
        var texto = valorFiltro('texto').toLowerCase().trim();
        var sede = valorFiltro('sede');
        var tipo = valorFiltro('tipo');
        var caducidad = valorFiltro('caducidad');
        var estado = estadoElegido();
        var fichas = cont.querySelectorAll('[data-llave]');
        var visibles = 0;
        fichas.forEach(function (f) {
            var ok = (texto === '' || (f.dataset.texto || '').indexOf(texto) !== -1)
                && (sede === '' || f.dataset.sede === sede)
                && (tipo === '' || f.dataset.tipo === tipo)
                && (caducidad === '' || f.dataset.caducidad === caducidad)
                && (estado === '' || f.dataset.estado === estado);
            f.style.display = ok ? '' : 'none';
            if (ok) { visibles++; }
        });
        var vacio = cont.querySelector('[data-sin-resultados-llaves]');
        if (vacio) { vacio.hidden = visibles !== 0 || fichas.length === 0; }
        actualizarExportar({ q: texto, sede: sede, tipo: tipo, caducidad: caducidad, estado: estado });
        try { sessionStorage.setItem(CLAVE, JSON.stringify({ texto: valorFiltro('texto'), sede: sede, tipo: tipo, caducidad: caducidad, estado: estado })); } catch (x) { /* sin almacenamiento */ }
    }

    function elegirEstado(valor) {
        document.querySelectorAll('[data-filtro-llaves-estado]').forEach(function (x) {
            var on = x.dataset.filtroLlavesEstado === valor;
            x.setAttribute('aria-pressed', String(on));
            x.classList.toggle('active', on);
        });
    }

    document.addEventListener('input', function (e) { if (e.target.matches('[data-filtro-llaves]')) { filtrar(); } });
    document.addEventListener('change', function (e) {
        if (e.target.matches('[data-filtro-llaves]')) { filtrar(); }
        if (e.target.matches('[data-chk-llave]')) { contar(); }
    });

    document.addEventListener('click', function (e) {
        var pill = e.target.closest('[data-filtro-llaves-estado]');
        if (pill) { elegirEstado(pill.dataset.filtroLlavesEstado); filtrar(); return; }

        var todas = e.target.closest('[data-accion="llaves-marcar-todas"]');
        if (todas) {
            var marcar = todas.getAttribute('aria-pressed') !== 'true';
            document.querySelectorAll('[data-llave]').forEach(function (f) {
                var c = f.querySelector('[data-chk-llave]');
                if (c && f.style.display !== 'none') { c.checked = marcar; }
            });
            todas.setAttribute('aria-pressed', String(marcar));
            todas.classList.toggle('activo', marcar);
            contar();
        }
    });

    /* ---------- Formulario: sede → departamento → puesto y lugares según el alcance ---------- */
    function contiene(lista, valor) { return (' ' + (lista || '') + ' ').indexOf(' ' + valor + ' ') !== -1; }

    function acomodar(form) {
        var sede = form.querySelector('[data-llave-sede]').value;
        var alcance = form.querySelector('[data-llave-alcance]').value;

        var depto = form.querySelector('[data-llave-depto]');
        Array.prototype.forEach.call(depto.options, function (o) {
            if (!o.value) { return; }
            var aplica = (o.dataset.sedes || 'todas') === 'todas' || contiene(o.dataset.sedes, sede);
            if (!aplica && o.selected) { depto.value = ''; }
            o.hidden = !aplica || (o.hasAttribute('data-inactivo') && !o.selected);
        });

        var puesto = form.querySelector('[data-llave-puesto]');
        Array.prototype.forEach.call(puesto.options, function (o) {
            if (!o.value) { return; }
            var aplica = depto.value === '' || !o.dataset.deps || contiene(o.dataset.deps, depto.value);
            if (!aplica && o.selected) { puesto.value = ''; }
            o.hidden = !aplica || (o.hasAttribute('data-inactivo') && !o.selected);
        });

        form.querySelectorAll('[data-llave-lugares]').forEach(function (caja) {
            var activa = caja.dataset.llaveLugares === alcance;
            caja.hidden = !activa;
            // Lo que no aplica no se envía (el servidor revalida todo contra la sede)
            caja.querySelectorAll('input, select, textarea').forEach(function (c) { c.disabled = !activa; });
            var buscar = caja.querySelector('[data-buscar-lugar]');
            var texto = buscar ? buscar.value.toLowerCase().trim() : '';
            var deLaSede = 0;
            caja.querySelectorAll('[data-lugar]').forEach(function (fila) {
                var casilla = fila.querySelector('input');
                var enSede = sede !== '' && fila.dataset.sede === sede;
                if (!enSede && casilla.checked) { casilla.checked = false; }
                var disponible = enSede && (!fila.hasAttribute('data-inactivo') || casilla.checked);
                if (disponible) { deLaSede++; }
                fila.hidden = !disponible || (texto !== '' && !casilla.checked && (fila.dataset.nombre || '').indexOf(texto) === -1);
            });
            var sinSede = caja.querySelector('[data-lugares-sin-sede]');
            if (sinSede) { sinSede.hidden = sede !== ''; }
            var vacio = caja.querySelector('[data-lugares-vacio]');
            if (vacio) { vacio.hidden = sede === '' || deLaSede !== 0; }
            if (buscar) { buscar.hidden = deLaSede < 8; }
        });

        var otra = form.querySelector('[data-llave-otra]');
        if (otra) { otra.required = alcance === 'otra'; }
    }

    // El campo "ID externo" usa data-mostrar-si (bloque del Padrón Vehicular): se acomoda con un "change"
    function acomodarTodo(form) {
        acomodar(form);
        var tipo = form.querySelector('[data-llave-tipo]');
        if (tipo) { tipo.dispatchEvent(new Event('change', { bubbles: true })); }
    }

    document.addEventListener('change', function (e) {
        var form = e.target.closest && e.target.closest('[data-form-llave]');
        if (form && e.target.matches('[data-llave-sede], [data-llave-alcance], [data-llave-depto]')) { acomodar(form); }
    });
    document.addEventListener('input', function (e) {
        var form = e.target.closest && e.target.closest('[data-form-llave]');
        if (form && e.target.matches('[data-buscar-lugar]')) { acomodar(form); }
    });

    /* ---------- Horarios ---------- */
    function agregarHorario(form, datos) {
        var plantilla = form.querySelector('[data-plantilla-horario]');
        var fila = plantilla.content.firstElementChild.cloneNode(true);
        if (datos) {
            fila.querySelector('[name="horario_nombre[]"]').value = datos.nombre || '';
            fila.querySelector('[name="horario_inicio[]"]').value = datos.inicio || '';
            fila.querySelector('[name="horario_fin[]"]').value = datos.fin || '';
        }
        form.querySelector('[data-horarios-llave]').appendChild(fila);
        return fila;
    }

    function rellenarHorarios(form, lista) {
        form.querySelector('[data-horarios-llave]').textContent = '';
        (lista || []).forEach(function (h) { agregarHorario(form, h); });
    }

    document.addEventListener('click', function (e) {
        var agregar = e.target.closest('[data-agregar-horario]');
        if (agregar) {
            var fila = agregarHorario(agregar.closest('form'));
            fila.querySelector('input').focus();
            return;
        }
        var quitar = e.target.closest('[data-quitar-horario]');
        if (quitar) { quitar.closest('[data-fila-horario]').remove(); }
    });

    /* ---------- Editar: después del llenado genérico (editar-registro) ---------- */
    document.addEventListener('click', function (e) {
        var boton = e.target.closest('[data-accion="editar-registro"]');
        if (!boton) { return; }
        var dialogo = document.getElementById(boton.dataset.dialogo);
        var form = dialogo && dialogo.querySelector('[data-form-llave]');
        if (!form) { return; }
        var valores = leer(boton.dataset.valores);
        rellenarHorarios(form, valores.horarios);
        var lector = form.querySelector('[data-lector][data-modo="buscar"]');
        if (lector && window.Lector) {
            if (valores.colaborador_id) {
                window.Lector.elegir(lector, { id: valores.colaborador_id, titulo: valores.colaborador_texto || '', detalle: '', activo: true });
            } else {
                window.Lector.limpiar(lector);
            }
        }
        acomodarTodo(form);
    });

    /* ---------- Baja con voucher ---------- */
    function sincronizarCobro(form) {
        var casilla = form.querySelector('[data-cobro-llave]');
        var caja = form.querySelector('[data-caja-cobro-llave]');
        if (!casilla || !caja) { return; }
        caja.hidden = !casilla.checked;
        var monto = form.querySelector('[data-monto-llave]');
        if (monto) { monto.required = casilla.checked; }
    }

    document.addEventListener('change', function (e) {
        if (e.target.matches('[data-cobro-llave]')) { sincronizarCobro(e.target.form); }
    });

    document.addEventListener('click', function (e) {
        var boton = e.target.closest('[data-accion="baja-llave"]');
        if (!boton) { return; }
        var dialogo = document.getElementById('dialogoBajaLlave');
        var form = dialogo && dialogo.querySelector('[data-form-baja-llave]');
        if (!form) { return; }
        form.action = boton.dataset.url;
        form.querySelector('[data-campo-dialogo]').value = 'baja-' + boton.dataset.id;
        dialogo.querySelector('[data-baja-nombre]').textContent = boton.dataset.nombre || '';
        var sugerido = leer(dialogo.dataset.costos)[boton.dataset.tipo];
        var monto = form.querySelector('[data-monto-llave]');
        var nota = form.querySelector('[data-nota-monto]');
        if (monto) { monto.value = sugerido || ''; }
        if (nota) {
            nota.textContent = sugerido
                ? '(sugerido: último cobro de una llave ' + (boton.dataset.tipoTexto || '') + '; puedes ajustarlo)'
                : '(escribe el costo de reposición)';
        }
        sincronizarCobro(form);
        if (typeof dialogo.showModal === 'function') { dialogo.showModal(); }
    });

    /* ---------- Al cerrar (y limpiarse) un diálogo, todo vuelve a acomodarse ---------- */
    document.addEventListener('close', function (e) {
        if (!(e.target instanceof HTMLDialogElement)) { return; }
        e.target.querySelectorAll('[data-form-llave]').forEach(function (form) {
            rellenarHorarios(form, form.closest('#dialogoNuevaLlave') ? [{}] : []);
            acomodarTodo(form);
        });
        e.target.querySelectorAll('[data-form-baja-llave]').forEach(sincronizarCobro);
    }, true);

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-form-llave]').forEach(acomodarTodo);
        document.querySelectorAll('[data-form-baja-llave]').forEach(sincronizarCobro);
        if (!document.querySelector('[data-llaves]')) { return; }
        // Al llegar a una ficha (#llave-12, p. ej. desde el QR) se muestran todas
        if (!/^#llave-\d+$/.test(location.hash)) {
            try {
                var g = JSON.parse(sessionStorage.getItem(CLAVE) || 'null');
                if (g) {
                    ['texto', 'sede', 'tipo', 'caducidad'].forEach(function (k) {
                        var el = document.querySelector('[data-filtro-llaves="' + k + '"]');
                        if (el && g[k]) { el.value = g[k]; }
                    });
                    if (g.estado) { elegirEstado(g.estado); }
                }
            } catch (x) { /* valor guardado dañado: se ignora */ }
        }
        filtrar();
        contar();
    });
})();
/* Fin Catálogo de llaves */
/* ==========================================================================
   Padrones: Gafetes y Vouchers de reposición
   - "Marcar todos" (solo los visibles con el filtro actual) e "Imprimir"
     los marcados, con el conteo en el botón.
   - Diálogos de editar y de baja: título con la nomenclatura, tipo nuevo y
     la caja de cobro (monto y responsable) que aparece al marcar "Aplica CXC".
   ========================================================================== */
(function () {
    'use strict';

    function casillas() { return Array.prototype.slice.call(document.querySelectorAll('[data-casilla-gafete]')); }

    function visible(casilla) {
        var ficha = casilla.closest('[data-ficha]');
        return !ficha || ficha.style.display !== 'none';
    }

    function actualizarConteo() {
        var marcadas = casillas().filter(function (c) { return c.checked; }).length;
        var conteo = document.querySelector('[data-conteo-gafetes]');
        if (conteo) { conteo.hidden = marcadas === 0; conteo.textContent = String(marcadas); }
        var visibles = casillas().filter(visible);
        var todas = visibles.length > 0 && visibles.every(function (c) { return c.checked; });
        var boton = document.querySelector('[data-marcar-gafetes]');
        if (boton) {
            boton.setAttribute('aria-pressed', String(todas));
            var texto = boton.querySelector('[data-texto-marcar]');
            if (texto) { texto.textContent = todas ? 'Desmarcar todos' : 'Marcar todos'; }
        }
        if (marcadas > 0) {
            var aviso = document.querySelector('[data-aviso-sin-marcar]');
            if (aviso) { aviso.hidden = true; }
        }
    }

    document.addEventListener('click', function (e) {
        if (!e.target.closest('[data-marcar-gafetes]')) { return; }
        var visibles = casillas().filter(visible);
        var marcar = !visibles.every(function (c) { return c.checked; });
        visibles.forEach(function (c) { c.checked = marcar; });
        actualizarConteo();
    });

    document.addEventListener('change', function (e) {
        if (e.target.matches('[data-casilla-gafete]')) { actualizarConteo(); }
    });

    // Al cambiar los filtros, el botón refleja si los visibles están marcados
    ['input', 'change', 'click'].forEach(function (tipo) {
        document.addEventListener(tipo, function (e) {
            if (e.target.closest && e.target.closest('[data-filtro-texto="gafetes"], [data-filtro-sede="gafetes"], [data-filtro-tipo="gafetes"], [data-filtro-estado="gafetes"]')) {
                setTimeout(actualizarConteo, 0);
            }
        });
    });

    // Imprimir sin nada marcado: aviso en lugar de abrir una pestaña vacía
    document.addEventListener('submit', function (e) {
        if (!e.target.matches('[data-form-imprimir-gafetes]')) { return; }
        if (casillas().some(function (c) { return c.checked; })) { return; }
        e.preventDefault();
        var aviso = document.querySelector('[data-aviso-sin-marcar]');
        if (aviso) { aviso.hidden = false; aviso.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
    });

    /* ---------- Casilla que muestra un bloque (y lo hace obligatorio) ---------- */
    function sincronizarCaja(casilla) {
        var caja = document.querySelector(casilla.getAttribute('data-muestra-si-marcado'));
        if (!caja) { return; }
        caja.hidden = !casilla.checked;
        caja.querySelectorAll('input, select, textarea').forEach(function (c) {
            c.disabled = !casilla.checked;
            if (c.hasAttribute('data-requerido-si-marcado')) { c.required = casilla.checked; }
        });
    }

    document.addEventListener('change', function (e) {
        if (e.target.matches('[data-muestra-si-marcado]')) { sincronizarCaja(e.target); }
    });

    function sincronizarFormulario(form) {
        form.querySelectorAll('[data-muestra-si-marcado]').forEach(sincronizarCaja);
        // Lista de tipo: muestra u oculta "Nombre del tipo nuevo" (data-mostrar-si)
        form.querySelectorAll('select[name="tipo_gafete_id"]').forEach(function (s) {
            s.dispatchEvent(new Event('change', { bubbles: true }));
        });
    }

    // Editar / dar de baja: después del llenado genérico (editar-registro)
    document.addEventListener('click', function (e) {
        var boton = e.target.closest('[data-accion="editar-registro"]');
        if (!boton) { return; }
        var dialogo = document.getElementById(boton.dataset.dialogo);
        var form = dialogo && dialogo.querySelector('[data-form-gafete]');
        if (!form) { return; }
        var titulo = dialogo.querySelector('[data-titulo-registro]');
        if (titulo && boton.dataset.tituloRegistro) { titulo.textContent = boton.dataset.tituloRegistro; }
        sincronizarFormulario(form);
    });

    // Al cerrar (y limpiarse) el diálogo, sus bloques dependientes se acomodan otra vez
    document.addEventListener('close', function (e) {
        if (!(e.target instanceof HTMLDialogElement)) { return; }
        e.target.querySelectorAll('[data-form-gafete]').forEach(sincronizarFormulario);
    }, true); // "close" no burbujea: fase de captura, después de la limpieza común

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-muestra-si-marcado]').forEach(sincronizarCaja);
        actualizarConteo();
    });
})();
/* Fin Padrones: Gafetes y Vouchers de reposición */
/* ==========================================================================
   Padrones: Equipos de seguridad y Estacionamientos
   - Equipos: filtro de fichas (texto, sede, tipo y estado), edición (estado
     de solo lectura si está ASIGNADO o de BAJA), aviso de número de serie
     repetido, costo sugerido por marca y modelo, "Ver QR" y baja con
     voucher (el monto y el responsable aparecen al marcar "Aplica CXC").
   - Estacionamientos: los grupos por sede se ocultan si el filtro los deja
     sin zonas; el cupo solo aplica a estacionamientos.
   Los campos que dependen de otro usan data-mostrar-si (Padrón Vehicular).
   ========================================================================== */
(function () {
    'use strict';

    function leer(texto) { try { return JSON.parse(texto || '{}'); } catch (x) { return {}; } }

    // El manejador genérico de data-mostrar-si escucha "change" en el formulario
    function resincronizar(form) {
        var campo = form && form.querySelector('[data-mostrar-si]') && form.querySelector('select[name]');
        if (campo) { campo.dispatchEvent(new Event('change', { bubbles: true })); }
    }

    /* ---------- "Aplica CXC": muestra monto y responsable ---------- */
    function sincronizarCobro(casilla) {
        var caja = document.querySelector(casilla.getAttribute('data-muestra-si-marcado'));
        if (!caja) { return; }
        caja.hidden = !casilla.checked;
        caja.querySelectorAll('input, select, textarea').forEach(function (c) { c.disabled = !casilla.checked; });
        caja.querySelectorAll('[data-requerido-si-marcado]').forEach(function (c) { c.required = casilla.checked; });
    }

    document.addEventListener('change', function (e) {
        if (e.target.matches('[data-muestra-si-marcado]')) { sincronizarCobro(e.target); }
    });

    // Con cobro, el responsable es obligatorio (el lector guarda su id en un campo oculto)
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.matches('[data-form-baja-equipo]')) { return; }
        var casilla = form.querySelector('[data-muestra-si-marcado]');
        var id = form.querySelector('[data-lector-id]');
        if (casilla && casilla.checked && id && !id.value) {
            e.preventDefault();
            var estado = form.querySelector('[data-lector] [data-lector-estado]');
            if (estado) { estado.hidden = false; estado.className = 'lector-estado error'; estado.textContent = 'Elige al colaborador responsable al que se le cobrará.'; }
            var entrada = form.querySelector('[data-lector-entrada]');
            if (entrada) { entrada.focus(); }
        }
    }, true);

    /* ---------- Aviso en vivo de número de serie ya registrado ---------- */
    function serie(texto) { return (texto || '').trim().replace(/\s+/g, ' ').toUpperCase(); }

    function avisarSerie(campo) {
        var aviso = campo.form && campo.form.querySelector('[data-aviso-serie]');
        if (!aviso) { return; }
        var valor = serie(campo.value);
        var existentes = leer(campo.getAttribute('data-series-existentes'));
        if (!Array.isArray(existentes)) { existentes = []; }
        var repetida = valor !== '' && valor !== serie(campo.dataset.original) && existentes.indexOf(valor) !== -1;
        aviso.hidden = !repetida;
        aviso.className = 'small mb-2 text-warning fw-semibold';
        aviso.textContent = repetida ? 'Ese número de serie ya existe en el inventario.' : '';
    }

    /* ---------- Costo sugerido por marca y modelo (solo si no se escribió a mano) ---------- */
    function sugerirCosto(form) {
        var costo = form.querySelector('[data-costos-equipo]');
        var marca = form.querySelector('[data-costo-marca]');
        var modelo = form.querySelector('[data-costo-modelo]');
        if (!costo || !marca || !modelo) { return; }
        if (costo.value !== '' && costo.dataset.sugerido !== '1') { return; }
        var monto = leer(costo.getAttribute('data-costos-equipo'))[serie(marca.value) + '|' + serie(modelo.value)];
        var nota = costo.form.querySelector('[data-nota-costo]');
        if (monto) {
            costo.value = monto;
            costo.dataset.sugerido = '1';
            if (nota) { nota.textContent = '(sugerido según altas anteriores de este modelo)'; }
        } else if (costo.dataset.sugerido === '1') {
            costo.value = '';
            costo.dataset.sugerido = '';
            if (nota) { nota.textContent = '(para el voucher, si algún día se da de baja)'; }
        }
    }

    document.addEventListener('input', function (e) {
        var el = e.target;
        if (el.matches('[data-series-existentes]')) { avisarSerie(el); }
        if (el.matches('[data-costo-marca], [data-costo-modelo]') && el.form) { sugerirCosto(el.form); }
        if (el.matches('[data-costos-equipo]')) { el.dataset.sugerido = ''; }
    });

    /* ---------- Editar equipo y abrir la baja (después del llenado genérico) ---------- */
    var ESTADOS_FIJOS = {
        asignado: 'ASIGNADO — lo cambia Responsivas cuando se devuelva el equipo.',
        baja: 'BAJA/PERDIDO — para volver a usarlo, oprime «Reactivar» en su ficha.'
    };

    document.addEventListener('click', function (e) {
        var boton = e.target.closest('[data-accion="editar-registro"]');
        if (!boton) { return; }
        var dialogo = document.getElementById(boton.dataset.dialogo);
        if (!dialogo) { return; }

        var form = dialogo.querySelector('[data-form-equipo]');
        if (form) {
            var valores = leer(boton.dataset.valores);
            // Los campos dependientes pudieron quedar deshabilitados al ocultarse
            form.querySelectorAll('[data-mostrar-si] input').forEach(function (c) { c.disabled = false; c.value = ''; });
            resincronizar(form);
            var editable = form.querySelector('[data-estado-editable]');
            var fijo = form.querySelector('[data-estado-fijo]');
            var texto = ESTADOS_FIJOS[valores.estado];
            if (editable) {
                editable.hidden = !!texto;
                editable.querySelectorAll('select').forEach(function (s) { s.disabled = !!texto; });
            }
            if (fijo) { fijo.hidden = !texto; fijo.textContent = texto ? 'Estado actual: ' + texto : ''; }
            var campoSerie = form.querySelector('[data-series-existentes]');
            if (campoSerie) { campoSerie.dataset.original = campoSerie.value; avisarSerie(campoSerie); }
            var costo = form.querySelector('[data-costos-equipo]');
            if (costo) { costo.dataset.sugerido = ''; }
        }

        var zona = dialogo.querySelector('[data-form-zona]');
        if (zona) { resincronizar(zona); }

        var baja = dialogo.querySelector('[data-form-baja-equipo]');
        if (baja) {
            var nombre = dialogo.querySelector('[data-baja-nombre]');
            if (nombre) { nombre.textContent = boton.dataset.bajaNombre || ''; }
            baja.querySelectorAll('[data-lector]').forEach(function (caja) { if (window.Lector) { window.Lector.limpiar(caja); } });
            baja.querySelectorAll('[data-muestra-si-marcado]').forEach(sincronizarCobro);
        }
    });

    /* ---------- Ver QR ---------- */
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-ver-qr-equipo]');
        if (!b) { return; }
        var d = document.getElementById('dialogoQrEquipo');
        if (!d) { return; }
        d.querySelector('[data-qr-nombre]').textContent = b.dataset.nombre || '';
        d.querySelector('[data-qr-imagen]').src = b.dataset.qr;
        d.querySelector('[data-qr-enlace]').textContent = b.dataset.enlace || '';
        var imprimir = d.querySelector('[data-qr-imprimir]');
        if (imprimir) { imprimir.hidden = !b.dataset.imprimir; imprimir.href = b.dataset.imprimir || '#'; }
        if (typeof d.showModal === 'function' && !d.open) { d.showModal(); }
    });

    /* ---------- Al cerrar (y limpiarse) un diálogo, se acomoda de nuevo ---------- */
    function acomodar(raiz) {
        raiz.querySelectorAll('[data-form-equipo], [data-form-zona]').forEach(function (form) {
            resincronizar(form);
            var aviso = form.querySelector('[data-aviso-serie]');
            if (aviso) { aviso.hidden = true; }
            var nota = form.querySelector('[data-nota-costo]');
            if (nota) { nota.textContent = '(para el voucher, si algún día se da de baja)'; }
            var editable = form.querySelector('[data-estado-editable]');
            if (editable) { editable.hidden = false; editable.querySelectorAll('select').forEach(function (s) { s.disabled = false; }); }
            var fijo = form.querySelector('[data-estado-fijo]');
            if (fijo) { fijo.hidden = true; }
        });
        raiz.querySelectorAll('[data-muestra-si-marcado]').forEach(sincronizarCobro);
    }

    document.addEventListener('close', function (e) {
        if (e.target instanceof HTMLDialogElement) { acomodar(e.target); }
    }, true);

    /* ---------- Filtro de equipos: texto, sede, tipo y estado ---------- */
    var CLAVE = 'plataforma_filtro_equipos';

    function valorFiltro(nombre) {
        var el = document.querySelector('[data-filtro-equipos="' + nombre + '"]');
        return el ? el.value : '';
    }

    function filtrarEquipos() {
        var cont = document.querySelector('[data-equipos]');
        if (!cont) { return; }
        var texto = valorFiltro('texto').toLowerCase().trim();
        var sede = valorFiltro('sede');
        var tipo = valorFiltro('tipo');
        var pill = document.querySelector('[data-filtro-estado-equipo][aria-pressed="true"]');
        var estado = pill ? pill.dataset.filtroEstadoEquipo : '';
        var fichas = cont.querySelectorAll('[data-equipo]');
        var visibles = 0;
        fichas.forEach(function (f) {
            var ok = (texto === '' || (f.dataset.texto || '').indexOf(texto) !== -1)
                && (sede === '' || f.dataset.sede === sede)
                && (tipo === '' || f.dataset.tipo === tipo)
                && (estado === '' || f.dataset.estado === estado);
            f.style.display = ok ? '' : 'none';
            if (ok) { visibles++; }
        });
        var vacio = cont.querySelector('[data-sin-resultados-equipos]');
        if (vacio) { vacio.hidden = visibles !== 0 || fichas.length === 0; }
        try { sessionStorage.setItem(CLAVE, JSON.stringify({ texto: valorFiltro('texto'), sede: sede, tipo: tipo, estado: estado })); } catch (x) { /* sin almacenamiento */ }
    }

    function elegirEstado(valor) {
        document.querySelectorAll('[data-filtro-estado-equipo]').forEach(function (x) {
            var on = x.dataset.filtroEstadoEquipo === valor;
            x.setAttribute('aria-pressed', String(on));
            x.classList.toggle('active', on);
        });
    }

    document.addEventListener('input', function (e) { if (e.target.matches('[data-filtro-equipos]')) { filtrarEquipos(); } });
    document.addEventListener('change', function (e) { if (e.target.matches('[data-filtro-equipos]')) { filtrarEquipos(); } });
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-filtro-estado-equipo]');
        if (!b) { return; }
        elegirEstado(b.dataset.filtroEstadoEquipo);
        filtrarEquipos();
    });

    /* ---------- Estacionamientos: ocultar las sedes que el filtro deja vacías ---------- */
    function gruposZonas() {
        document.querySelectorAll('[data-grupo-zonas]').forEach(function (g) {
            var alguna = Array.prototype.some.call(g.querySelectorAll('[data-ficha]'), function (f) { return f.style.display !== 'none'; });
            g.hidden = !alguna;
        });
    }

    function despuesDelFiltro(e) {
        var el = e.target.closest && e.target.closest('[data-filtro-texto="zonas"], [data-filtro-sede="zonas"], [data-filtro-tipo="zonas"]');
        if (el) { setTimeout(gruposZonas, 0); }
    }
    document.addEventListener('input', despuesDelFiltro);
    document.addEventListener('change', despuesDelFiltro);
    document.addEventListener('click', despuesDelFiltro);

    document.addEventListener('DOMContentLoaded', function () {
        acomodar(document);
        document.querySelectorAll('dialog[data-abrir-al-cargar] [data-series-existentes]').forEach(avisarSerie);
        setTimeout(gruposZonas, 0);

        if (!document.querySelector('[data-equipos]')) { return; }
        // Al llegar a una ficha (#equipo-12, p. ej. desde el QR) se muestran todas
        if (!/^#equipo-\d+$/.test(location.hash)) {
            try {
                var g = JSON.parse(sessionStorage.getItem(CLAVE) || 'null');
                if (g) {
                    ['texto', 'sede', 'tipo'].forEach(function (k) {
                        var el = document.querySelector('[data-filtro-equipos="' + k + '"]');
                        // Solo si el valor guardado todavía existe en la lista
                        if (el && g[k] && (el.tagName !== 'SELECT' || el.querySelector('option[value="' + g[k] + '"]'))) { el.value = g[k]; }
                    });
                    if (g.estado) { elegirEstado(g.estado); }
                }
            } catch (x) { /* valor guardado dañado: se ignora */ }
        }
        filtrarEquipos();
    });
})();
/* Fin Padrones: Equipos de seguridad y Estacionamientos */
/* ==========================================================================
   Rutas de transporte: diálogo "Configurar Ruta y Horarios" con horarios y
   paraderos dinámicos (plantillas <template data-plantilla-horario> y
   <template data-plantilla-paradero> de padrones/rutas/sede.blade.php).
   - Agregar horario copia los paraderos del último (como en SEGCAT).
   - Siempre queda al menos un horario: su botón "Quitar" se deshabilita.
   - Editar (data-accion="editar-ruta") llena la ruta y rehace sus horarios.
   - Al cerrar el diálogo, vuelve a un solo horario vacío.
   ========================================================================== */
(function () {
    'use strict';

    function plantilla(selector) {
        var t = document.querySelector(selector);
        return t ? t.innerHTML : '';
    }

    function elemento(html) {
        var caja = document.createElement('div');
        caja.innerHTML = html.trim();
        return caja.firstElementChild;
    }

    function prefijo(form) { return form.getAttribute('data-prefijo') || 'ruta'; }

    function campo(raiz, nombre) { return raiz.querySelector('[name="' + nombre + '"]'); }

    function renumerar(form) {
        var bloques = form.querySelectorAll('[data-horario]');
        bloques.forEach(function (b, i) {
            var titulo = b.querySelector('[data-titulo-horario]');
            if (titulo) { titulo.textContent = 'Horario ' + (i + 1); }
            var quitar = b.querySelector('[data-quitar-horario]');
            if (quitar) {
                quitar.disabled = bloques.length <= 1;
                quitar.title = bloques.length <= 1 ? 'Una ruta necesita al menos un horario' : 'Quitar este horario';
            }
        });
    }

    function nuevaParada(form, bloque, valores) {
        var h = bloque.getAttribute('data-indice');
        var p = parseInt(bloque.getAttribute('data-siguiente-paradero') || '0', 10);
        bloque.setAttribute('data-siguiente-paradero', String(p + 1));
        var fila = elemento(plantilla('template[data-plantilla-paradero]')
            .replace(/__H__/g, h).replace(/__P__/g, String(p)).replace(/__F__/g, prefijo(form)));
        if (!fila) { return null; }
        if (valores) {
            fila.querySelector('input[type="text"]').value = valores.nombre || '';
            fila.querySelector('input[type="time"]').value = valores.hora || '';
        }
        bloque.querySelector('[data-paraderos]').appendChild(fila);
        return fila;
    }

    function nuevoHorario(form, valores) {
        var cont = form.querySelector('[data-horarios]');
        var h = parseInt(cont.getAttribute('data-siguiente') || '0', 10);
        cont.setAttribute('data-siguiente', String(h + 1));
        var bloque = elemento(plantilla('template[data-plantilla-horario]')
            .replace(/__H__/g, String(h)).replace(/__F__/g, prefijo(form)));
        if (!bloque) { return null; }
        cont.appendChild(bloque);
        valores = valores || {};
        var base = 'horarios[' + h + ']';
        bloque.querySelector('[data-horario-id]').value = valores.id || '';
        campo(bloque, base + '[nombre]').value = valores.nombre || '';
        campo(bloque, base + '[hora_inicio]').value = valores.hora_inicio || '';
        campo(bloque, base + '[hora_fin]').value = valores.hora_fin || '';
        var dias = valores.dias || [];
        bloque.querySelectorAll('input[name="' + base + '[dias][]"]').forEach(function (c) { c.checked = dias.indexOf(c.value) !== -1; });
        (valores.paraderos || []).forEach(function (p) { nuevaParada(form, bloque, p); });
        renumerar(form);
        return bloque;
    }

    function reiniciar(form, horarios) {
        var cont = form.querySelector('[data-horarios]');
        if (!cont) { return; }
        cont.innerHTML = '';
        cont.setAttribute('data-siguiente', '0');
        (horarios && horarios.length ? horarios : [null]).forEach(function (h) { nuevoHorario(form, h); });
    }

    document.addEventListener('click', function (e) {
        var form = e.target.closest('form[data-form-ruta]');

        if (form && e.target.closest('[data-agregar-horario]')) {
            var bloques = form.querySelectorAll('[data-horario]');
            var ultimo = bloques[bloques.length - 1];
            var copia = [];
            if (ultimo) {
                ultimo.querySelectorAll('[data-paradero-fila]').forEach(function (f) {
                    copia.push({ nombre: f.querySelector('input[type="text"]').value, hora: f.querySelector('input[type="time"]').value });
                });
            }
            var nuevo = nuevoHorario(form, { paraderos: copia });
            if (nuevo) {
                nuevo.scrollIntoView({ block: 'nearest' });
                var nombre = nuevo.querySelector('input[type="text"]');
                if (nombre) { nombre.focus(); }
            }
            return;
        }

        var quitarHorario = form && e.target.closest('[data-quitar-horario]');
        if (quitarHorario) {
            if (form.querySelectorAll('[data-horario]').length > 1) {
                quitarHorario.closest('[data-horario]').remove();
                renumerar(form);
            }
            return;
        }

        var agregarParada = form && e.target.closest('[data-agregar-paradero]');
        if (agregarParada) {
            var fila = nuevaParada(form, agregarParada.closest('[data-horario]'));
            if (fila) { fila.querySelector('input[type="text"]').focus(); }
            return;
        }

        var quitarParada = form && e.target.closest('[data-quitar-paradero]');
        if (quitarParada) {
            quitarParada.closest('[data-paradero-fila]').remove();
            return;
        }

        // Editar: datos de la ruta y sus horarios desde data-valores
        var editar = e.target.closest('[data-accion="editar-ruta"]');
        if (editar) {
            var dialogo = document.getElementById(editar.dataset.dialogo);
            var f = dialogo && dialogo.querySelector('form[data-form-ruta]');
            if (!f) { return; }
            var v = {};
            try { v = JSON.parse(editar.dataset.valores || '{}'); } catch (x) { /* sin valores */ }
            f.action = editar.dataset.url;
            var marca = f.querySelector('[data-campo-dialogo]');
            if (marca) { marca.value = 'editar-' + editar.dataset.id; }
            f.querySelectorAll('input[name="sentido"]').forEach(function (r) { r.checked = r.value === v.sentido; });
            ['nombre', 'turno_id', 'proveedor_id', 'costo_maximo_taxi'].forEach(function (n) {
                var c = campo(f, n);
                if (c) { c.value = (v[n] === null || v[n] === undefined) ? '' : String(v[n]); }
            });
            reiniciar(f, v.horarios);
            if (typeof dialogo.showModal === 'function' && !dialogo.open) { dialogo.showModal(); }
        }
    });

    // Al cerrar (Cancelar, X o Esc) el diálogo vuelve a un solo horario vacío
    document.addEventListener('close', function (e) {
        if (!(e.target instanceof HTMLDialogElement) || e.target.hasAttribute('data-conservar-al-cerrar')) { return; }
        e.target.querySelectorAll('form[data-form-ruta]').forEach(function (form) { reiniciar(form, null); });
    }, true);

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('form[data-form-ruta]').forEach(renumerar);
    });
})();
/* Fin Rutas de transporte */
/* ==========================================================================
   Firma autógrafa (componentes/firma.blade.php): dibuja con dedo, lápiz o
   mouse y deja la imagen (JPEG, ligera) en el campo oculto al soltar.
   Un formulario con firma obligatoria vacía no se envía.
   ========================================================================== */
(function () {
    'use strict';

    function preparar(caja) {
        var lienzo = caja.querySelector('[data-firma-lienzo]');
        if (!lienzo || lienzo.dataset.listo) { return; }
        lienzo.dataset.listo = '1';
        var ctx = lienzo.getContext('2d');
        var valor = caja.querySelector('[data-firma-valor]');
        var guia = caja.querySelector('[data-firma-guia]');
        var dibujando = false;
        var trazos = 0;

        function pos(e) {
            var r = lienzo.getBoundingClientRect();
            return { x: (e.clientX - r.left) * (lienzo.width / r.width), y: (e.clientY - r.top) * (lienzo.height / r.height) };
        }
        function exportar() {
            if (!trazos) { valor.value = ''; return; }
            // Fondo blanco + JPEG: pocos KB (los firewalls del hosting rechazan envíos grandes)
            var copia = document.createElement('canvas');
            copia.width = lienzo.width; copia.height = lienzo.height;
            var c = copia.getContext('2d');
            c.fillStyle = '#ffffff'; c.fillRect(0, 0, copia.width, copia.height);
            c.drawImage(lienzo, 0, 0);
            valor.value = copia.toDataURL('image/jpeg', 0.7);
        }

        lienzo.addEventListener('pointerdown', function (e) {
            dibujando = true;
            lienzo.setPointerCapture(e.pointerId);
            ctx.lineWidth = 2.5; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#0f172a';
            var p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y);
            if (guia) { guia.hidden = true; }
            e.preventDefault();
        });
        lienzo.addEventListener('pointermove', function (e) {
            if (!dibujando) { return; }
            var p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke();
            trazos++;
            e.preventDefault();
        });
        ['pointerup', 'pointercancel'].forEach(function (ev) {
            lienzo.addEventListener(ev, function () { if (dibujando) { dibujando = false; exportar(); caja.classList.remove('falta'); } });
        });

        caja.limpiarFirma = function () {
            ctx.clearRect(0, 0, lienzo.width, lienzo.height);
            trazos = 0; valor.value = '';
            if (guia) { guia.hidden = false; }
        };
    }

    document.addEventListener('DOMContentLoaded', function () { document.querySelectorAll('[data-firma]').forEach(preparar); });
    // Por si el recuadro aparece después (filas dinámicas, diálogos cargados)
    document.addEventListener('pointerdown', function (e) {
        var caja = e.target.closest && e.target.closest('[data-firma]');
        if (caja && !caja.querySelector('[data-firma-lienzo]').dataset.listo) { preparar(caja); }
    }, true);

    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-firma-limpiar]');
        if (!b) { return; }
        var caja = b.closest('[data-firma]');
        preparar(caja);
        caja.limpiarFirma();
    });

    document.addEventListener('submit', function (e) {
        var faltan = Array.prototype.filter.call(e.target.querySelectorAll('[data-firma-requerida]'), function (v) { return !v.value; });
        if (!faltan.length) { return; }
        e.preventDefault();
        e.stopImmediatePropagation();
        faltan.forEach(function (v) { v.closest('[data-firma]').classList.add('falta'); });
        faltan[0].closest('[data-firma]').scrollIntoView({ behavior: 'smooth', block: 'center' });
    }, true);

    // Al cerrar el diálogo que la contiene, la firma se borra
    document.addEventListener('close', function (e) {
        if (!(e.target instanceof HTMLDialogElement)) { return; }
        e.target.querySelectorAll('[data-firma]').forEach(function (caja) { preparar(caja); caja.limpiarFirma(); caja.classList.remove('falta'); });
    }, true);

    window.Firma = { preparar: preparar };
})();
/* Fin Firma autógrafa */
/* ==========================================================================
   Bitácora de accesos (seguridad/accesos): "Registro Inteligente de Ingreso"
   (bloques según el tipo de persona, sugerencias al escribir, gafetes libres
   de la sede, acompañantes con su gafete), "Dar Salida" rápida, diálogos de
   las tarjetas (zona, salida a tour, regreso) y filtro de las tarjetas.
   Los escaneos los hace el lector universal (evento lector:elegido).
   ========================================================================== */
(function () {
    'use strict';

    var CLAVE_SEDE = 'plataforma_accesos_sede';
    var gafetesPorSede = {};
    var esperas = {};
    var destinoProvisional = null;

    function lista(texto) { return (texto || '').split(/\s+/).filter(Boolean); }
    function dialogoIngreso() { return document.querySelector('[data-dialogo-ingreso]'); }
    function formIngreso() { return document.querySelector('[data-form-acceso]'); }
    function radio(form, nombre) { var r = form.querySelector('input[name="' + nombre + '"]:checked'); return r ? r.value : ''; }
    function sedeDe(form) { var s = form && form.querySelector('[data-acceso-sede]'); return s ? s.value : ''; }
    function token() { var t = document.querySelector('[data-form-acceso] [name="_token"]') || document.querySelector('form [name="_token"]'); return t ? t.value : ''; }
    function urlDe(clave) { var d = dialogoIngreso(); return d ? d.dataset[clave] : ''; }
    function esperar(clave, fn, ms) { clearTimeout(esperas[clave]); esperas[clave] = setTimeout(fn, ms || 250); }
    function pedirJson(url) {
        return fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { if (!r.ok) { throw new Error(String(r.status)); } return r.json(); });
    }
    function elemento(etiqueta, clase, texto) {
        var e = document.createElement(etiqueta);
        if (clase) { e.className = clase; }
        if (texto !== undefined && texto !== null) { e.textContent = texto; }
        return e;
    }

    /* ---------- Bloques según tipo, forma de llegada, motivo y reserva ---------- */
    function sincronizar(form) {
        if (!form) { return; }
        var tipo = radio(form, 'tipo');
        var modo = radio(form, 'modo_arribo');
        var motivo = radio(form, 'motivo_visita');
        var reserva = radio(form, 'tiene_reserva');
        form.querySelectorAll('[data-condicion]').forEach(function (b) {
            var ok = true;
            if (b.hasAttribute('data-solo-tipos')) { ok = ok && lista(b.dataset.soloTipos).indexOf(tipo) !== -1; }
            if (b.hasAttribute('data-solo-motivo')) { ok = ok && b.dataset.soloMotivo === motivo; }
            if (b.hasAttribute('data-solo-reserva')) { ok = ok && b.dataset.soloReserva === reserva; }
            if (b.hasAttribute('data-solo-vehiculo')) { ok = ok && (tipo === 'emergencia' || modo === 'auto'); }
            b.hidden = !ok;
        });
        // Un campo oculto no se envía (SEGCAT mandaba los de todos los bloques y el servidor tomaba el equivocado)
        form.querySelectorAll('input, select, textarea').forEach(function (c) {
            if (c.type === 'hidden' && (c.name === '_token' || c.name === '_dialogo' || c.hasAttribute('data-acceso-sede'))) { return; }
            c.disabled = !!c.closest('[data-condicion][hidden]');
        });
        form.querySelectorAll('[data-etiqueta-tipo]').forEach(function (e) { e.hidden = lista(e.dataset.etiquetaTipo).indexOf(tipo) === -1; });
        sugerirTipoGafete(form, tipo, motivo);
        acotarPorSede(form, sedeDe(form));
    }

    // "Tipo de Gafete" se sugiere al cambiar el tipo de persona (el guardia lo puede cambiar)
    function sugerirTipoGafete(form, tipo, motivo) {
        var clave = tipo + '|' + motivo;
        if (form.dataset.tipoSugerido === clave) { return; }
        form.dataset.tipoSugerido = clave;
        var sel = form.querySelector('[data-filtro-tipo-gafete]');
        if (!sel) { return; }
        var esperados = tipo === 'proveedor' ? ['proveedor'] : tipo === 'contratista' ? ['contratista']
            : tipo === 'visitante' ? (motivo === 'rh' ? ['recursos humanos', 'rrhh', 'capital humano', 'visitante'] : ['visitante']) : [];
        var elegido = '';
        esperados.forEach(function (esperado) {
            if (elegido) { return; }
            Array.prototype.forEach.call(sel.options, function (o) {
                if (!elegido && o.value && (o.dataset.nombre || '').indexOf(esperado) !== -1) { elegido = o.value; }
            });
        });
        sel.value = elegido;
    }

    // Zonas y departamentos solo de la sede elegida
    function acotarOpciones(select, sede, conservar) {
        if (!select) { return; }
        Array.prototype.forEach.call(select.options, function (o) {
            if (!o.value) { return; }
            var aplica;
            if (o.hasAttribute('data-todas')) {
                aplica = o.dataset.todas === '1' || lista((o.dataset.sedes || '').replace(/,/g, ' ')).indexOf(String(sede)) !== -1;
            } else {
                aplica = String(o.dataset.sede) === String(sede);
            }
            o.hidden = !aplica;
            o.disabled = !aplica;
        });
        if (!conservar && select.selectedOptions.length && select.selectedOptions[0].disabled) { select.value = ''; }
    }

    function acotarPorSede(form, sede) {
        acotarOpciones(form.querySelector('[data-zona-sede]'), sede);
        acotarOpciones(form.querySelector('[data-departamento-sede]'), sede);
    }

    /* ---------- Gafetes libres de la sede ---------- */
    function cargarGafetes(sede, fresco) {
        if (!sede) { return Promise.resolve([]); }
        if (!fresco && gafetesPorSede[sede]) { return Promise.resolve(gafetesPorSede[sede]); }
        return pedirJson(urlDe('urlGafetes') + '?sede=' + encodeURIComponent(sede))
            .then(function (d) { gafetesPorSede[sede] = d.resultados || []; return gafetesPorSede[sede]; })
            .catch(function () { return []; });
    }

    function gafetesElegidos(form, excepto) {
        var ids = [];
        var principal = form.querySelector('[name="gafete_id"]');
        if (principal && principal.value && !principal.disabled) { ids.push(principal.value); }
        form.querySelectorAll('[data-gafete-acompanante]').forEach(function (s) { if (s !== excepto && s.value) { ids.push(s.value); } });
        return ids;
    }

    function llenarGafetesAcompanantes(form) {
        cargarGafetes(sedeDe(form)).then(function (gafetes) {
            form.querySelectorAll('[data-gafete-acompanante]').forEach(function (sel) {
                var actual = sel.value || sel.dataset.valor || '';
                var ocupados = gafetesElegidos(form, sel);
                sel.textContent = '';
                sel.appendChild(new Option('-- Sin gafete --', ''));
                gafetes.forEach(function (g) {
                    if (ocupados.indexOf(String(g.id)) !== -1 && String(g.id) !== actual) { return; }
                    sel.appendChild(new Option(g.titulo + ' — ' + g.detalle, g.id));
                });
                sel.value = actual;
                if (sel.value !== actual) { sel.value = ''; }
                sel.dataset.valor = '';
            });
        });
    }

    /* ---------- Acompañantes: una fila por persona ---------- */
    function ajustarAcompanantes(form) {
        var campo = form.querySelector('[data-num-acompanantes]');
        var filas = form.querySelector('[data-filas-acompanantes]');
        var plantilla = form.querySelector('[data-plantilla-acompanante]');
        if (!campo || !filas || !plantilla) { return; }
        var maximo = parseInt(campo.max || '15', 10);
        var cantidad = Math.max(0, Math.min(maximo, parseInt(campo.value, 10) || 0));
        if (String(cantidad) !== campo.value) { campo.value = cantidad; }
        var actuales = filas.querySelectorAll('[data-fila-acompanante]');
        for (var i = actuales.length; i < cantidad; i++) {
            var html = plantilla.innerHTML.replace(/__i__/g, String(i));
            var caja = document.createElement('div');
            caja.innerHTML = html.trim();
            var fila = caja.firstElementChild;
            fila.querySelector('[data-numero-acompanante]').textContent = String(i + 1);
            filas.appendChild(fila);
        }
        for (var j = actuales.length - 1; j >= cantidad; j--) { actuales[j].remove(); }
        llenarGafetesAcompanantes(form);
    }

    /* ---------- Sugerencias al escribir (gafete, colaborador, vehículo) ---------- */
    function cajaSugerencias(envoltura) { return envoltura.querySelector('[data-sugerencias]'); }

    function ocultarSugerencias(envoltura) {
        var caja = envoltura && cajaSugerencias(envoltura);
        if (caja) { caja.hidden = true; caja.textContent = ''; }
    }

    function pintarSugerencias(envoltura, items, vacio, alElegir) {
        var caja = cajaSugerencias(envoltura);
        if (!caja) { return; }
        caja.textContent = '';
        if (!items.length) {
            if (!vacio) { caja.hidden = true; return; }
            caja.appendChild(elemento('div', 'acceso-sugerencia nada', vacio));
        }
        items.forEach(function (it) {
            var b = elemento('button', 'acceso-sugerencia');
            b.type = 'button';
            b.appendChild(elemento('strong', '', it.titulo));
            if (it.detalle) { b.appendChild(elemento('small', '', it.detalle)); }
            b.addEventListener('click', function () { alElegir(it); ocultarSugerencias(envoltura); });
            caja.appendChild(b);
        });
        caja.hidden = false;
    }

    function elegirEnLector(envoltura, registro) {
        var caja = envoltura.querySelector('[data-lector]');
        if (caja && window.Lector) { window.Lector.elegir(caja, registro); }
    }

    function llenarVehiculo(envoltura, v) {
        var form = envoltura.closest('form');
        var placas = envoltura.querySelector('[data-placas]');
        if (placas) { placas.value = v.placas || v.titulo || ''; }
        if (!form) { return; }
        ['tipo', 'marca', 'modelo', 'color'].forEach(function (campo) {
            var c = form.querySelector('[data-vehiculo-campo="' + campo + '"]');
            if (c && v[campo]) { c.value = v[campo]; }
        });
    }

    function sugerir(envoltura, texto) {
        var que = envoltura.dataset.sugerir;
        var form = envoltura.closest('form');
        if (que === 'gafete') {
            cargarGafetes(sedeDe(form)).then(function (gafetes) {
                var tipoSel = form.querySelector('[data-filtro-tipo-gafete]');
                var tipo = tipoSel ? tipoSel.value : '';
                var t = (texto || '').toLowerCase();
                var libres = gafetes.filter(function (g) { return gafetesElegidos(form).indexOf(String(g.id)) === -1 || false; });
                var porTipo = tipo ? libres.filter(function (g) { return String(g.tipo_id) === tipo; }) : libres;
                // Si no hay de ese tipo en la sede, se muestran todos (como SEGCAT)
                var base = porTipo.length ? porTipo : libres;
                var items = base.filter(function (g) { return !t || g.titulo.toLowerCase().indexOf(t) !== -1; }).slice(0, 8);
                pintarSugerencias(envoltura, items, sedeDe(form) ? 'No hay gafetes libres con ese texto en esta sede.' : 'Elige primero la sede.', function (g) { elegirEnLector(envoltura, g); });
            });
            return;
        }
        if (!texto || texto.trim().length < 2) { ocultarSugerencias(envoltura); return; }
        esperar(que, function () {
            pedirJson(urlDe('urlBuscar') + '?que=' + que + '&q=' + encodeURIComponent(texto) + (sedeDe(form) ? '&sede=' + encodeURIComponent(sedeDe(form)) : ''))
                .then(function (d) {
                    var items = (d.resultados || []).slice(0, 8).map(function (r) {
                        if (que === 'colaborador') {
                            return { id: r.id, titulo: r.nombre_completo, detalle: (r.num_empleado ? 'Núm. ' + r.num_empleado : 'Alta provisional') + (r.puesto ? ' · ' + r.puesto : '') + (r.sede ? ' · ' + r.sede : ''), activo: true };
                        }
                        return { id: r.id, titulo: r.placas, detalle: [r.descripcion, r.propiedad_etiqueta].filter(Boolean).join(' · '), activo: !!r.activo,
                            placas: r.placas, tipo: r.tipo, marca: r.marca, modelo: r.modelo, color: r.color };
                    });
                    var vacio = que === 'colaborador' ? 'Sin coincidencias. Si no está registrado, usa «Alta provisional».' : 'No está en el padrón: se registrará como vehículo nuevo.';
                    pintarSugerencias(envoltura, items, vacio, function (it) {
                        elegirEnLector(envoltura, it);
                        if (que === 'vehiculo') { llenarVehiculo(envoltura, it); }
                    });
                })
                .catch(function () { ocultarSugerencias(envoltura); });
        });
    }

    document.addEventListener('input', function (e) {
        var entrada = e.target.closest && e.target.closest('[data-lector-entrada]');
        var envoltura = entrada && entrada.closest('[data-sugerir]');
        if (!envoltura) { return; }
        if (envoltura.dataset.sugerir === 'vehiculo') {
            // Las placas se escriben en el lector: si no está en el padrón, se registra con lo escrito
            var placas = envoltura.querySelector('[data-placas]');
            var id = envoltura.querySelector('[data-lector-id]');
            if (placas && !(id && id.value)) { placas.value = entrada.value.toUpperCase(); }
        }
        sugerir(envoltura, entrada.value);
    });

    // Al tocar el campo del gafete se ofrecen los libres de la sede (sin escribir nada)
    document.addEventListener('click', function (e) {
        var entrada = e.target.closest && e.target.closest('[data-lector-entrada]');
        var envoltura = entrada && entrada.closest('[data-sugerir="gafete"]');
        if (envoltura && !envoltura.querySelector('[data-lector-id]').value) { sugerir(envoltura, entrada.value); }
    });

    document.addEventListener('lector:elegido', function (e) {
        var envoltura = e.target.closest && e.target.closest('[data-sugerir]');
        if (envoltura) { ocultarSugerencias(envoltura); }
        // Dar Salida: el gafete que devuelven dice quién era
        if (e.target.closest && e.target.closest('[data-salida-gafete]')) { buscarEnSitio('gafete=' + encodeURIComponent(e.detail.id)); return; }
        if (!envoltura) { return; }
        var form = envoltura.closest('form');
        if (envoltura.dataset.sugerir === 'gafete') {
            // El gafete escaneado debe ser de la sede y estar libre
            var sedeActual = sedeDe(form);
            cargarGafetes(sedeActual, true).then(function (gafetes) {
                var libre = gafetes.some(function (g) { return String(g.id) === String(e.detail.id); });
                if (!libre) {
                    var caja = envoltura.querySelector('[data-lector]');
                    window.Lector.limpiar(caja);
                    var estado = caja.querySelector('[data-lector-estado]');
                    estado.hidden = false;
                    estado.className = 'lector-estado error';
                    estado.textContent = sedeActual ? 'El gafete ' + e.detail.titulo + ' no está disponible: ya está en uso (EN SITIO), está dado de baja o es de otra sede.' : 'Elige primero la sede y vuelve a escanear el gafete.';
                }
                llenarGafetesAcompanantes(form);
            });
        }
        if (envoltura.dataset.sugerir === 'vehiculo') {
            var placas = envoltura.querySelector('[data-placas]');
            if (placas) { placas.value = e.detail.titulo; }
            if (!form.closest('[data-dialogo-ingreso]')) { return; }
            pedirJson(urlDe('urlBuscar') + '?que=vehiculo&q=' + encodeURIComponent(e.detail.titulo)).then(function (d) {
                var v = (d.resultados || []).filter(function (r) { return r.placas === e.detail.titulo; })[0];
                if (v) { llenarVehiculo(envoltura, v); }
            }).catch(function () { /* sin datos extra */ });
        }
    });

    // "Cambiar" en el lector de placas: se olvidan las placas y los datos del vehículo
    document.addEventListener('click', function (e) {
        var limpiar = e.target.closest('[data-lector-limpiar]');
        var envoltura = limpiar && limpiar.closest('[data-sugerir="vehiculo"]');
        if (!envoltura) { return; }
        var placas = envoltura.querySelector('[data-placas]');
        if (placas) { placas.value = ''; }
        var form = envoltura.closest('form');
        form.querySelectorAll('[data-vehiculo-campo]').forEach(function (c) { if (c.tagName !== 'SELECT') { c.value = ''; } });
    });

    // Placas que no están en el padrón: no es un error, es un vehículo nuevo
    function vigilarPlacas(envoltura) {
        var estado = envoltura.querySelector('[data-lector-estado]');
        if (!estado || estado.dataset.vigilado || typeof MutationObserver === 'undefined') { return; }
        estado.dataset.vigilado = '1';
        new MutationObserver(function () {
            if (estado.classList.contains('error') && estado.textContent.indexOf('No se encontró') === 0) {
                estado.className = 'lector-estado info';
                estado.textContent = 'Vehículo nuevo: se registrará en el padrón con estas placas. Llena marca y color.';
            }
        }).observe(estado, { childList: true, characterData: true, subtree: true, attributes: true, attributeFilter: ['class'] });
    }

    /* ---------- Nombre (Padrón de personas) y empresa / agencia (Proveedores) ---------- */
    document.addEventListener('input', function (e) {
        var campo = e.target;
        var form = campo.form;
        if (!form || !form.matches('[data-form-acceso]')) { return; }

        if (campo.matches('[data-nombre-titular]')) {
            var personaId = form.querySelector('[data-persona-id]');
            if (personaId) { personaId.value = ''; }
            var repetida = form.querySelector('[data-caja-persona-repetida]');
            if (repetida) { repetida.remove(); }
            var tipo = radio(form, 'tipo');
            var envoltura = campo.closest('[data-sugerir-persona]');
            if (['visitante', 'proveedor', 'contratista'].indexOf(tipo) === -1 || campo.value.trim().length < 2) { ocultarSugerencias(envoltura); return; }
            esperar('persona', function () {
                pedirJson(urlDe('urlBuscar') + '?que=persona&tipo=' + tipo + '&q=' + encodeURIComponent(campo.value)).then(function (d) {
                    var items = (d.resultados || []).slice(0, 8).map(function (p) {
                        return { id: p.id, titulo: p.nombre_completo, detalle: [p.tipo_etiqueta, p.empresa, p.folio].filter(Boolean).join(' · '), persona: p };
                    });
                    pintarSugerencias(envoltura, items, 'Sin coincidencias en el Padrón de personas: se registrará como persona nueva.', function (it) { usarPersona(form, it.persona); });
                }).catch(function () { ocultarSugerencias(envoltura); });
            });
            return;
        }

        var cajaProveedor = campo.closest('[data-sugerir-proveedor]');
        if (cajaProveedor && campo.type === 'text') {
            var oculto = cajaProveedor.querySelector('[data-proveedor-id]');
            if (oculto) { oculto.value = ''; }
            if (campo.value.trim().length < 2) { ocultarSugerencias(cajaProveedor); return; }
            esperar('proveedor', function () {
                pedirJson(urlDe('urlBuscar') + '?que=proveedor&q=' + encodeURIComponent(campo.value) + (sedeDe(form) ? '&sede=' + sedeDe(form) : '')).then(function (d) {
                    var items = (d.resultados || []).slice(0, 8).map(function (p) { return { id: p.id, titulo: p.nombre, detalle: p.categoria_etiqueta }; });
                    pintarSugerencias(cajaProveedor, items, 'Sin coincidencias: se registrará como empresa nueva.', function (it) {
                        campo.value = it.titulo.toUpperCase();
                        if (oculto) { oculto.value = it.id; }
                    });
                }).catch(function () { ocultarSugerencias(cajaProveedor); });
            });
        }
    });

    function usarPersona(form, p) {
        var nombre = form.querySelector('[data-nombre-titular]');
        if (nombre) { nombre.value = (p.nombre_completo || '').toUpperCase(); }
        var id = form.querySelector('[data-persona-id]');
        if (id) { id.value = p.id; }
        var empresa = form.querySelector('[data-sugerir-proveedor="proveedor_id"] input[type="text"]');
        var empresaId = form.querySelector('[data-sugerir-proveedor="proveedor_id"] [data-proveedor-id]');
        if (empresa && p.empresa && ['proveedor', 'contratista'].indexOf(radio(form, 'tipo')) !== -1) {
            empresa.value = p.empresa.toUpperCase();
            if (empresaId) { empresaId.value = p.proveedor_id || ''; }
        }
    }

    // Registro rápido en el Padrón de personas (con identificación): se usa al volver
    document.addEventListener('persona:registrada', function (e) {
        var form = formIngreso();
        if (form && e.detail) { usarPersona(form, e.detail); }
    });

    // Alta provisional de colaborador: queda elegido en el lector que la pidió
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-provisional-para]');
        if (b) { destinoProvisional = b.dataset.provisionalPara; }
    });
    document.addEventListener('colaborador:registrado', function (e) {
        var entrada = destinoProvisional && document.getElementById(destinoProvisional);
        var caja = entrada && entrada.closest('[data-lector]');
        if (!caja || !e.detail || !window.Lector) { return; }
        window.Lector.elegir(caja, {
            id: e.detail.id, titulo: e.detail.nombre_completo, activo: true,
            detalle: e.detail.num_empleado ? 'Núm. ' + e.detail.num_empleado : 'Alta provisional (Recursos Humanos la valida)'
        });
        destinoProvisional = null;
    });

    /* ---------- Eventos del formulario de ingreso ---------- */
    document.addEventListener('change', function (e) {
        var form = e.target.form;
        if (!form || !form.matches('[data-form-acceso]')) { return; }
        if (e.target.matches('[data-acceso-sede]')) {
            try { localStorage.setItem(CLAVE_SEDE, e.target.value); } catch (x) { /* sin almacenamiento */ }
            var gafete = form.querySelector('[data-sugerir="gafete"] [data-lector]');
            if (gafete && window.Lector) { window.Lector.limpiar(gafete); }
            llenarGafetesAcompanantes(form);
        }
        if (e.target.matches('[data-num-acompanantes]')) { ajustarAcompanantes(form); }
        if (e.target.matches('[data-gafete-acompanante]')) { llenarGafetesAcompanantes(form); }
        if (e.target.matches('[data-filtro-tipo-gafete]')) {
            var env = form.querySelector('[data-sugerir="gafete"]');
            if (env && document.activeElement && env.contains(document.activeElement)) { sugerir(env, ''); }
        }
        sincronizar(form);
    });

    document.addEventListener('click', function (e) {
        var mas = e.target.closest('[data-acompanantes-mas], [data-acompanantes-menos]');
        if (!mas) { return; }
        var form = mas.closest('form');
        var campo = form.querySelector('[data-num-acompanantes]');
        campo.value = Math.max(0, (parseInt(campo.value, 10) || 0) + (mas.hasAttribute('data-acompanantes-mas') ? 1 : -1));
        ajustarAcompanantes(form);
    });

    // Un solo envío (doble clic en caseta = doble registro)
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.matches('[data-form-acceso]') || e.defaultPrevented) { return; }
        setTimeout(function () { form.querySelectorAll('button[type="submit"]').forEach(function (b) { b.disabled = true; }); }, 0);
    });

    function prepararIngreso(fresco) {
        var form = formIngreso();
        if (!form) { return; }
        var sede = form.querySelector('select[data-acceso-sede]');
        if (sede && !sede.value) {
            try {
                var guardada = localStorage.getItem(CLAVE_SEDE);
                if (guardada && sede.querySelector('option[value="' + guardada + '"]')) { sede.value = guardada; }
            } catch (x) { /* sin almacenamiento */ }
        }
        form.dataset.tipoSugerido = '';
        sincronizar(form);
        form.querySelectorAll('[data-sugerir="vehiculo"]').forEach(vigilarPlacas);
        if (fresco) { gafetesPorSede = {}; }
        ajustarAcompanantes(form);
        mostrarVista(false);
        // Tras un error, se muestra el aviso; si no, en PC el cursor queda listo para escanear (en celular no se abre el teclado solo)
        var aviso = form.querySelector('[data-caja-persona-repetida], .alert-danger');
        if (aviso) {
            setTimeout(function () { aviso.scrollIntoView({ block: 'center' }); }, 150);
        } else if (window.matchMedia && window.matchMedia('(pointer: fine)').matches) {
            setTimeout(function () {
                var primero = form.querySelector('[data-condicion]:not([hidden]) [data-lector-entrada]:not(:disabled)');
                if (primero) { primero.focus({ preventScroll: true }); }
            }, 150);
        }
    }

    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-abrir-dialogo="dialogoIngreso"]')) { prepararIngreso(true); }
    });

    document.addEventListener('close', function (e) {
        if (!(e.target instanceof HTMLDialogElement)) { return; }
        if (e.target.matches('[data-dialogo-ingreso]')) {
            var form = formIngreso();
            if (!form) { return; }
            form.querySelectorAll('[data-persona-id], [data-proveedor-id], [data-placas]').forEach(function (c) { c.value = ''; });
            var num = form.querySelector('[data-num-acompanantes]');
            if (num) { num.value = '0'; }
            var filas = form.querySelector('[data-filas-acompanantes]');
            if (filas) { filas.textContent = ''; }
            form.querySelectorAll('[data-sugerencias]').forEach(function (c) { c.hidden = true; c.textContent = ''; });
            form.querySelectorAll('button[type="submit"]').forEach(function (b) { b.disabled = false; });
            form.querySelectorAll('details.mas-detalles').forEach(function (d) { d.open = false; });
            var resultados = document.querySelector('[data-resultados-salida]');
            if (resultados) { resultados.textContent = ''; }
            form.dataset.tipoSugerido = '';
            sincronizar(form);
            return;
        }
        // Diálogos de las tarjetas: se olvidan las placas escritas
        e.target.querySelectorAll('[data-form-dialogo-acceso] [data-placas]').forEach(function (c) { c.value = ''; });
        e.target.querySelectorAll('[data-sugerencias]').forEach(function (c) { c.hidden = true; c.textContent = ''; });
    }, true);

    // Cerrar sugerencias con un clic fuera o con Escape
    document.addEventListener('click', function (e) {
        document.querySelectorAll('[data-sugerencias]:not([hidden])').forEach(function (caja) {
            var envoltura = caja.parentElement;
            if (envoltura && !envoltura.contains(e.target)) { caja.hidden = true; caja.textContent = ''; }
        });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') { return; }
        var abierta = document.querySelector('[data-sugerencias]:not([hidden])');
        if (abierta) { e.preventDefault(); abierta.hidden = true; abierta.textContent = ''; }
    });

    /* ---------- Dar Salida (dentro del mismo diálogo) ---------- */
    function mostrarVista(salida) {
        var d = dialogoIngreso();
        if (!d) { return; }
        var vSalida = d.querySelector('[data-vista-salida]');
        var vEntrada = d.querySelector('[data-vista-entrada]');
        if (!vSalida) { return; }
        vSalida.hidden = !salida;
        vEntrada.hidden = salida;
        d.querySelectorAll('[data-titulo-entrada], [data-texto-entrada]').forEach(function (n) { n.hidden = salida; });
        d.querySelectorAll('[data-titulo-salida], [data-texto-salida]').forEach(function (n) { n.hidden = !salida; });
        if (salida) {
            var buscar = d.querySelector('[data-buscar-en-sitio]');
            if (buscar) { buscar.value = ''; setTimeout(function () { buscar.focus(); }, 80); }
            d.querySelector('[data-resultados-salida]').textContent = '';
        }
    }

    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-alternar-salida-rapida]');
        if (!b) { return; }
        var d = dialogoIngreso();
        mostrarVista(d.querySelector('[data-vista-salida]').hidden);
    });

    document.addEventListener('input', function (e) {
        if (!e.target.matches('[data-buscar-en-sitio]')) { return; }
        var texto = e.target.value.trim();
        if (texto.length < 2) { document.querySelector('[data-resultados-salida]').textContent = ''; return; }
        esperar('en-sitio', function () { buscarEnSitio('q=' + encodeURIComponent(texto)); }, 300);
    });

    function formularioPatch(url, texto, clase, confirmar, metodo) {
        var f = elemento('form');
        f.method = 'POST';
        f.action = url;
        if (confirmar) { f.setAttribute('data-confirmar', confirmar); }
        var t = elemento('input'); t.type = 'hidden'; t.name = '_token'; t.value = token(); f.appendChild(t);
        if (metodo !== 'POST') { var m = elemento('input'); m.type = 'hidden'; m.name = '_method'; m.value = 'PATCH'; f.appendChild(m); }
        var b = elemento('button', clase, texto); b.type = 'submit'; f.appendChild(b);
        return f;
    }

    function botonDialogo(dialogo, url, id, nombre, titulo, texto, clase) {
        var b = elemento('button', clase, texto);
        b.type = 'button';
        b.setAttribute('data-accion', 'acceso-dialogo');
        b.dataset.dialogo = dialogo; b.dataset.url = url; b.dataset.id = id; b.dataset.nombre = nombre; b.dataset.titulo = titulo;
        return b;
    }

    function buscarEnSitio(consulta) {
        var cont = document.querySelector('[data-resultados-salida]');
        if (!cont) { return; }
        pedirJson(urlDe('urlEnSitio') + '?' + consulta).then(function (d) {
            cont.textContent = '';
            var datos = d.resultados || [];
            if (!datos.length) { cont.appendChild(elemento('p', 'text-muted small p-2 m-0', 'Sin resultados en sitio para esa búsqueda.')); return; }
            datos.forEach(function (r) {
                var card = elemento('div', 'resultado-salida' + (r.fuera_temporal ? ' fuera' : ''));
                var nombre = elemento('div', 'resultado-salida-nombre', r.nombre);
                if (r.habitacion) { nombre.appendChild(document.createTextNode(' ')); nombre.appendChild(elemento('span', 'badge-hab', 'Hab. ' + r.habitacion)); }
                if (r.fuera_temporal) { nombre.appendChild(document.createTextNode(' ')); nombre.appendChild(elemento('span', 'estado-acceso fuera mini', 'FUERA')); }
                card.appendChild(nombre);
                card.appendChild(elemento('div', 'resultado-salida-dato', r.tipo_etiqueta + (r.sede ? ' · ' + r.sede : '')));
                if (r.placas) { card.appendChild(elemento('div', 'resultado-salida-dato', 'Vehículo: ' + r.placas + (r.zona ? ' · ' + r.zona + (r.zona_descarga ? ' (descarga)' : '') : ''))); }
                var pedir = [];
                if (r.gafete) { pedir.push('gafete ' + r.gafete + ' (titular)'); }
                (r.acompanantes || []).forEach(function (a) { if (a.gafete) { pedir.push('gafete ' + a.gafete + ' (' + a.nombre + ')'); } });
                if (r.identificacion) { pedir.push('la identificación (' + r.identificacion + ')'); }
                if (pedir.length) { card.appendChild(elemento('div', 'resultado-salida-pedir', 'Pedir de vuelta: ' + pedir.join(', '))); }
                if (!r.fuera_temporal) {
                    (r.acompanantes || []).forEach(function (a) {
                        var fila = elemento('div', 'resultado-salida-acomp');
                        fila.appendChild(elemento('span', '', '• ' + a.nombre + (a.gafete ? ' · Gafete ' + a.gafete : '') + (a.fuera_temporal ? ' · FUERA' : '')));
                        if (!a.fuera_temporal) {
                            fila.appendChild(formularioPatch(a.url_salida, 'Salida', 'btn-acomp salida', '¿Confirmar salida de ' + a.nombre + (a.gafete ? ' y devolver su gafete ' + a.gafete : '') + '?'));
                        }
                        card.appendChild(fila);
                    });
                }
                var acciones = elemento('div', 'resultado-salida-acciones');
                if (r.fuera_temporal) {
                    acciones.appendChild(botonDialogo('dialogoRegresoAcceso', r.url_regreso, r.id, r.nombre, r.tipo === 'huesped' ? 'Regreso de Tour' : 'Regreso', 'Registrar Regreso', 'btn-accion-acceso regreso'));
                } else {
                    if (r.salida_temporal) {
                        acciones.appendChild(botonDialogo('dialogoSalidaTemporalAcceso', r.url_salida_temporal, r.id, r.nombre, r.texto_salida_temporal, r.texto_salida_temporal, 'btn-accion-acceso temporal'));
                    }
                    acciones.appendChild(formularioPatch(r.url_salida, r.texto_salida, 'btn-accion-acceso salida',
                        '¿Confirmar salida de ' + r.nombre + '?' + (pedir.length ? ' Pide de vuelta: ' + pedir.join(', ') + '.' : '')));
                }
                card.appendChild(acciones);
                cont.appendChild(card);
            });
        }).catch(function () {
            cont.textContent = '';
            cont.appendChild(elemento('p', 'text-danger small p-2 m-0', 'No se pudo buscar en este momento. Intenta de nuevo.'));
        });
    }

    /* ---------- Diálogos de las tarjetas: zona, salida a tour / temporal, regreso ---------- */
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-accion="acceso-dialogo"]');
        if (!b) { return; }
        var d = document.getElementById(b.dataset.dialogo);
        var form = d && d.querySelector('[data-form-dialogo-acceso]');
        if (!form) { return; }
        form.action = b.dataset.url;
        d.querySelectorAll('.alert').forEach(function (n) { n.remove(); });
        var poner = function (sel, valor) { form.querySelectorAll(sel).forEach(function (c) { c.value = valor || ''; }); };
        poner('[data-dialogo-acceso-id]', b.dataset.id);
        poner('[data-dialogo-nombre-campo]', b.dataset.nombre);
        poner('[data-dialogo-titulo-campo]', b.dataset.titulo);
        d.querySelectorAll('[data-dialogo-nombre]').forEach(function (n) { n.textContent = b.dataset.nombre || ''; });
        if (b.dataset.titulo) { d.querySelectorAll('[data-dialogo-titulo]').forEach(function (n) { n.textContent = b.dataset.titulo; }); }
        var zona = form.querySelector('[data-zona-sede]');
        if (zona) {
            acotarOpciones(zona, b.dataset.sede, true);
            zona.value = b.dataset.zona || '';
        }
        d.querySelectorAll('[data-sugerir="vehiculo"]').forEach(vigilarPlacas);
        if (typeof d.showModal === 'function' && !d.open) { d.showModal(); }
    });

    /* ---------- Filtro de tarjetas sin recargar (Gente en Sitio y Pendientes) ---------- */
    function filtrarTarjetas() {
        var barra = document.querySelector('[data-filtros-accesos="vivo"]');
        var cont = document.querySelector('[data-accesos]');
        if (!barra || !cont) { return; }
        var texto = (barra.querySelector('[data-filtro-accesos-texto]') || {}).value || '';
        var tipo = (barra.querySelector('[data-filtro-accesos-tipo]') || {}).value || '';
        var sedeSel = barra.querySelector('[data-filtro-accesos-sede]');
        var sede = sedeSel ? sedeSel.value : '';
        var palabras = lista(texto.toLowerCase());
        var compacto = texto.replace(/[\s\-.]+/g, '').toLowerCase();
        var fichas = cont.querySelectorAll('[data-acceso-ficha]');
        var visibles = 0;
        fichas.forEach(function (f) {
            var t = f.dataset.texto || '';
            var porTexto = palabras.every(function (p) { return t.indexOf(p) !== -1; }) || (compacto.length > 1 && t.indexOf(compacto) !== -1);
            var ok = porTexto && (!tipo || f.dataset.tipo === tipo) && (!sede || f.dataset.sede === sede);
            f.style.display = ok ? '' : 'none';
            if (ok) { visibles++; }
        });
        var vacio = cont.querySelector('[data-sin-resultados-accesos]');
        if (vacio) { vacio.hidden = visibles !== 0 || fichas.length === 0; }
    }

    document.addEventListener('input', function (e) { if (e.target.closest('[data-filtros-accesos="vivo"]')) { filtrarTarjetas(); } });
    document.addEventListener('change', function (e) { if (e.target.closest('[data-filtros-accesos="vivo"]')) { filtrarTarjetas(); } });

    document.addEventListener('DOMContentLoaded', function () {
        filtrarTarjetas();
        var d = dialogoIngreso();
        if (!d) { return; }
        // Tras un error, o con "Guardar y capturar siguiente", el diálogo ya se abrió solo
        if (d.open) {
            prepararIngreso(false);
        } else {
            var form = formIngreso();
            if (form) { sincronizar(form); }
        }
    });
})();
/* Fin Bitácora de accesos */
/* ==========================================================================
   Operación: Préstamo de llaves y Responsivas
   - Pestañas (Llaves en Uso / Historial de Entregas; Equipos en Campo /
     Historial Devueltos) con buscador y filtro de sede sobre la pestaña activa.
   - Historial de una llave o de un equipo: el reloj abre un diálogo con el
     fragmento que dibuja el servidor (#historial-llave-ID lo abre al llegar).
   - "Prestar Llave": se escanea la llave y luego el gafete; "Registrar y
     Capturar Siguiente" guarda por fetch, pinta la ficha nueva y deja el
     cuadro abierto y limpio para el siguiente préstamo.
   - "Nuevo Resguardo (Lote)": cada equipo escaneado se agrega solo a la
     lista; "+ Añadir Equipo" agrega una fila para elegirlo de la lista
     (solo equipos DISPONIBLES de la sede elegida y sin repetir).
   - "Firma": muestra la firma del lote (la sirve la plataforma con permiso).
   ========================================================================== */
(function () {
    'use strict';

    function leer(texto, porDefecto) { try { return JSON.parse(texto); } catch (x) { return porDefecto; } }

    /* ---------- Pestañas y filtro ---------- */
    function vistaActiva() {
        var b = document.querySelector('[data-pestana-prestamos][aria-selected="true"]');
        return b ? document.querySelector('[data-vista-prestamos="' + b.dataset.pestanaPrestamos + '"]') : null;
    }

    function filtrar() {
        var vista = vistaActiva();
        if (!vista) { return; }
        var t = document.querySelector('[data-filtro-prestamos="texto"]');
        var s = document.querySelector('[data-filtro-prestamos="sede"]');
        var texto = t ? t.value.toLowerCase().trim() : '';
        var sede = s ? s.value : '';
        var fichas = vista.querySelectorAll('[data-ficha-prestamo]');
        var visibles = 0;
        fichas.forEach(function (f) {
            var ok = (texto === '' || (f.dataset.texto || '').indexOf(texto) !== -1) && (sede === '' || f.dataset.sede === sede);
            f.hidden = !ok;
            if (ok) { visibles++; }
        });
        var vacio = document.querySelector('[data-sin-resultados-prestamos]');
        if (vacio) { vacio.hidden = visibles !== 0 || fichas.length === 0; }
        var exportar = document.querySelector('[data-exportar-prestamos]');
        if (exportar) { exportar.href = exportar.dataset.base + (sede ? '?sede=' + encodeURIComponent(sede) : ''); }
    }

    function elegirPestana(clave) {
        var hay = false;
        document.querySelectorAll('[data-pestana-prestamos]').forEach(function (b) {
            var on = b.dataset.pestanaPrestamos === clave;
            if (on) { hay = true; }
            b.setAttribute('aria-selected', String(on));
            b.classList.toggle('activa', on);
        });
        if (!hay) { return; }
        document.querySelectorAll('[data-vista-prestamos]').forEach(function (v) { v.hidden = v.dataset.vistaPrestamos !== clave; });
        filtrar();
    }

    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-pestana-prestamos]');
        if (b) { elegirPestana(b.dataset.pestanaPrestamos); }
    });
    document.addEventListener('input', function (e) { if (e.target.matches('[data-filtro-prestamos]')) { filtrar(); } });
    document.addEventListener('change', function (e) { if (e.target.matches('[data-filtro-prestamos]')) { filtrar(); } });

    /* ---------- Historial de una llave o de un equipo ---------- */
    function abrirHistorial(url, idDialogo) {
        var dialogo = document.getElementById(idDialogo || 'dialogoHistorialLlave');
        if (!dialogo) { return; }
        var caja = dialogo.querySelector('[data-contenido-historial]');
        caja.innerHTML = '';
        var cargando = document.createElement('div');
        cargando.className = 'p-5 text-center text-muted';
        cargando.textContent = 'Cargando historial...';
        caja.appendChild(cargando);
        if (!dialogo.open) { dialogo.showModal(); }
        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.text(); })
            // Fragmento dibujado (y escapado) por el servidor
            .then(function (html) { caja.innerHTML = html; })
            .catch(function () { cargando.textContent = 'No se pudo cargar el historial. Revisa tu conexión e intenta de nuevo.'; });
    }

    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-historial-llave]');
        if (b) { abrirHistorial(b.dataset.historialLlave, b.dataset.dialogoHistorial); }
    });

    /* ---------- Ver la firma de un resguardo ---------- */
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-ver-firma]');
        if (!b) { return; }
        var dialogo = document.getElementById('dialogoVerFirma');
        if (!dialogo) { return; }
        dialogo.querySelector('[data-firma-imagen]').src = b.dataset.verFirma;
        dialogo.querySelector('[data-firma-folio]').textContent = '· ' + (b.dataset.folio || '');
        dialogo.querySelector('[data-firma-nombre]').textContent = b.dataset.nombre || '';
        dialogo.showModal();
    });

    /* ---------- Lectores dentro de los formularios ---------- */
    function cajaLector(form, nombre) {
        var oculto = form.querySelector('[data-lector-id][name="' + nombre + '"]');
        return oculto ? oculto.closest('[data-lector]') : null;
    }

    function avisoLector(caja, texto, tipo) {
        var e = caja && caja.querySelector('[data-lector-estado]');
        if (!e) { return; }
        e.hidden = !texto;
        e.textContent = texto || '';
        e.className = 'lector-estado' + (tipo ? ' ' + tipo : '');
    }

    function enfocar(caja) {
        var entrada = caja && caja.querySelector('[data-lector-entrada]');
        if (entrada) { setTimeout(function () { entrada.focus(); }, 30); }
    }

    function mensajes(caja, lista, ok) {
        if (!caja) { return; }
        caja.textContent = '';
        lista.forEach(function (m) { var d = document.createElement('div'); d.textContent = m; caja.appendChild(d); });
        caja.hidden = lista.length === 0;
        if (!ok && lista.length) { caja.scrollIntoView({ block: 'nearest' }); }
    }

    /* ---------- Prestar Llave ---------- */
    // Al elegir la llave: avisa si es de otra sede o ya está fuera, y pasa al colaborador
    document.addEventListener('lector:elegido', function (e) {
        var form = e.target.closest('[data-form-prestamo]');
        if (!form) { return; }
        var registro = e.detail || {};
        var caja = e.target.closest('[data-lector]');
        if (caja === cajaLector(form, 'llave_id')) {
            var sede = form.querySelector('[data-sede-prestamo]');
            var fuera = leer(form.dataset.llavesFuera || '[]', []);
            if (fuera.map(String).indexOf(String(registro.id)) !== -1) {
                if (window.Lector) { window.Lector.limpiar(caja); }
                avisoLector(caja, (registro.titulo || 'Esa llave') + ': esa llave ya está fuera — alguien más la tiene en este momento. Recíbela primero en «Llaves en Uso».', 'error');
                return;
            }
            if (sede && sede.value && registro.sede_id && String(registro.sede_id) !== sede.value) {
                if (window.Lector) { window.Lector.limpiar(caja); }
                avisoLector(caja, (registro.titulo || 'Esta llave') + ' es de otra sede: cambia la Sede o escanea otra llave.', 'error');
                return;
            }
            if (sede && !sede.value && registro.sede_id && sede.querySelector('option[value="' + registro.sede_id + '"]')) {
                sede.value = String(registro.sede_id); // la sede sale de la llave
            }
            enfocar(cajaLector(form, 'colaborador_id'));
        } else if (caja === cajaLector(form, 'colaborador_id')) {
            var boton = form.querySelector('button[type="submit"]');
            if (boton) { setTimeout(function () { boton.focus(); }, 30); }
        }
    });

    function limpiarParaSiguiente(form) {
        ['llave_id', 'colaborador_id'].forEach(function (n) {
            var caja = cajaLector(form, n);
            if (caja && window.Lector) { window.Lector.limpiar(caja); }
        });
        var garantia = form.querySelector('[name="tipo_garantia"]');
        if (garantia) {
            var defecto = garantia.querySelector('[data-por-defecto]');
            garantia.value = defecto ? defecto.value : garantia.options[0].value;
        }
        var folio = form.querySelector('[name="folio_garantia"]');
        if (folio) { folio.value = ''; }
        enfocar(cajaLector(form, 'llave_id'));
    }

    function agregarFicha(html) {
        var vista = document.querySelector('[data-vista-prestamos="uso"]');
        if (!vista || !html) { return; }
        var plantilla = document.createElement('template');
        plantilla.innerHTML = html.trim(); // ficha dibujada (y escapada) por el servidor
        var ficha = plantilla.content.firstElementChild;
        if (!ficha) { return; }
        var vacio = vista.querySelector('[data-vacio-en-uso]');
        if (vacio) { vacio.hidden = true; vacio.after(ficha); } else { vista.prepend(ficha); }
        var conteo = document.querySelector('[data-conteo-en-uso]');
        if (conteo) { conteo.textContent = String(vista.querySelectorAll('[data-ficha-prestamo]').length); }
        filtrar();
    }

    var enSesion = 0;

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.matches('[data-form-prestamo]')) { return; }
        e.preventDefault();
        var dialogo = form.closest('dialog');
        var errores = dialogo.querySelector('[data-errores-prestamo]');
        var ok = dialogo.querySelector('[data-errores-prestamo-ok]');
        mensajes(ok, [], true);

        var faltan = [];
        if (!form.querySelector('[name="llave_id"]').value) { faltan.push('Escanea o busca la llave a prestar.'); }
        if (!form.querySelector('[name="colaborador_id"]').value) { faltan.push('Escanea el gafete o busca al colaborador que se lleva la llave.'); }
        if (faltan.length) { mensajes(errores, faltan, false); return; }
        mensajes(errores, [], true);

        var boton = form.querySelector('button[type="submit"]');
        var texto = boton ? boton.querySelector('span') : null;
        if (boton) { boton.disabled = true; }
        if (texto) { texto.textContent = 'Guardando...'; }
        function terminar() {
            if (boton) { boton.disabled = false; }
            if (texto) { texto.textContent = boton.dataset.textoOriginal || 'Registrar y Capturar Siguiente'; }
        }

        fetch(form.action, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData(form)
        })
            .then(function (r) { return r.json().then(function (d) { return { estado: r.status, datos: d }; }, function () { return { estado: r.status, datos: {} }; }); })
            .then(function (res) {
                terminar();
                if (res.estado === 201 && res.datos.ok) {
                    mensajes(ok, [res.datos.mensaje], true);
                    agregarFicha(res.datos.ficha);
                    var fuera = leer(form.dataset.llavesFuera || '[]', []);
                    fuera.push(res.datos.llave_id);
                    form.dataset.llavesFuera = JSON.stringify(fuera);
                    enSesion++;
                    var contador = dialogo.querySelector('[data-contador-prestamos]');
                    if (contador) { contador.hidden = false; contador.querySelector('[data-numero-prestamos]').textContent = String(enSesion); }
                    limpiarParaSiguiente(form);
                    return;
                }
                var lista = [];
                Object.keys(res.datos.errores || {}).forEach(function (k) { lista = lista.concat(res.datos.errores[k]); });
                if (!lista.length) {
                    lista.push(res.estado === 419 ? 'La sesión expiró. Recarga la página e intenta de nuevo.'
                        : (res.estado === 403 ? 'No tienes permiso para prestar llaves.' : (res.datos.mensaje || 'No se pudo completar la operación. Intenta de nuevo.')));
                }
                mensajes(errores, lista, false);
            })
            .catch(function () {
                terminar();
                mensajes(errores, ['No se pudo conectar con el servidor. Intenta de nuevo.'], false);
            });
    });

    /* ---------- Nuevo Resguardo (Lote) ---------- */
    function sedeResguardo(form) { var s = form.querySelector('[data-sede-resguardo]'); return s ? s.value : ''; }

    function filasEquipos(form) { return form.querySelectorAll('[data-fila-equipo]'); }

    function plantillaFila(form) { return form.closest('dialog').querySelector('[data-plantilla-fila-equipo]'); }

    function acomodarOpciones(form) {
        var sede = sedeResguardo(form);
        var elegidos = [];
        filasEquipos(form).forEach(function (f) { var v = f.querySelector('[data-equipo-lote]').value; if (v) { elegidos.push(v); } });
        filasEquipos(form).forEach(function (f) {
            var sel = f.querySelector('[data-equipo-lote]');
            Array.prototype.forEach.call(sel.options, function (op) {
                if (!op.value) { return; }
                var deLaSede = sede !== '' && op.dataset.sede === sede;
                var ocupado = elegidos.indexOf(op.value) !== -1 && op.value !== sel.value;
                op.hidden = !deLaSede || ocupado;
                op.disabled = !deLaSede || ocupado;
            });
            if (sel.value && sel.selectedOptions[0] && sel.selectedOptions[0].disabled) { sel.value = ''; }
        });
        var plantilla = plantillaFila(form);
        var hayDeLaSede = sede !== '' && !!plantilla && plantilla.content.querySelector('option[data-sede="' + sede + '"]') !== null;
        var nota = form.querySelector('[data-sin-equipos]');
        if (nota) { nota.hidden = sede === '' || hayDeLaSede; }
    }

    function agregarFila(form, valor) {
        var plantilla = plantillaFila(form);
        var cont = form.querySelector('[data-filas-equipos]');
        if (!plantilla || !cont) { return null; }
        var fila = plantilla.content.firstElementChild.cloneNode(true);
        cont.appendChild(fila);
        if (valor) { fila.querySelector('[data-equipo-lote]').value = String(valor); }
        acomodarOpciones(form);
        return fila;
    }

    document.addEventListener('click', function (e) {
        var anadir = e.target.closest('[data-anadir-equipo]');
        if (anadir) {
            var form = anadir.closest('[data-form-resguardo]');
            var fila = agregarFila(form);
            if (fila) { fila.querySelector('[data-equipo-lote]').focus(); }
            return;
        }
        var quitar = e.target.closest('[data-quitar-equipo]');
        if (quitar) {
            var f2 = quitar.closest('[data-form-resguardo]');
            quitar.closest('[data-fila-equipo]').remove();
            acomodarOpciones(f2);
            return;
        }
        var abrir = e.target.closest('[data-abrir-dialogo]');
        if (!abrir) { return; }
        var destino = document.getElementById(abrir.getAttribute('data-abrir-dialogo'));
        var fPrestamo = destino && destino.querySelector('[data-form-prestamo]');
        // Al abrir "Prestar Llave" el cursor queda listo en la llave (el lector USB escribe ahí)
        if (fPrestamo) { enfocar(cajaLector(fPrestamo, 'llave_id')); }
        var fResguardo = destino && destino.querySelector('[data-form-resguardo]');
        if (fResguardo) {
            if (!filasEquipos(fResguardo).length) { agregarFila(fResguardo); }
            acomodarOpciones(fResguardo);
            enfocar(cajaLector(fResguardo, 'colaborador_id'));
        }
    });

    document.addEventListener('change', function (e) {
        var form = e.target.closest && e.target.closest('[data-form-resguardo]');
        if (form && (e.target.matches('[data-equipo-lote]') || e.target.matches('[data-sede-resguardo]'))) { acomodarOpciones(form); }
    });

    // Colaborador elegido: pasa a escanear equipos. Equipo escaneado: se agrega a la lista (o llena la fila vacía)
    document.addEventListener('lector:elegido', function (e) {
        var form = e.target.closest('[data-form-resguardo]');
        if (!form) { return; }
        var caja = e.target.closest('[data-lector]');
        var registro = e.detail || {};
        if (caja === cajaLector(form, 'colaborador_id')) { enfocar(cajaLector(form, '_equipo_leido')); return; }
        if (caja !== cajaLector(form, '_equipo_leido')) { return; }

        var id = String(registro.id);
        var nombre = registro.titulo || 'Ese equipo';
        var plantilla = plantillaFila(form);
        var opcion = plantilla ? plantilla.content.querySelector('option[value="' + id + '"]') : null;
        if (window.Lector) { window.Lector.limpiar(caja); }
        enfocar(caja);
        if (!opcion) {
            avisoLector(caja, nombre + ' no está DISPONIBLE en tus sedes: no se puede resguardar.', 'error');
            return;
        }
        var selSede = form.querySelector('[data-sede-resguardo]');
        if (selSede && selSede.value === '') { selSede.value = opcion.dataset.sede; acomodarOpciones(form); }
        if (opcion.dataset.sede !== sedeResguardo(form)) {
            avisoLector(caja, nombre + ' es de otra sede: cambia la Sede de Origen o escanea otro equipo.', 'error');
            return;
        }
        var ya = false;
        var vacia = null;
        filasEquipos(form).forEach(function (f) {
            var v = f.querySelector('[data-equipo-lote]').value;
            if (v === id) { ya = true; }
            if (!v && !vacia) { vacia = f; }
        });
        if (ya) { avisoLector(caja, nombre + ' ya está en la lista.', 'aviso'); return; }
        if (vacia) { vacia.querySelector('[data-equipo-lote]').value = id; acomodarOpciones(form); } else { agregarFila(form, id); }
        avisoLector(caja, '✓ Agregado: ' + opcion.textContent.trim() + '. Escanea el siguiente o pide la firma.', 'ok');
    });

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.matches('[data-form-resguardo]')) { return; }
        var errores = form.closest('dialog').querySelector('[data-errores-resguardo]');
        var lista = [];
        if (!form.querySelector('[name="colaborador_id"]').value) { lista.push('Escanea el gafete o busca al colaborador responsable.'); }
        var filas = filasEquipos(form);
        if (!filas.length) { lista.push('Debe añadir al menos 1 equipo al lote.'); }
        if (Array.prototype.some.call(filas, function (f) { return !f.querySelector('[data-equipo-lote]').value; })) {
            lista.push('Seleccione un equipo en todas las filas agregadas (o quite la fila vacía).');
        }
        if (lista.length) { e.preventDefault(); mensajes(errores, lista, false); }
    });

    // Al cerrar: Nuevo Resguardo queda sin filas (al abrirlo aparece una vacía); en Prestar, el contador vuelve a cero
    document.addEventListener('close', function (e) {
        if (!(e.target instanceof HTMLDialogElement)) { return; }
        var cont = e.target.querySelector('[data-filas-equipos]');
        if (cont) { cont.textContent = ''; }
        if (e.target.querySelector('[data-form-prestamo]')) {
            enSesion = 0;
            var contador = e.target.querySelector('[data-contador-prestamos]');
            if (contador) { contador.hidden = true; }
        }
        if (e.target.id === 'dialogoVerFirma') { e.target.querySelector('[data-firma-imagen]').removeAttribute('src'); }
    }, true);

    document.addEventListener('DOMContentLoaded', function () {
        var fResguardo = document.querySelector('[data-form-resguardo]');
        if (fResguardo) { acomodarOpciones(fResguardo); }
        if (!document.querySelector('[data-pestana-prestamos]')) { return; }
        // Al llegar a una ficha (#prestamo-12, #responsiva-3) se abre su pestaña;
        // #historial-llave-5 (desde el Catálogo de llaves) abre el historial de esa llave
        var hash = location.hash || '';
        var ficha = /^#(prestamo|responsiva)-\d+$/.test(hash) ? document.querySelector(hash) : null;
        if (ficha) {
            var vista = ficha.closest('[data-vista-prestamos]');
            if (vista) { elegirPestana(vista.dataset.vistaPrestamos); }
            ficha.classList.add('ficha-resaltada');
            ficha.scrollIntoView({ block: 'center' });
        } else {
            filtrar();
        }
        var m = /^#historial-llave-(\d+)$/.exec(hash);
        var dialogo = document.getElementById('dialogoHistorialLlave');
        if (m && dialogo && dialogo.dataset.urlHistorial) {
            abrirHistorial(dialogo.dataset.urlHistorial.replace(/\/0\/historial$/, '/' + m[1] + '/historial'), 'dialogoHistorialLlave');
        }
    });
})();
/* Fin Operación: Préstamo de llaves y Responsivas */
/* ==========================================================================
   Bitácora de Novedades (seguridad/novedades): pestañas y filtros de la
   lista, combos que dependen de otro (sede → edificio → piso → habitación,
   a quién se canaliza, sugerencias de nombres), formato de cada categoría,
   campos que aparecen según otro, filas dinámicas (testigos, personas,
   artículos…), mapa corporal y firmas del Accidente, "Buscar Coincidencias"
   y "Vincular" (Lost & Found, Robo y Ficha de Hechos).
   ========================================================================== */
(function () {
    'use strict';

    function leer(texto) { try { return JSON.parse(texto || 'null'); } catch (x) { return null; } }
    function enFormulario(el) { return el.closest('form') || document; }

    /* ---------- Lista: pestañas y filtros ---------- */
    var CLAVE_FILTRO = 'plataforma_filtro_novedades';

    function filtrosActuales() {
        var valor = function (n) { var el = document.querySelector('[data-filtro-novedades="' + n + '"]'); return el ? el.value : ''; };
        var tab = document.querySelector('[data-pestana-novedades].active');
        return { texto: valor('texto').toLowerCase().trim(), categoria: valor('categoria'), sede: valor('sede'), pestana: tab ? tab.dataset.pestanaNovedades : 'abiertas' };
    }

    function filtrarNovedades() {
        var f = filtrosActuales();
        document.querySelectorAll('[data-vista-novedades]').forEach(function (vista) {
            var visibles = 0;
            var fichas = vista.querySelectorAll('[data-novedad]');
            fichas.forEach(function (ficha) {
                var ok = (f.texto === '' || (ficha.dataset.texto || '').indexOf(f.texto) !== -1)
                    && (f.categoria === '' || ficha.dataset.cat === f.categoria)
                    && (f.sede === '' || ficha.dataset.sede === f.sede);
                ficha.hidden = !ok;
                if (ok) { visibles++; }
            });
            var vacio = vista.querySelector('[data-sin-resultados-novedades]');
            if (vacio) { vacio.hidden = visibles !== 0 || fichas.length === 0; }
        });
        // Exportar lleva los mismos filtros que la pantalla
        var exportar = document.querySelector('[data-exportar-novedades]');
        if (exportar) {
            var p = new URLSearchParams();
            if (f.texto) { p.set('q', f.texto); }
            if (f.categoria) { p.set('categoria', f.categoria); }
            if (f.sede) { p.set('sede', f.sede); }
            p.set('pestana', f.pestana);
            exportar.href = exportar.dataset.base + '?' + p.toString();
        }
        try { sessionStorage.setItem(CLAVE_FILTRO, JSON.stringify(f)); } catch (x) { /* sin almacenamiento */ }
    }

    function elegirPestana(nombre) {
        document.querySelectorAll('[data-pestana-novedades]').forEach(function (b) {
            var on = b.dataset.pestanaNovedades === nombre;
            b.classList.toggle('active', on);
            b.setAttribute('aria-selected', String(on));
        });
        document.querySelectorAll('[data-vista-novedades]').forEach(function (v) { v.hidden = v.dataset.vistaNovedades !== nombre; });
        var nota = document.querySelector('[data-nota-resueltas]');
        if (nota) { nota.hidden = nombre !== 'resueltas'; }
        filtrarNovedades();
    }

    document.addEventListener('input', function (e) { if (e.target.matches('[data-filtro-novedades]')) { filtrarNovedades(); } });
    document.addEventListener('change', function (e) { if (e.target.matches('select[data-filtro-novedades]')) { filtrarNovedades(); } });
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-pestana-novedades]');
        if (b) { elegirPestana(b.dataset.pestanaNovedades); }
    });

    /* ---------- Combos que dependen de otro ---------- */
    var originales = new WeakMap();

    function opcionesOriginales(select) {
        if (!originales.has(select)) {
            originales.set(select, Array.prototype.map.call(select.options, function (o) { return o.cloneNode(true); }));
        }
        return originales.get(select);
    }

    function reconstruir(select, acepta) {
        var anterior = select.value;
        var lista = opcionesOriginales(select);
        select.textContent = '';
        lista.forEach(function (op, i) {
            if (i === 0 || acepta(op)) { select.appendChild(op.cloneNode(true)); }
        });
        select.value = anterior;
        if (select.value !== anterior) { select.selectedIndex = 0; }
    }

    function padreDe(select) {
        var form = enFormulario(select);
        var tipo = select.dataset.novDepende;
        return tipo === 'sede' ? form.querySelector('[data-nov-sede]') : form.querySelector('[data-nov-edificio]');
    }

    function areaActual(form) {
        var piso = form.querySelector('[name="area_piso_id"]');
        var edificio = form.querySelector('[name="area_edificio_id"]');
        return (piso && piso.value) || (edificio && edificio.value) || '';
    }

    function sincronizarCombos(form) {
        form.querySelectorAll('select[data-nov-depende]').forEach(function (select) {
            var padre = padreDe(select);
            if (!padre) { return; }
            var valor = padre.value;
            reconstruir(select, function (op) {
                var de = (op.getAttribute('data-de') || '').split(' ');
                return valor !== '' && (de.indexOf('todas') !== -1 || de.indexOf(valor) !== -1);
            });
        });
        var area = areaActual(form);
        form.querySelectorAll('select[data-nov-habitaciones]').forEach(function (select) {
            reconstruir(select, function (op) { return area !== '' && (op.getAttribute('data-ruta') || '').indexOf('/' + area + '/') !== -1; });
        });
    }

    /* ---------- Sugerencias de nombres (colaboradores de la sede) ---------- */
    var colaboradores = null;
    var sedeLista = null;

    function datosColaboradores() {
        if (colaboradores === null) {
            var nodo = document.getElementById('novColaboradoresDatos');
            colaboradores = (nodo && leer(nodo.textContent)) || [];
        }
        return colaboradores;
    }

    function sedeDe(el) {
        var form = enFormulario(el);
        var sel = form.querySelector && form.querySelector('[data-nov-sede]');
        if (sel) { return sel.value; }
        return (form.dataset && form.dataset.sede) || '';
    }

    function llenarSugerencias(sede) {
        var lista = document.getElementById('novColaboradores');
        if (!lista || sede === sedeLista) { return; }
        sedeLista = sede;
        lista.textContent = '';
        if (!sede) { return; }
        datosColaboradores().forEach(function (c) {
            var sedes = String(c.s).split(' ');
            if (sedes.indexOf('todas') === -1 && sedes.indexOf(sede) === -1) { return; }
            var op = document.createElement('option');
            op.value = c.n;
            lista.appendChild(op);
        });
    }

    function buscarColaborador(nombre) {
        var buscado = (nombre || '').trim().toUpperCase();
        if (!buscado) { return null; }
        return datosColaboradores().filter(function (c) { return c.n === buscado; })[0] || null;
    }

    document.addEventListener('focusin', function (e) {
        if (e.target.matches && e.target.matches('input[list="novColaboradores"]')) { llenarSugerencias(sedeDe(e.target)); }
    });

    /* ---------- Formato de la categoría y campos que aparecen según otro ---------- */
    function mostrarFormato(form) {
        var cat = form.querySelector('[data-nov-categoria]');
        if (!cat) { return; }
        form.querySelectorAll('fieldset[data-formato]').forEach(function (fs) {
            var activo = fs.dataset.formato === cat.value;
            fs.hidden = !activo;
            fs.disabled = !activo;
        });
    }

    // <div data-nov-mostrar-si='{"campo":["valor"]}'>: solo se oculta (lo capturado no se borra)
    function sincronizarVisibles(form) {
        form.querySelectorAll('[data-nov-mostrar-si]').forEach(function (caja) {
            var cond = leer(caja.getAttribute('data-nov-mostrar-si')) || {};
            caja.hidden = !Object.keys(cond).some(function (campo) {
                var el = form.querySelector('[name="' + campo + '"]');
                return !!el && cond[campo].indexOf(el.value) !== -1;
            });
        });
    }

    document.addEventListener('change', function (e) {
        var form = e.target.form;
        if (!form || !form.matches('[data-form-novedad]')) { return; }
        if (e.target.matches('[data-nov-categoria]')) { mostrarFormato(form); }
        if (e.target.matches('[data-nov-sede], [data-nov-edificio], [name="area_piso_id"]')) { sincronizarCombos(form); }
        if (e.target.matches('[data-nov-sede]')) { sedeLista = null; }
        if (e.target.matches('[data-nov-marca-hora]')) {
            var hora = e.target.closest('[data-nov-servicio]').querySelector('[data-nov-hora-servicio]');
            if (hora) { hora.hidden = !e.target.checked; }
        }
        if (e.target.matches('[data-categoria-pc]')) { criteriosPc(e.target); }
        sincronizarVisibles(form);
    });

    // Departamento y puesto del colaborador elegido de la lista (se pueden corregir)
    document.addEventListener('change', function (e) {
        if (!e.target.matches('[data-nov-nombre]')) { return; }
        var caja = e.target.closest('[data-nov-autocompletar]');
        var c = buscarColaborador(e.target.value);
        if (!caja || !c) { return; }
        var depto = caja.querySelector('[data-nov-depto]');
        var puesto = caja.querySelector('[data-nov-puesto]');
        if (depto) { depto.value = (c.d || '').toUpperCase(); }
        if (puesto) { puesto.value = (c.p || '').toUpperCase(); }
    });

    /* ---------- Quién reporta: escrito, "Fui yo" o gafete escaneado ---------- */
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-accion="novedad-fui-yo"]');
        if (!b) { return; }
        var campo = document.getElementById(b.dataset.objetivo);
        if (!campo) { return; }
        campo.value = b.dataset.nombre || '';
        var lector = enFormulario(campo).querySelector('[data-nov-lector-reporta] [data-lector]');
        if (lector && window.Lector) { window.Lector.limpiar(lector); }
        campo.focus();
    });

    document.addEventListener('lector:elegido', function (e) {
        var caja = e.target;
        var oculto = caja.querySelector('[data-lector-id]');
        var form = enFormulario(caja);
        if (!oculto) { return; }
        if (oculto.name === 'reportado_colaborador_id') {
            var reporta = form.querySelector('[data-nov-reporta]');
            if (reporta) { reporta.value = (e.detail.titulo || '').toUpperCase(); reporta.dataset.delLector = reporta.value; }
        }
        if (oculto.name === 'c_id_colaborador') {
            var datos = form.querySelector('[data-nov-colaborador-datos]');
            var c = datosColaboradores().filter(function (x) { return String(x.id) === String(e.detail.id); })[0];
            if (datos) {
                datos.querySelector('[data-nov-depto]').value = c ? (c.d || '').toUpperCase() : '';
                datos.querySelector('[data-nov-puesto]').value = c ? (c.p || '').toUpperCase() : ((e.detail.detalle || '').split(' · ')[1] || '').toUpperCase();
            }
        }
    });

    // Si se escribe otro nombre, deja de contar el gafete escaneado
    document.addEventListener('input', function (e) {
        if (!e.target.matches('[data-nov-reporta]') || !e.target.dataset.delLector) { return; }
        if (e.target.value.trim().toUpperCase() !== e.target.dataset.delLector) {
            var lector = enFormulario(e.target).querySelector('[data-nov-lector-reporta] [data-lector]');
            if (lector && window.Lector) { window.Lector.limpiar(lector); }
            delete e.target.dataset.delLector;
        }
    });

    /* ---------- Filas dinámicas ---------- */
    function renumerar(contenedor) {
        contenedor.querySelectorAll(':scope > [data-fila]').forEach(function (fila, i) {
            fila.querySelectorAll('[data-numero-fila]').forEach(function (n) { n.textContent = String(i + 1); });
        });
    }

    document.addEventListener('click', function (e) {
        var agregar = e.target.closest('[data-agregar-fila]');
        if (agregar) {
            var clave = agregar.dataset.agregarFila;
            var ambito = agregar.closest('.accordion-body') || enFormulario(agregar);
            var contenedor = ambito.querySelector('[data-filas="' + clave + '"]');
            var plantilla = ambito.querySelector('template[data-plantilla="' + (agregar.dataset.plantillaDe || clave) + '"]');
            if (!contenedor || !plantilla) { return; }
            var indice = parseInt(contenedor.dataset.siguiente || '0', 10);
            contenedor.dataset.siguiente = String(indice + 1);
            var numero = contenedor.querySelectorAll(':scope > [data-fila]').length + 1;
            var temporal = document.createElement('div');
            temporal.innerHTML = plantilla.innerHTML.replace(/__i__/g, String(indice)).replace(/__n__/g, String(numero));
            var fila = temporal.firstElementChild;
            contenedor.appendChild(fila);
            if (window.Lector) { fila.querySelectorAll('[data-lector]').forEach(window.Lector.preparar); }
            sincronizarCombos(enFormulario(contenedor));
            var primero = fila.querySelector('input:not([type="hidden"]):not([readonly]), select, textarea');
            if (primero) { primero.focus(); }
            return;
        }
        var quitar = e.target.closest('[data-quitar-fila]');
        if (quitar) {
            var filaQuitar = quitar.closest('[data-fila]');
            var padre = filaQuitar && filaQuitar.parentElement;
            if (filaQuitar) { filaQuitar.remove(); }
            if (padre) { renumerar(padre); }
        }
    });

    /* ---------- Recorrido PC: piezas a revisar según la categoría ---------- */
    function criteriosPc(select) {
        var fila = select.closest('[data-punto-pc]');
        var ambito = select.closest('[data-criterios-pc]');
        var defs = (ambito && leer(ambito.dataset.criteriosPc)) || {};
        var caja = fila && fila.querySelector('.criterios-pc');
        var indice = (select.name.match(/rpc_puntos\[(\d+)\]/) || [])[1];
        if (!caja || indice === undefined) { return; }
        caja.textContent = '';
        var piezas = defs[select.value];
        if (!piezas) {
            var aviso = document.createElement('div');
            aviso.className = 'col-12 text-muted small fst-italic';
            aviso.textContent = 'Seleccione una categoría arriba para cargar las piezas a evaluar.';
            caja.appendChild(aviso);
            return;
        }
        Object.keys(piezas).forEach(function (clave) {
            var col = document.createElement('div');
            col.className = 'col-md-4 col-sm-6 mb-2';
            var label = document.createElement('label');
            label.className = 'd-flex align-items-center gap-2 small';
            var chk = document.createElement('input');
            chk.type = 'checkbox'; chk.className = 'casilla-grande'; chk.value = '1'; chk.checked = true;
            chk.name = 'rpc_puntos[' + indice + '][criterios][' + clave + ']';
            label.appendChild(chk);
            label.appendChild(document.createTextNode(' ' + piezas[clave]));
            col.appendChild(label);
            caja.appendChild(col);
        });
    }

    /* ---------- Accidente: mapa corporal ---------- */
    document.addEventListener('click', function (e) {
        var parte = e.target.closest && e.target.closest('[data-nov-mapa-corporal] [data-zona]');
        if (!parte) { return; }
        var form = enFormulario(parte);
        var campos = parte.closest('fieldset.expediente-campos');
        if (campos && campos.disabled) { return; }
        var mapa = parte.closest('[data-nov-mapa-corporal]');
        var zona = parte.getAttribute('data-zona');
        var marcar = !parte.classList.contains('selected');
        mapa.querySelectorAll('[data-zona]').forEach(function (p) { if (p.getAttribute('data-zona') === zona) { p.classList.toggle('selected', marcar); } });
        var vistas = [];
        mapa.querySelectorAll('[data-zona].selected').forEach(function (p) {
            var z = p.getAttribute('data-zona');
            if (vistas.indexOf(z) === -1) { vistas.push(z); }
        });
        var campo = form.querySelector('[data-nov-zonas]');
        if (campo) { campo.value = vistas.join(', '); }
    });

    /* ---------- Accidente: "Guardar Esta Firma" (un recuadro, seis firmantes, como SEGCAT) ---------- */
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-accion="novedad-guardar-firma"]');
        if (!b) { return; }
        var caja = b.closest('[data-nov-firmas]');
        var recuadro = caja.querySelector('[data-firma]');
        var valor = recuadro.querySelector('[data-firma-valor]');
        var selector = caja.querySelector('[data-nov-selector-firma]');
        var estado = caja.querySelector('[data-nov-estado-firmas]');
        if (!valor.value) { recuadro.classList.add('falta'); return; }
        var destino = caja.querySelector('#val_firma_' + selector.value);
        if (!destino) { return; }
        destino.value = valor.value;
        var previa = estado.querySelector('[data-firma-rol="' + selector.value + '"]');
        if (previa) { previa.remove(); }
        var badge = document.createElement('span');
        badge.className = 'badge bg-success me-1 mb-1';
        badge.setAttribute('data-firma-rol', selector.value);
        var icono = document.createElement('i');
        icono.className = 'bi bi-check-circle-fill me-1';
        icono.setAttribute('aria-hidden', 'true');
        badge.appendChild(icono);
        badge.appendChild(document.createTextNode(' ' + selector.options[selector.selectedIndex].text + ' GUARDADA (se guarda con el expediente)'));
        estado.appendChild(badge);
        if (window.Firma) { window.Firma.preparar(recuadro); }
        if (recuadro.limpiarFirma) { recuadro.limpiarFirma(); }
        recuadro.classList.remove('falta');
    });

    /* ---------- Buscar Coincidencias y Vincular ---------- */
    function token() { var m = document.querySelector('meta[name="csrf-token"]'); return m ? m.content : ''; }

    function mostrarResultados(caja, lista, urlVincular, texto) {
        caja.textContent = '';
        if (!lista.length) {
            var vacio = document.createElement('div');
            vacio.className = 'small text-muted p-2';
            vacio.textContent = texto || 'Sin coincidencias por ahora — puedes volver a intentar más tarde, según se sigan registrando artículos.';
            caja.appendChild(vacio);
            return;
        }
        lista.forEach(function (a) {
            var fila = document.createElement('div');
            fila.className = 'resultado-coincidencia';
            var dato = document.createElement('span');
            var folio = document.createElement('strong');
            folio.textContent = a.folio;
            dato.appendChild(folio);
            dato.appendChild(document.createTextNode(' — ' + a.objeto + ' (' + [a.marca, a.color].filter(Boolean).join(' ') + ') · encontrado ' + a.fecha));
            var acciones = document.createElement('span');
            acciones.className = 'd-flex gap-1';
            var ver = document.createElement('a');
            ver.href = a.url; ver.target = '_blank'; ver.rel = 'noopener'; ver.className = 'btn-mini'; ver.textContent = 'Ver';
            acciones.appendChild(ver);
            if (urlVincular) {
                var vincular = document.createElement('button');
                vincular.type = 'button'; vincular.className = 'btn-mini verde'; vincular.textContent = 'Vincular';
                vincular.setAttribute('data-accion', 'novedad-vincular');
                vincular.setAttribute('data-url', urlVincular);
                vincular.setAttribute('data-articulo', a.id);
                acciones.appendChild(vincular);
            }
            fila.appendChild(dato);
            fila.appendChild(acciones);
            caja.appendChild(fila);
        });
    }

    function buscarCoincidencias(boton, parametros, urlVincular, textoVacio) {
        var ambito = boton.closest('[data-coincidencias]');
        var caja = (boton.closest('[data-fila]') || ambito).querySelector('[data-resultados-coincidencias]');
        if (!ambito || !caja) { return; }
        caja.textContent = 'Buscando…';
        fetch(ambito.dataset.coincidencias + '?' + new URLSearchParams(parametros).toString(), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.json(); })
            .then(function (d) { mostrarResultados(caja, d.resultados || [], urlVincular, textoVacio); })
            .catch(function () { caja.textContent = 'No se pudo buscar en este momento.'; });
    }

    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-accion="novedad-coincidencias"]');
        if (b) {
            var fila = b.closest('[data-fila]');
            var v = function (n) { var el = fila.querySelector('[data-rp="' + n + '"]'); return el ? el.value : ''; };
            buscarCoincidencias(b, { tipo_valor: v('tipo_valor'), objeto: v('objeto'), marca: v('marca'), color: v('color'), fecha: v('fecha') }, b.dataset.vincular);
            return;
        }
        var r = e.target.closest('[data-accion="novedad-coincidencias-robo"]');
        if (r) {
            // Solo la primera frase de "¿Qué se llevaron?" como palabra clave
            var form = enFormulario(r);
            var objetos = form.querySelector('[data-robo-objetos]');
            var clave = ((objetos && objetos.value) || '').split(/[,.\n]/)[0].trim().slice(0, 40);
            var cuando = form.querySelector('[name="ocurrio_en"]');
            buscarCoincidencias(r, { objeto: clave, fecha: cuando && cuando.value ? cuando.value.slice(0, 10) : '' }, r.dataset.vincular,
                clave ? 'Sin coincidencias para «' + clave + '» por ahora.' : 'Escribe primero «¿Qué se llevaron?».');
        }
    });

    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-accion="novedad-vincular"]');
        if (!b) { return; }
        b.disabled = true;
        b.textContent = 'Vinculando…';
        var datos = new FormData();
        datos.append('articulo_id', b.dataset.articulo);
        fetch(b.dataset.url, { method: 'POST', credentials: 'same-origin', body: datos,
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': token() } })
            .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, datos: d }; }); })
            .then(function (res) {
                if (res.ok && res.datos.ok) {
                    var listo = document.createElement('span');
                    listo.className = 'small text-success fw-bold';
                    listo.textContent = '✔ ' + (res.datos.mensaje || 'Vinculado');
                    b.replaceWith(listo);
                    var fila = listo.closest('[data-fila]');
                    var estado = fila && fila.querySelector('[data-rp-estatus]');
                    if (estado) { estado.textContent = 'Vinculado con un hallazgo'; }
                    return;
                }
                var errores = res.datos.errors ? Object.values(res.datos.errors)[0] : null;
                b.disabled = false;
                b.textContent = 'Vincular';
                window.alert('No se pudo vincular: ' + ((errores && errores[0]) || res.datos.message || 'intenta de nuevo.'));
            })
            .catch(function () { b.disabled = false; b.textContent = 'Vincular'; window.alert('No se pudo vincular en este momento.'); });
    });

    /* ---------- Diálogos ---------- */
    // "¿Cuándo sucedió?" propone la hora actual al abrir el alta (si no se ha cambiado)
    document.addEventListener('click', function (e) {
        if (!e.target.closest('[data-abrir-dialogo="dialogoAltaTicket"]')) { return; }
        var campo = document.getElementById('alta_cuando');
        if (campo && campo.value === campo.defaultValue) {
            var d = new Date();
            d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
            campo.value = d.toISOString().slice(0, 16);
        }
        var form = document.querySelector('[data-form-novedad="alta"]');
        if (form) { sincronizarCombos(form); }
    });

    // Al cerrar el expediente, la dirección ya no lo vuelve a abrir
    document.addEventListener('close', function (e) {
        if (!(e.target instanceof HTMLDialogElement) || !e.target.hasAttribute('data-expediente')) { return; }
        try {
            var url = new URL(window.location.href);
            url.searchParams.delete('abrir');
            window.history.replaceState(null, '', url.pathname + url.search + url.hash);
        } catch (x) { /* navegador antiguo */ }
    }, true);
    // Al cerrar el alta, sus combos vuelven a su estado inicial
    document.addEventListener('close', function (e) {
        if (!(e.target instanceof HTMLDialogElement)) { return; }
        var form = e.target.querySelector('[data-form-novedad="alta"]');
        if (form) { setTimeout(function () { sincronizarCombos(form); }, 0); }
    }, true);

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('form[data-form-novedad]').forEach(function (form) {
            sincronizarCombos(form);
            mostrarFormato(form);
            sincronizarVisibles(form);
        });
        if (!document.querySelector('[data-vista-novedades]')) { return; }
        var g = null;
        try { g = JSON.parse(sessionStorage.getItem(CLAVE_FILTRO) || 'null'); } catch (x) { g = null; }
        if (g) {
            var poner = function (n, v) { var el = document.querySelector('[data-filtro-novedades="' + n + '"]'); if (el && v) { el.value = v; } };
            poner('texto', g.texto); poner('categoria', g.categoria); poner('sede', g.sede);
        }
        // Un ticket enlazado (#novedad-12) se muestra aunque esté en la otra pestaña
        var destino = location.hash && /^#novedad-\d+$/.test(location.hash) ? document.querySelector(location.hash) : null;
        var vista = destino ? destino.closest('[data-vista-novedades]') : null;
        elegirPestana(vista ? vista.dataset.vistaNovedades : ((g && g.pestana) || 'abiertas'));
        if (destino) {
            if (destino.hidden) { ['texto', 'categoria', 'sede'].forEach(function (n) { var el = document.querySelector('[data-filtro-novedades="' + n + '"]'); if (el) { el.value = ''; } }); filtrarNovedades(); }
            destino.scrollIntoView({ block: 'center' });
        }
    });
})();
/* Fin Bitácora de Novedades */
/* ==========================================================================
   Pases de salida: detalle "Firmas del pase" en diálogo, firmar por rol,
   rechazo, "Nuevo Pase de Salida" (renglones de artículos, equipo escaneado,
   solicitante con lector o por nombre, atajos Nuevo Colaborador / Nuevo
   Proveedor, dirección y teléfono del destino).
   ========================================================================== */
(function () {
    'use strict';

    var ENCABEZADOS = { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' };

    /* ---------- Detalle del pase (se pide al abrir; sin JavaScript abre ?pase=ID) ---------- */
    function dialogoDetalle() { return document.querySelector('[data-dialogo-detalle-pase]'); }

    function aviso(dialogo, texto, clase) {
        dialogo.textContent = '';
        var cuerpo = document.createElement('div');
        cuerpo.className = 'dialogo-cuerpo text-center p-5 ' + (clase || '');
        cuerpo.textContent = texto;
        dialogo.appendChild(cuerpo);
    }

    document.addEventListener('click', function (e) {
        var enlace = e.target.closest('[data-detalle-pase]');
        if (!enlace) { return; }
        var dialogo = dialogoDetalle();
        if (!dialogo || typeof dialogo.showModal !== 'function') { return; }
        e.preventDefault();
        aviso(dialogo, 'Cargando…', 'text-muted');
        if (!dialogo.open) { dialogo.showModal(); }
        fetch(enlace.getAttribute('data-detalle-pase'), { credentials: 'same-origin', headers: ENCABEZADOS })
            .then(function (r) { if (!r.ok) { throw new Error(String(r.status)); } return r.text(); })
            .then(function (html) { dialogo.innerHTML = html; })
            .catch(function () { aviso(dialogo, 'No se pudo cargar el pase. Revisa tu conexión e intenta de nuevo.', 'text-danger'); });
    });

    // Al cerrar el detalle abierto por ?pase=ID, la dirección se limpia para que al recargar no vuelva a abrirse
    document.addEventListener('close', function (e) {
        if (!e.target.matches || !e.target.matches('[data-dialogo-detalle-pase]')) { return; }
        try {
            var url = new URL(window.location.href);
            if (url.searchParams.has('pase')) { url.searchParams.delete('pase'); window.history.replaceState(null, '', url.toString()); }
        } catch (x) { /* navegador antiguo */ }
    }, true);

    /* ---------- Firmar un rol y rechazar ---------- */
    document.addEventListener('click', function (e) {
        var boton = e.target.closest('[data-firmar-rol]');
        if (boton) {
            var dialogo = boton.closest('dialog') || document;
            var caja = dialogo.querySelector('[data-caja-firma-pase]');
            if (!caja) { return; }
            caja.querySelector('[data-firma-rol]').value = boton.getAttribute('data-firmar-rol');
            caja.querySelector('[data-firma-titulo]').textContent = 'Firma — ' + boton.getAttribute('data-rol-texto');
            caja.querySelector('[data-firma-nombre]').value = boton.getAttribute('data-sugerido') || '';
            var firma = caja.querySelector('[data-firma]');
            if (firma && window.Firma) { window.Firma.preparar(firma); if (firma.limpiarFirma) { firma.limpiarFirma(); } firma.classList.remove('falta'); }
            dialogo.querySelectorAll('.fila-firma.eligiendo').forEach(function (f) { f.classList.remove('eligiendo'); });
            boton.closest('.fila-firma').classList.add('eligiendo');
            caja.hidden = false;
            caja.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }
        var cancelar = e.target.closest('[data-cancelar-firma]');
        if (cancelar) {
            var cajaF = cancelar.closest('[data-caja-firma-pase]');
            var f = cajaF.querySelector('[data-firma]');
            if (f && f.limpiarFirma) { f.limpiarFirma(); }
            cajaF.hidden = true;
            var d = cajaF.closest('dialog');
            if (d) { d.querySelectorAll('.fila-firma.eligiendo').forEach(function (x) { x.classList.remove('eligiendo'); }); }
            return;
        }
        var rechazo = e.target.closest('[data-mostrar-rechazo]');
        if (rechazo) {
            var zona = rechazo.closest('.zona-rechazo');
            var form = zona.querySelector('[data-caja-rechazo]');
            form.hidden = false;
            rechazo.hidden = true;
            form.querySelector('textarea').focus();
        }
    });

    /* ---------- Nuevo pase: campos dependientes y destino ---------- */
    function formPase() { return document.querySelector('[data-form-pase]'); }

    // La sede destino no puede ser la de origen
    function ocultarOrigen(form) {
        var origen = form.querySelector('[data-pase-origen]');
        var destino = form.querySelector('[data-pase-sede-destino]');
        if (!origen || !destino) { return; }
        Array.prototype.forEach.call(destino.options, function (o) {
            if (o.value === '') { return; }
            var igual = o.value === origen.value;
            o.hidden = igual;
            o.disabled = igual;
        });
        if (destino.value !== '' && destino.value === origen.value) { destino.value = ''; }
    }

    function sincronizar(form) {
        // El acomodo genérico de data-mostrar-si (Padrón Vehicular) responde a un "change"
        var motivo = form.querySelector('[data-pase-motivo]');
        if (motivo) { motivo.dispatchEvent(new Event('change', { bubbles: true })); }
        ocultarOrigen(form);
    }

    function llenarContacto(form, opcion) {
        if (!opcion || opcion.value === '') { return; }
        var dir = form.querySelector('[data-pase-direccion]');
        var tel = form.querySelector('[data-pase-telefono]');
        if (dir) { dir.value = opcion.getAttribute('data-direccion') || ''; }
        if (tel) { tel.value = opcion.getAttribute('data-telefono') || ''; }
    }

    document.addEventListener('change', function (e) {
        var form = e.target.closest && e.target.closest('[data-form-pase]');
        if (!form) { return; }
        if (e.target.matches('[data-pase-origen]')) { ocultarOrigen(form); }
        if (e.target.matches('[data-pase-sede-destino], [data-pase-proveedor]')) { llenarContacto(form, e.target.selectedOptions[0]); }
        if (e.target.matches('[name="destino_tipo"]')) {
            var dir = form.querySelector('[data-pase-direccion]');
            var tel = form.querySelector('[data-pase-telefono]');
            if (dir) { dir.value = ''; }
            if (tel) { tel.value = ''; }
        }
    });

    /* ---------- Renglones de artículos ---------- */
    function agregarArticulo(form) {
        var cont = form.querySelector('[data-articulos-pase]');
        var plantilla = form.querySelector('[data-plantilla-articulo]');
        if (!cont || !plantilla) { return null; }
        var i = parseInt(cont.getAttribute('data-siguiente') || '0', 10);
        cont.setAttribute('data-siguiente', String(i + 1));
        var envoltura = document.createElement('div');
        envoltura.innerHTML = plantilla.innerHTML.replace(/__i__/g, String(i));
        var fila = envoltura.querySelector('[data-articulo-fila]');
        cont.appendChild(fila);
        return fila;
    }

    function reiniciarArticulos(form) {
        var cont = form.querySelector('[data-articulos-pase]');
        if (!cont) { return; }
        cont.textContent = '';
        cont.setAttribute('data-siguiente', '0');
        agregarArticulo(form);
    }

    document.addEventListener('click', function (e) {
        var agregar = e.target.closest('[data-agregar-articulo]');
        if (agregar) {
            var fila = agregarArticulo(agregar.closest('form'));
            if (fila) { fila.querySelector('[data-articulo="equipo"]').focus(); }
            return;
        }
        var quitar = e.target.closest('[data-quitar-articulo]');
        if (quitar) {
            var form = quitar.closest('form');
            quitar.closest('[data-articulo-fila]').remove();
            if (!form.querySelector('[data-articulo-fila]')) { agregarArticulo(form); }
        }
    });

    /* ---------- Equipo escaneado del padrón: llena un renglón ---------- */
    function estadoLector(caja, texto, clase) {
        var estado = caja && caja.querySelector('[data-lector-estado]');
        if (!estado) { return; }
        estado.hidden = !texto;
        estado.className = 'lector-estado ' + (clase || '');
        estado.textContent = texto || '';
    }

    document.addEventListener('lector:elegido', function (e) {
        var caja = e.target;
        if (!caja.closest || !caja.closest('[data-pase-lector-equipo]')) { return; }
        var form = caja.closest('form');
        var registro = e.detail || {};
        var url = form.getAttribute('data-url-equipo').replace(/\/0$/, '/' + encodeURIComponent(registro.id));
        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { if (!r.ok) { throw new Error(String(r.status)); } return r.json(); })
            .then(function (datos) {
                var fila = null;
                form.querySelectorAll('[data-articulo-fila]').forEach(function (f) {
                    if (!fila && f.querySelector('[data-articulo="equipo"]').value.trim() === '') { fila = f; }
                });
                fila = fila || agregarArticulo(form);
                ['equipo_id', 'equipo', 'marca', 'modelo', 'serie', 'descripcion'].forEach(function (c) {
                    var campo = fila.querySelector('[data-articulo="' + c + '"]');
                    if (campo) { campo.value = datos[c] === null || datos[c] === undefined ? '' : String(datos[c]); }
                });
                fila.querySelector('[data-articulo="cantidad"]').value = '1';
                if (window.Lector) { window.Lector.limpiar(caja); }
                estadoLector(caja, 'Agregado: ' + datos.equipo + ' · Serie ' + datos.serie + '. Puedes escanear el siguiente.', 'ok');
            })
            .catch(function () { estadoLector(caja, 'No se pudieron traer los datos del equipo. Captúralo a mano.', 'error'); });
    });

    /* ---------- Solicitante / colaborador destino: búsqueda por nombre y Departamento / Puesto ---------- */
    var espera = null;

    function cajaDe(para) {
        var envoltura = document.querySelector('[data-pase-colaborador][data-para="' + para + '"]');
        return envoltura ? envoltura.querySelector('[data-lector]') : null;
    }

    function mostrarDepto(registro) {
        var form = formPase();
        if (!form) { return; }
        var d = form.querySelector('[data-solicitante-depto]');
        var p = form.querySelector('[data-solicitante-puesto]');
        if (d) { d.value = registro ? (registro.departamento || '—') : ''; }
        if (p) { p.value = registro ? (registro.puesto || '—') : ''; }
    }

    function buscarColaboradores(form, texto) {
        var url = form.getAttribute('data-url-buscar-colaborador');
        if (!url || !texto) { return Promise.resolve([]); }
        return fetch(url + '?q=' + encodeURIComponent(texto), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { if (!r.ok) { throw new Error(String(r.status)); } return r.json(); })
            .then(function (d) { return d.resultados || []; });
    }

    function elegirColaborador(caja, c) {
        if (!window.Lector || !caja || !c || !c.id) { return; }
        window.Lector.elegir(caja, {
            id: c.id, titulo: c.nombre_completo, activo: true,
            detalle: (c.num_empleado ? 'Núm. ' + c.num_empleado : 'provisional') + (c.puesto ? ' · ' + c.puesto : ''),
            departamento: c.departamento || null, puesto: c.puesto || null
        });
    }

    document.addEventListener('input', function (e) {
        var entrada = e.target.closest && e.target.closest('[data-pase-colaborador] [data-lector-entrada]');
        if (!entrada) { return; }
        var caja = entrada.closest('[data-lector]');
        var form = entrada.closest('form');
        var opciones = caja.querySelector('[data-lector-opciones]');
        clearTimeout(espera);
        var texto = entrada.value.trim();
        // Solo nombres (con letras): los números y códigos los resuelve el lector al dar Enter
        if (texto.length < 3 || !/[a-záéíóúñü]/i.test(texto) || !form.getAttribute('data-url-buscar-colaborador')) { return; }
        espera = setTimeout(function () {
            buscarColaboradores(form, texto).then(function (lista) {
                if (entrada.value.trim() !== texto) { return; }
                opciones.textContent = '';
                if (!lista.length) {
                    estadoLector(caja, 'Nadie coincide con «' + texto + '». Si no está registrado, usa «Nuevo Colaborador».', 'aviso');
                    opciones.hidden = true;
                    return;
                }
                estadoLector(caja, 'Elige a la persona:', 'info');
                lista.forEach(function (c) {
                    var b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'lector-opcion';
                    var t = document.createElement('strong'); t.textContent = c.nombre_completo;
                    var d = document.createElement('span');
                    d.textContent = (c.num_empleado ? 'Núm. ' + c.num_empleado : 'Provisional') + (c.puesto ? ' · ' + c.puesto : '') + (c.departamento ? ' · ' + c.departamento : '') + (c.sede ? ' · ' + c.sede : '');
                    b.appendChild(t); b.appendChild(d);
                    b.addEventListener('click', function () { elegirColaborador(caja, c); });
                    opciones.appendChild(b);
                });
                opciones.hidden = false;
            }).catch(function () { /* sin red: queda el lector normal */ });
        }, 300);
    });

    // Al elegir al solicitante (escaneado o por nombre) se muestran su departamento y puesto
    document.addEventListener('lector:elegido', function (e) {
        var envoltura = e.target.closest && e.target.closest('[data-pase-colaborador][data-para="solicitante"]');
        if (!envoltura) { return; }
        var r = e.detail || {};
        if (r.departamento !== undefined || r.puesto !== undefined) { mostrarDepto(r); return; }
        mostrarDepto({ departamento: '…', puesto: '…' });
        var num = /Núm\. ([^\s·]+)/.exec(r.detalle || '');
        buscarColaboradores(envoltura.closest('form'), num ? num[1] : (r.titulo || '')).then(function (lista) {
            mostrarDepto(lista.filter(function (x) { return String(x.id) === String(r.id); })[0] || { departamento: '—', puesto: '—' });
        }).catch(function () { mostrarDepto({ departamento: '—', puesto: '—' }); });
    });

    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-pase-colaborador][data-para="solicitante"] [data-lector-limpiar]')) { mostrarDepto(null); }
    });

    /* ---------- Atajos: Nuevo Colaborador (alta provisional) y Nuevo Proveedor ---------- */
    var registrarPara = 'solicitante';
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-registrar-para]');
        if (b) { registrarPara = b.getAttribute('data-registrar-para'); }
    }, true);

    document.addEventListener('colaborador:registrado', function (e) {
        if (!formPase()) { return; }
        elegirColaborador(cajaDe(registrarPara), e.detail || {});
    });

    // Dirección y teléfono capturados en el alta rápida (ese formulario se limpia antes de avisar)
    var contactoProveedor = null;
    document.addEventListener('submit', function (e) {
        if (!e.target.matches || !e.target.matches('[data-alta-rapida-proveedor]')) { return; }
        contactoProveedor = {
            direccion: (e.target.querySelector('[name="direccion"]') || {}).value || '',
            telefono: (e.target.querySelector('[name="telefono"]') || {}).value || ''
        };
    }, true);

    document.addEventListener('proveedor:registrado', function (e) {
        var form = formPase();
        var select = form && form.querySelector('[data-pase-proveedor]');
        if (!select || !e.detail) { return; }
        var p = e.detail;
        var opcion = Array.prototype.filter.call(select.options, function (o) { return o.value === String(p.id); })[0];
        if (!opcion) {
            opcion = document.createElement('option');
            opcion.value = String(p.id);
            opcion.textContent = p.nombre;
            opcion.setAttribute('data-direccion', contactoProveedor && !p.ya_existia ? contactoProveedor.direccion : '');
            opcion.setAttribute('data-telefono', contactoProveedor && !p.ya_existia ? contactoProveedor.telefono : '');
            select.appendChild(opcion);
        }
        select.value = String(p.id);
        select.dispatchEvent(new Event('change', { bubbles: true }));
    });

    /* ---------- Envío: el solicitante (y el colaborador destino) son obligatorios ---------- */
    document.addEventListener('submit', function (e) {
        if (!e.target.matches || !e.target.matches('[data-form-pase]')) { return; }
        var faltan = Array.prototype.filter.call(e.target.querySelectorAll('[data-pase-colaborador] [data-lector-id]'), function (oculto) {
            var caja = oculto.closest('[data-lector]');
            var requerido = oculto.hasAttribute('data-requerido') || !!caja.closest('[data-para="destino"]');
            return !caja.closest('[hidden]') && requerido && !oculto.disabled && oculto.value === '';
        });
        if (!faltan.length) { return; }
        e.preventDefault();
        faltan.forEach(function (oculto) {
            estadoLector(oculto.closest('[data-lector]'), 'Falta elegir a la persona: escanea su gafete o escribe su nombre y elígela de la lista.', 'error');
        });
        faltan[0].closest('[data-lector]').querySelector('[data-lector-entrada]').focus();
    });

    /* ---------- Abrir y cerrar el diálogo Nuevo Pase ---------- */
    document.addEventListener('click', function (e) {
        if (!e.target.closest('[data-abrir-dialogo="dialogoNuevoPase"]')) { return; }
        var form = formPase();
        if (form) { sincronizar(form); }
    });

    document.addEventListener('close', function (e) {
        if (!(e.target instanceof HTMLDialogElement) || e.target.id !== 'dialogoNuevoPase') { return; }
        var form = e.target.querySelector('[data-form-pase]');
        if (!form) { return; }
        reiniciarArticulos(form);
        mostrarDepto(null);
        sincronizar(form);
    }, true);

    document.addEventListener('DOMContentLoaded', function () {
        var form = formPase();
        if (form) {
            sincronizar(form);
            // Tras un error: el solicitante ya elegido vuelve a mostrar su departamento y puesto
            var caja = cajaDe('solicitante');
            var oculto = caja && caja.querySelector('[data-lector-id]');
            var titulo = caja && caja.querySelector('[data-lector-titulo]');
            if (oculto && oculto.value && titulo) {
                buscarColaboradores(form, titulo.textContent.split(' · ')[0]).then(function (lista) {
                    mostrarDepto(lista.filter(function (x) { return String(x.id) === oculto.value; })[0] || null);
                }).catch(function () { /* sin datos */ });
            }
        }
        // Tras un error al firmar, el recuadro de firma queda a la vista
        var cajaFirma = document.querySelector('[data-dialogo-detalle-pase] [data-caja-firma-pase]:not([hidden])');
        if (cajaFirma) { setTimeout(function () { cajaFirma.scrollIntoView({ block: 'center' }); }, 50); }
    });
})();
/* Fin Pases de salida */
/* ==========================================================================
   Bitácora de transporte (seguridad/transporte): diálogo "Registrar Bitácora
   Logística" y "Editar Registro".
   - La lista "Ruta" se filtra por sede y tipo (llegada / salida) y propone el
     horario más cercano a la hora actual de la sede (data-horarios-sugeridos; no usar data-sugerencias: es la caja de sugerencias de Accesos y su Escape la vaciaba — Seguridad: FUN-01).
   - Estatus "NO LLEGO": se ocultan los datos de la unidad y aparecen los taxis
     (plantilla <template data-plantilla-taxi>); siempre queda al menos uno.
   - Pasajeros de cada taxi: se agregan con el lector universal (lector:elegido)
     como fichas con su campo oculto; también los de un alta provisional
     (colaborador:registrado).
   - Tope de la ruta: si el monto lo supera, la justificación se vuelve obligatoria.
   - Placas y chofer conocidos completan marca, modelo, número económico,
     capacidad y teléfono (sin consultar al servidor).
   ========================================================================== */
(function () {
    'use strict';

    function leer(texto, porDefecto) { try { return JSON.parse(texto || ''); } catch (x) { return porDefecto; } }
    function valorRadio(form, nombre) { var r = form.querySelector('input[name="' + nombre + '"]:checked'); return r ? r.value : ''; }
    function sedeDe(form) { var s = form.querySelector('[data-sede-transporte]'); return s ? s.value : ''; }
    function dinero(n) { return '$' + Number(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }

    /* ---------- Pasajeros (fichas) ---------- */
    function contar(bloque) {
        var c = bloque.querySelector('[data-contador-pax]');
        if (c) { c.textContent = bloque.querySelectorAll('.chip-pasajero').length + ' PAX'; }
    }

    function agregarPasajero(bloque, id, texto) {
        var lista = bloque.querySelector('[data-pasajeros]');
        if (!lista || lista.querySelector('.chip-pasajero[data-id="' + id + '"]')) { return false; }
        var chip = document.createElement('span');
        chip.className = 'chip-pasajero';
        chip.setAttribute('data-id', id);
        chip.appendChild(document.createTextNode(texto));
        var oculto = document.createElement('input');
        oculto.type = 'hidden'; oculto.name = bloque.getAttribute('data-nombre'); oculto.value = id;
        chip.appendChild(oculto);
        var quitar = document.createElement('button');
        quitar.type = 'button'; quitar.setAttribute('data-quitar-pasajero', ''); quitar.setAttribute('aria-label', 'Quitar a ' + texto); quitar.textContent = '×';
        chip.appendChild(quitar);
        lista.appendChild(chip);
        contar(bloque);
        return true;
    }

    var bloqueAlta = null; // a qué taxi va el colaborador que se da de alta provisional

    document.addEventListener('lector:elegido', function (e) {
        var bloque = e.target.closest('[data-pasajeros-taxi]');
        if (!bloque) { return; }
        var r = e.detail || {};
        var caja = e.target.closest('[data-lector]');
        var nuevo = agregarPasajero(bloque, r.id, r.titulo + (r.detalle ? ' · ' + r.detalle.split(' · ')[0] : ''));
        if (window.Lector && caja) {
            window.Lector.limpiar(caja);
            var estado = caja.querySelector('[data-lector-estado]');
            if (estado) {
                estado.hidden = false;
                estado.className = 'lector-estado ' + (nuevo ? 'ok' : 'info');
                estado.textContent = nuevo ? 'Agregado: ' + r.titulo + '. Escanea el siguiente.' : r.titulo + ' ya estaba en la lista.';
            }
            var entrada = caja.querySelector('[data-lector-entrada]');
            if (entrada) { entrada.focus(); }
        }
    });

    document.addEventListener('colaborador:registrado', function (e) {
        if (!bloqueAlta || !document.body.contains(bloqueAlta)) { return; }
        var c = e.detail || {};
        agregarPasajero(bloqueAlta, c.id, c.nombre_completo + (c.num_empleado ? ' · Núm. ' + c.num_empleado : ' · provisional'));
        bloqueAlta = null;
    });

    /* ---------- Tope de la ruta ---------- */
    function topeDe(form) {
        if (form.hasAttribute('data-form-editar-transporte')) { return parseFloat(form.getAttribute('data-tope')); }
        var ruta = form.querySelector('[data-ruta-transporte]');
        var op = ruta && ruta.value ? ruta.selectedOptions[0] : null;
        return op ? parseFloat(op.getAttribute('data-tope')) : NaN;
    }

    function revisarTope(contenedor, form) {
        var monto = contenedor.querySelector('[data-monto-taxi]');
        var aviso = contenedor.querySelector('[data-aviso-tope]');
        var just = contenedor.querySelector('[data-justificacion-taxi]');
        if (!monto || !aviso) { return; }
        var tope = topeDe(form);
        var valor = parseFloat(monto.value) || 0;
        var excede = !isNaN(tope) && valor > tope && !monto.disabled;
        aviso.hidden = !excede;
        if (just) { just.required = excede; }
        if (excede) {
            var t = aviso.querySelector('[data-texto-tope]');
            if (t) { t.textContent = 'Este monto (' + dinero(valor) + ') supera el tope autorizado de ' + dinero(tope) + ' para esta ruta.'; }
        }
    }

    /* ---------- Taxis ---------- */
    function renumerar(form) {
        var filas = form.querySelectorAll('[data-taxi]');
        filas.forEach(function (f, i) {
            var t = f.querySelector('[data-titulo-taxi]');
            if (t) { t.textContent = 'Taxi ' + (i + 1); }
            var q = f.querySelector('[data-quitar-taxi]');
            if (q) { q.disabled = filas.length <= 1; q.title = filas.length <= 1 ? 'Debe haber al menos un taxi' : 'Quitar este taxi'; }
        });
    }

    function listaParaderos(form) {
        var sede = sedeDe(form) || '0';
        form.querySelectorAll('[data-destino-taxi]').forEach(function (c) { c.setAttribute('list', 'paraderosTransporte-' + sede); });
    }

    function agregarTaxi(form) {
        var cont = form.querySelector('[data-taxis]');
        var plantilla = document.querySelector('template[data-plantilla-taxi]');
        if (!cont || !plantilla) { return null; }
        var n = parseInt(cont.getAttribute('data-siguiente') || '0', 10);
        cont.setAttribute('data-siguiente', String(n + 1));
        var caja = document.createElement('div');
        caja.innerHTML = plantilla.innerHTML.replace(/__T__/g, String(n)).trim();
        var fila = caja.firstElementChild;
        cont.appendChild(fila);
        fila.querySelectorAll('[data-lector]').forEach(function (l) { if (window.Lector) { window.Lector.preparar(l); } });
        listaParaderos(form);
        renumerar(form);
        return fila;
    }

    /* ---------- Sincronizar el alta ---------- */
    function sobrecupo(form) {
        var pax = form.querySelector('[data-pax-normal]');
        var cap = form.querySelector('[data-capacidad-normal]');
        var aviso = form.querySelector('[data-aviso-sobrecupo]');
        if (!pax || !cap || !aviso) { return; }
        var p = parseInt(pax.value, 10) || 0;
        var c = parseInt(cap.value, 10) || 0;
        aviso.hidden = !(c > 0 && p > c);
        if (!aviso.hidden) { aviso.querySelector('[data-texto-sobrecupo]').textContent = 'Sobrecupo: ' + p + ' pasajeros declarados contra una capacidad de ' + c + '. Verifica antes de continuar.'; }
    }

    function firmaGuardia(form, taxis) {
        var firma = form.querySelector('input[name="firma_guardia"]');
        if (!firma) { return; }
        var caja = firma.closest('[data-firma]');
        if (taxis) { firma.setAttribute('data-firma-requerida', ''); } else { firma.removeAttribute('data-firma-requerida'); caja.classList.remove('falta'); }
        var etiqueta = caja.querySelector('.campo-etiqueta');
        var asterisco = etiqueta && etiqueta.querySelector('[data-asterisco]');
        if (etiqueta && taxis && !asterisco) {
            asterisco = document.createElement('span');
            asterisco.className = 'text-danger'; asterisco.setAttribute('data-asterisco', ''); asterisco.setAttribute('aria-hidden', 'true'); asterisco.textContent = ' *';
            etiqueta.appendChild(asterisco);
        } else if (!taxis && asterisco) { asterisco.remove(); }
    }

    function sincronizar(form, elegirSugerido) {
        var sede = sedeDe(form);
        var tipo = valorRadio(form, 'tipo_movimiento');
        var estatus = valorRadio(form, 'estatus');
        var ruta = form.querySelector('[data-ruta-transporte]');
        var dialogo = form.closest('dialog');
        var sugerencias = leer(dialogo && dialogo.getAttribute('data-horarios-sugeridos'), {});

        // Rutas de la sede y el sentido elegidos
        var visibles = 0;
        if (ruta) {
            Array.prototype.forEach.call(ruta.options, function (op) {
                if (!op.value) { return; }
                var ok = op.getAttribute('data-sede') === sede && op.getAttribute('data-sentido') === tipo;
                op.hidden = !ok; op.disabled = !ok;
                if (ok) { visibles++; }
            });
            var actual = ruta.value ? ruta.selectedOptions[0] : null;
            if (elegirSugerido || (actual && actual.disabled)) {
                var sugerido = sugerencias[sede] && sugerencias[sede][tipo];
                ruta.value = sugerido ? String(sugerido) : '';
                if (ruta.value && ruta.selectedOptions[0].disabled) { ruta.value = ''; }
            }
        }
        var sinRutas = form.querySelector('[data-sin-rutas]');
        if (sinRutas) { sinRutas.hidden = !sede || !tipo || visibles > 0; }

        // Transportista y tope de la ruta elegida
        var info = form.querySelector('[data-info-ruta]');
        var op = ruta && ruta.value ? ruta.selectedOptions[0] : null;
        if (info) {
            info.hidden = !op;
            info.textContent = '';
            if (op) {
                var tope = op.getAttribute('data-tope');
                var i = document.createElement('i'); i.className = 'bi bi-building me-1'; i.setAttribute('aria-hidden', 'true');
                info.appendChild(i);
                info.appendChild(document.createTextNode('Empresa Asociada: ' + (op.getAttribute('data-transportista') || '—')
                    + ' · ' + (tope ? 'Tope por taxi: ' + dinero(tope) : 'Sin tope por taxi')));
            }
        }

        // Unidad normal o taxis
        var taxis = estatus === 'no_llego';
        var normal = form.querySelector('[data-caja-normal]');
        var cajaTaxi = form.querySelector('[data-caja-taxi]');
        if (normal) {
            normal.hidden = taxis;
            normal.querySelectorAll('input, select, textarea').forEach(function (c) { c.disabled = taxis; });
        }
        if (cajaTaxi) {
            cajaTaxi.hidden = !taxis;
            var cont = cajaTaxi.querySelector('[data-taxis]');
            if (taxis && cont && !cont.querySelector('[data-taxi]')) { agregarTaxi(form); }
            if (!taxis && cont) { cont.innerHTML = ''; cont.setAttribute('data-siguiente', '0'); }
        }
        firmaGuardia(form, taxis);
        listaParaderos(form);
        form.querySelectorAll('[data-taxi]').forEach(function (f) { revisarTope(f, form); });
        renumerar(form);
        sobrecupo(form);
    }

    /* ---------- Autollenado de unidad y chofer ---------- */
    function autollenarUnidad(campo) {
        var form = campo.form;
        var mapa = leer(form.getAttribute(campo.getAttribute('data-autollenar') === 'taxi' ? 'data-taxis' : 'data-unidades'), {});
        var datos = mapa[(campo.value || '').replace(/[\s\-.]+/g, '').toUpperCase()];
        if (!datos) { return; }
        var raiz = campo.closest('[data-taxi]') || campo.closest('[data-caja-normal]');
        Object.keys(datos).forEach(function (k) {
            var c = raiz.querySelector('[data-campo-unidad="' + k + '"]');
            if (!c) { return; }
            if (k === 'tipo') {
                if (c.querySelector('option[value="' + datos[k] + '"]')) { c.value = datos[k]; }
            } else if (!c.value) { c.value = datos[k]; }
        });
        sobrecupo(form);
    }

    function autollenarChofer(campo) {
        var mapa = leer(campo.form.getAttribute('data-choferes'), {});
        var tel = mapa[(campo.value || '').trim().replace(/\s+/g, ' ').toUpperCase()];
        if (!tel) { return; }
        var raiz = campo.closest('[data-taxi]') || campo.closest('[data-caja-normal]');
        var c = raiz && raiz.querySelector('[data-telefono-chofer]');
        if (c && !c.value) { c.value = tel; }
    }

    /* ---------- Editar ---------- */
    function abrirEdicion(boton) {
        var dialogo = document.getElementById(boton.getAttribute('data-dialogo'));
        var f = dialogo && dialogo.querySelector('form[data-form-editar-transporte]');
        if (!f) { return; }
        var v = leer(boton.getAttribute('data-valores'), {});
        f.action = boton.getAttribute('data-url');
        f.querySelector('[data-campo-dialogo]').value = 'editar-' + boton.getAttribute('data-id');
        f.setAttribute('data-tope', v.tope === null || v.tope === undefined ? '' : String(v.tope));
        dialogo.querySelector('[data-editar-folio]').textContent = v.folio || '';
        var badge = dialogo.querySelector('[data-editar-tipo]');
        badge.textContent = v.taxi ? 'TAXI' : 'NORMAL';
        badge.className = 'badge-tipo-registro ' + (v.taxi ? 'taxi' : 'normal');
        var soloTaxi = f.querySelector('[data-solo-taxi]');
        var soloNormal = f.querySelector('[data-solo-normal]');
        soloTaxi.hidden = !v.taxi;
        soloNormal.hidden = !!v.taxi;
        soloTaxi.querySelectorAll('input, textarea').forEach(function (c) { c.disabled = !v.taxi; });
        soloNormal.querySelectorAll('input').forEach(function (c) { c.disabled = !!v.taxi; });
        ['cantidad_pax', 'monto', 'destino', 'justificacion', 'observaciones'].forEach(function (nombre) {
            var c = f.querySelector('[name="' + nombre + '"]');
            if (c) { c.value = v[nombre] === null || v[nombre] === undefined ? '' : String(v[nombre]); }
        });
        f.querySelectorAll('input[name="estatus"]').forEach(function (r) { r.checked = r.value === v.estatus; });
        var destino = f.querySelector('[data-destino-taxi]');
        if (destino) { destino.setAttribute('list', 'paraderosTransporte-' + v.sede_id); }
        var bloque = f.querySelector('[data-pasajeros-taxi]');
        if (bloque) {
            bloque.querySelector('[data-pasajeros]').innerHTML = '';
            (v.pasajeros || []).forEach(function (p) { agregarPasajero(bloque, p.id, p.texto); });
            contar(bloque);
        }
        revisarTope(f, f);
        if (typeof dialogo.showModal === 'function' && !dialogo.open) { dialogo.showModal(); }
    }

    /* ---------- Eventos ---------- */
    document.addEventListener('change', function (e) {
        var form = e.target.form;
        if (!form || !form.matches('[data-form-transporte]')) { return; }
        var cambioSedeOTipo = e.target.matches('[data-sede-transporte]') || e.target.name === 'tipo_movimiento';
        if (cambioSedeOTipo || e.target.name === 'estatus' || e.target.matches('[data-ruta-transporte]')) { sincronizar(form, cambioSedeOTipo); }
        if (e.target.matches('[data-autollenar]')) { autollenarUnidad(e.target); }
        if (e.target.matches('[data-autollenar-chofer]')) { autollenarChofer(e.target); }
    });

    document.addEventListener('input', function (e) {
        var form = e.target.form;
        if (!form || !form.matches('[data-form-transporte], [data-form-editar-transporte]')) { return; }
        if (e.target.matches('[data-monto-taxi]')) { revisarTope(e.target.closest('[data-taxi]') || form, form); }
        if (e.target.matches('[data-pax-normal], [data-capacidad-normal]')) { sobrecupo(form); }
        // Al elegir de la lista de sugerencias el navegador lanza "input"
        if (e.target.matches('[data-autollenar]')) { autollenarUnidad(e.target); }
        if (e.target.matches('[data-autollenar-chofer]')) { autollenarChofer(e.target); }
    });

    document.addEventListener('click', function (e) {
        var form = e.target.closest('form[data-form-transporte]');
        if (form && e.target.closest('[data-agregar-taxi]')) {
            var fila = agregarTaxi(form);
            if (fila) {
                fila.scrollIntoView({ block: 'nearest' });
                var placas = fila.querySelector('input[type="text"]');
                if (placas) { placas.focus(); }
            }
            return;
        }
        var quitarTaxi = form && e.target.closest('[data-quitar-taxi]');
        if (quitarTaxi) {
            if (form.querySelectorAll('[data-taxi]').length > 1) { quitarTaxi.closest('[data-taxi]').remove(); renumerar(form); }
            return;
        }
        var quitar = e.target.closest('[data-quitar-pasajero]');
        if (quitar) {
            var bloque = quitar.closest('[data-pasajeros-taxi]');
            quitar.closest('.chip-pasajero').remove();
            if (bloque) { contar(bloque); }
            return;
        }
        var alta = e.target.closest('[data-alta-pasajero]');
        if (alta) { bloqueAlta = alta.closest('[data-pasajeros-taxi]'); return; }

        var editar = e.target.closest('[data-accion="editar-movimiento"]');
        if (editar) { abrirEdicion(editar); }
    });

    // Al cerrar, el alta vuelve a su estado inicial (sin taxis ni pasajeros) y la edición sin fichas
    // ("close" no burbujea: se escucha en captura, después del limpiado genérico)
    document.addEventListener('close', function (e) {
        if (!(e.target instanceof HTMLDialogElement)) { return; }
        e.target.querySelectorAll('form[data-form-transporte]').forEach(function (form) {
            var cont = form.querySelector('[data-taxis]');
            if (cont) { cont.innerHTML = ''; cont.setAttribute('data-siguiente', '0'); }
            // Después de que los campos vuelvan a sus valores iniciales (manejador genérico)
            setTimeout(function () { sincronizar(form, true); }, 0);
        });
        e.target.querySelectorAll('form[data-form-editar-transporte] [data-pasajeros]').forEach(function (l) { l.innerHTML = ''; });
    }, true);

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('form[data-form-transporte]').forEach(function (form) {
            sincronizar(form, false);
            form.querySelectorAll('[data-pasajeros-taxi]').forEach(contar);
        });
        document.querySelectorAll('form[data-form-editar-transporte]').forEach(function (form) {
            form.querySelectorAll('[data-pasajeros-taxi]').forEach(contar);
            revisarTope(form, form);
        });
    });
})();
/* Fin Bitácora de transporte */
/* ==========================================================================
   Filtros que se aplican solos (<form method="GET" data-autoenviar>): al
   cambiar una lista, fecha o casilla se aplica de inmediato; al escribir en
   la búsqueda, al dejar de teclear. Al recargar, el cursor vuelve al campo
   donde se estaba escribiendo.
   ========================================================================== */
(function () {
    'use strict';
    var CLAVE = 'plataforma:filtro-activo';
    var espera = null;

    function enviar(form, campo) {
        try { sessionStorage.setItem(CLAVE, form.getAttribute('action') + '|' + (campo && campo.name || '')); } catch (e) { /* sin almacenamiento: no pasa nada */ }
        if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
    }

    document.addEventListener('change', function (e) {
        var form = e.target.form;
        if (!form || !form.hasAttribute('data-autoenviar')) { return; }
        if (e.target.matches('input[type="search"], input[type="text"]')) { return; }
        clearTimeout(espera);
        enviar(form, e.target);
    });

    document.addEventListener('input', function (e) {
        var form = e.target.form;
        if (!form || !form.hasAttribute('data-autoenviar') || !e.target.matches('input[type="search"], input[type="text"]')) { return; }
        clearTimeout(espera);
        espera = setTimeout(function () { enviar(form, e.target); }, 700);
    });

    document.addEventListener('DOMContentLoaded', function () {
        var guardado = null;
        try { guardado = sessionStorage.getItem(CLAVE); sessionStorage.removeItem(CLAVE); } catch (e) { return; }
        if (!guardado) { return; }
        var partes = guardado.split('|');
        document.querySelectorAll('form[data-autoenviar]').forEach(function (form) {
            if (form.getAttribute('action') !== partes[0] || !partes[1]) { return; }
            var campo = form.elements[partes[1]];
            if (campo && campo.matches && campo.matches('input[type="search"], input[type="text"]')) {
                campo.focus();
                var fin = campo.value.length;
                try { campo.setSelectionRange(fin, fin); } catch (e) { /* tipos sin selección */ }
            }
        });
    });
})();
/* Fin Filtros que se aplican solos */
/* ==========================================================================
   Listas con buscador (<select data-select-buscable>): agrega encima un
   campo para escribir y deja en la lista solo las opciones que coinciden
   (sin importar acentos ni mayúsculas). Si queda una sola, se elige sola.
   ========================================================================== */
(function () {
    'use strict';
    function normal(t) { return (t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); }

    function preparar(select) {
        if (select.dataset.buscableListo) { return; }
        select.dataset.buscableListo = '1';
        var buscador = document.createElement('input');
        buscador.type = 'search';
        buscador.className = 'campo campo-buscador-lista';
        buscador.placeholder = select.dataset.selectBuscable || 'Escribe para buscar…';
        buscador.setAttribute('aria-label', buscador.placeholder);
        buscador.autocomplete = 'off';
        select.parentNode.insertBefore(buscador, select);

        buscador.addEventListener('input', function () {
            var q = normal(buscador.value.trim());
            var visibles = [];
            Array.prototype.forEach.call(select.options, function (op) {
                if (!op.value) { return; }
                var ok = !q || normal(op.textContent).indexOf(q) !== -1;
                op.hidden = !ok;
                op.disabled = !ok;
                if (ok) { visibles.push(op); }
            });
            if (visibles.length === 1) { select.value = visibles[0].value; }
            else if (select.selectedOptions[0] && select.selectedOptions[0].hidden) { select.value = ''; }
            select.dispatchEvent(new Event('change', { bubbles: true }));
        });
        buscador.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); } });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('select[data-select-buscable]').forEach(preparar);
    });
    // Al cerrar el diálogo, el buscador se limpia y la lista vuelve completa
    document.addEventListener('close', function (e) {
        if (!(e.target instanceof HTMLDialogElement)) { return; }
        e.target.querySelectorAll('.campo-buscador-lista').forEach(function (b) {
            b.value = '';
            b.dispatchEvent(new Event('input'));
        });
    }, true);
})();
/* Fin Listas con buscador */
/* ==========================================================================
   Lost & Found (archivo) y Robo — Seguimiento: escanear la etiqueta de la
   bolsa (abre la ficha del artículo), "Cerrar / Entregar" (bloques según cómo
   se cierra y quién recibe, quién firma, persona del Padrón, colaborador con
   el lector universal) y un solo envío. El expediente de Robo usa los
   ganchos de la Bitácora de Novedades (formato, filas, coincidencias).
   ========================================================================== */
(function () {
    'use strict';

    function marcado(form, nombre) {
        var r = form.querySelector('input[name="' + nombre + '"]:checked');
        return r ? r.value : '';
    }

    function poner(raiz, selector, texto) {
        var el = raiz.querySelector(selector);
        if (el) { el.textContent = texto || ''; }
    }

    /* ---------- Bloques según "¿Cómo se cierra?" y "¿Quién recibe?" ----------
       <div data-lf-si-tipo="PERSONA PAQUETERIA" [data-lf-si-recibe="externo"]>:
       oculto, sus campos se deshabilitan (no se envían ni se validan). */
    function sincronizar(form) {
        var tipo = marcado(form, 'tipo_cierre');
        var recibe = marcado(form, 'recibe_es') || 'externo';
        form.querySelectorAll('[data-lf-si-tipo]').forEach(function (caja) {
            var tipos = caja.getAttribute('data-lf-si-tipo').split(' ');
            var quien = caja.getAttribute('data-lf-si-recibe');
            var visible = tipos.indexOf(tipo) !== -1 && (!quien || quien === recibe);
            caja.hidden = !visible;
            caja.querySelectorAll('input, select, textarea, button').forEach(function (c) { c.disabled = !visible; });
        });
        var elegido = form.querySelector('input[name="tipo_cierre"]:checked');
        var etiqueta = form.querySelector('#lf_firma_etiqueta');
        if (elegido && etiqueta && etiqueta.firstChild) { etiqueta.firstChild.nodeValue = elegido.dataset.etiquetaFirma + ' '; }
        form.querySelectorAll('.opcion-cierre-lf').forEach(function (l) {
            var r = l.querySelector('input');
            l.classList.toggle('elegida', !!(r && r.checked));
        });
    }

    document.addEventListener('change', function (e) {
        var form = e.target.form;
        if (!form || !form.matches('[data-form-cierre-lf]') || (e.target.name !== 'tipo_cierre' && e.target.name !== 'recibe_es')) { return; }
        // Cada forma de cierre la firma alguien distinto: al cambiarla, la firma se vuelve a pedir
        if (e.target.name === 'tipo_cierre') {
            form.querySelectorAll('[data-firma]').forEach(function (caja) {
                if (window.Firma) { window.Firma.preparar(caja); }
                if (caja.limpiarFirma) { caja.limpiarFirma(); }
            });
        }
        sincronizar(form);
    });

    /* ---------- Abrir "Cerrar / Entregar" desde el archivo ---------- */
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-accion="lf-cerrar"]');
        if (!b) { return; }
        var dialogo = document.getElementById('dialogoCerrarArticulo');
        var form = dialogo && dialogo.querySelector('[data-form-cierre-lf]');
        if (!form) { return; }
        form.action = b.dataset.url;
        form.querySelector('[data-lf-dialogo]').value = 'cerrar-' + b.dataset.id;
        poner(dialogo, '[data-lf-folio]', b.dataset.folio);
        poner(dialogo, '[data-lf-objeto]', b.dataset.objeto);
        poner(dialogo, '[data-lf-detalle]', b.dataset.detalle);
        poner(dialogo, '[data-lf-bodega]', b.dataset.bodega);
        var vinculo = dialogo.querySelector('[data-lf-vinculo]');
        if (vinculo) { vinculo.hidden = !b.dataset.vinculo; poner(vinculo, '[data-lf-vinculo-texto]', b.dataset.vinculo); }
        // Si un reporte de pérdida está vinculado, se propone a ese huésped como quien recibe
        form.querySelectorAll('[data-lf-nombre-recibe]').forEach(function (c) { c.value = (b.dataset.recibe || '').toUpperCase(); });
        form.querySelectorAll('[data-lf-correo]').forEach(function (c) { c.value = b.dataset.correo || ''; });
        olvidarPersona(form);
        sincronizar(form);
        if (typeof dialogo.showModal === 'function' && !dialogo.open) { dialogo.showModal(); }
    });

    /* ---------- Persona registrada en el Padrón (registro rápido) ---------- */
    function olvidarPersona(form) {
        var id = form.querySelector('[data-lf-persona-id]');
        var aviso = form.querySelector('[data-lf-persona-elegida]');
        if (id) { id.value = ''; }
        if (aviso) { aviso.hidden = true; }
    }

    document.addEventListener('persona:registrada', function (e) {
        var form = document.querySelector('[data-form-cierre-lf]');
        if (!form || !e.detail) { return; }
        var nombre = form.querySelector('#lf_nombre_recibe');
        if (nombre) { nombre.value = (e.detail.nombre_completo || '').toUpperCase(); nombre.dataset.persona = nombre.value; }
        var id = form.querySelector('[data-lf-persona-id]');
        if (id) { id.value = e.detail.id; }
        var aviso = form.querySelector('[data-lf-persona-elegida]');
        if (aviso) { aviso.hidden = false; }
    });

    // Si se escribe otro nombre, deja de contar la persona del padrón
    document.addEventListener('input', function (e) {
        if (e.target.id !== 'lf_nombre_recibe' || !e.target.dataset.persona) { return; }
        if (e.target.value.trim().toUpperCase() !== e.target.dataset.persona) {
            olvidarPersona(e.target.form);
            delete e.target.dataset.persona;
        }
    });

    /* ---------- Enviar: colaborador obligatorio donde aplica y un solo envío ---------- */
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.matches('[data-form-cierre-lf]') || e.defaultPrevented) { return; }
        var falta = Array.prototype.filter.call(form.querySelectorAll('[data-lector-id][data-requerido]'), function (c) { return !c.disabled && !c.value; })[0];
        if (falta) {
            e.preventDefault();
            var caja = falta.closest('[data-lector]');
            var estado = caja && caja.querySelector('[data-lector-estado]');
            if (estado) { estado.hidden = false; estado.className = 'lector-estado error'; estado.textContent = 'Escanea el gafete o busca al colaborador que recibe.'; }
            var entrada = caja && caja.querySelector('[data-lector-entrada]');
            if (entrada) { entrada.focus(); }
            return;
        }
        setTimeout(function () { form.querySelectorAll('button[type="submit"]').forEach(function (b) { b.disabled = true; }); }, 0);
    });

    // Al cerrar (y limpiarse) el diálogo, los bloques se acomodan otra vez
    document.addEventListener('close', function (e) {
        if (!(e.target instanceof HTMLDialogElement)) { return; }
        var form = document.querySelector('[data-form-cierre-lf]');
        if (form) { setTimeout(function () { sincronizar(form); }, 0); }
    }, true);

    /* ---------- Escanear la etiqueta de la bolsa: abre la ficha del artículo ---------- */
    document.addEventListener('lector:elegido', function (e) {
        if (!e.target.closest || !e.target.closest('.escaner-lf') || !e.detail || !e.detail.url) { return; }
        window.location.href = e.detail.url;
    });

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-form-cierre-lf]').forEach(sincronizar);
    });
})();
/* Fin Lost & Found (archivo) y Robo — Seguimiento */
/* ==========================================================================
   Recorridos de Protección Civil (seguridad/recorridos-pc)
   - Lista: filtro por estatus (píldoras), sede y texto.
   - Recorrido en curso: al escanear (lector universal, evento
     "lector:elegido") se abre el punto de inspección de ese equipo; en PC
     con lector USB el campo queda listo para leer sin tocar la pantalla.
   - Punto de inspección: piezas de la categoría elegida (captura a mano),
     resultado en vivo (OK / FALLA) y confirmación de «Finalizar Recorrido».
   - Catálogo de equipos: filtros, zona/piso y área específica según la sede
     (y el área según la zona elegida), «Ver QR».
   ========================================================================== */
(function () {
    'use strict';

    /* ---------- Lista de recorridos ---------- */
    function filtrarRecorridos() {
        var lista = document.querySelector('[data-lista-rpc]');
        if (!lista) { return; }
        var pill = document.querySelector('[data-filtro-rpc-estatus][aria-pressed="true"]');
        var estatus = pill ? pill.getAttribute('data-filtro-rpc-estatus') : '';
        var campoTexto = document.querySelector('[data-filtro-rpc="texto"]');
        var campoSede = document.querySelector('[data-filtro-rpc="sede"]');
        var texto = campoTexto ? campoTexto.value.toLowerCase().trim() : '';
        var sede = campoSede ? campoSede.value : '';
        var fichas = lista.querySelectorAll('[data-rpc]');
        var visibles = 0;
        fichas.forEach(function (f) {
            var ok = (estatus === '' || f.dataset.estatus === estatus)
                && (sede === '' || f.dataset.sede === sede)
                && (texto === '' || (f.dataset.texto || '').indexOf(texto) !== -1);
            f.hidden = !ok;
            if (ok) { visibles++; }
        });
        var vacio = lista.querySelector('[data-sin-resultados-rpc]');
        if (vacio) { vacio.hidden = visibles !== 0 || fichas.length === 0; }
    }

    /* ---------- Catálogo de equipos ---------- */
    function filtrarEquipos() {
        var lista = document.querySelector('[data-lista-epc]');
        if (!lista) { return; }
        var pill = document.querySelector('[data-filtro-epc-estado][aria-pressed="true"]');
        var activo = pill ? pill.getAttribute('data-filtro-epc-estado') : '';
        var valor = function (n) { var el = document.querySelector('[data-filtro-epc="' + n + '"]'); return el ? el.value : ''; };
        var texto = valor('texto').toLowerCase().trim();
        var sede = valor('sede');
        var categoria = valor('categoria');
        var fichas = lista.querySelectorAll('[data-epc]');
        var visibles = 0;
        fichas.forEach(function (f) {
            var ok = (activo === '' || f.dataset.activo === activo)
                && (sede === '' || f.dataset.sede === sede)
                && (categoria === '' || f.dataset.categoria === categoria)
                && (texto === '' || (f.dataset.texto || '').indexOf(texto) !== -1);
            f.hidden = !ok;
            if (ok) { visibles++; }
        });
        var vacio = lista.querySelector('[data-sin-resultados-epc]');
        if (vacio) { vacio.hidden = visibles !== 0 || fichas.length === 0; }
    }

    function elegirPildora(boton, atributo) {
        document.querySelectorAll('[' + atributo + ']').forEach(function (b) {
            var on = b === boton;
            b.classList.toggle('active', on);
            b.setAttribute('aria-pressed', String(on));
        });
    }

    /* Zona/piso y área específica: solo las de la sede elegida; el área, además, de la zona elegida */
    function acomodarUbicaciones(form) {
        var sede = form.querySelector('[data-epc-sede]');
        var zona = form.querySelector('[data-epc-zona]');
        var area = form.querySelector('[data-epc-area]');
        if (!sede || !zona) { return; }
        var idSede = sede.value;
        var mostrar = function (op, ok) { op.hidden = !ok; op.disabled = !ok; };
        Array.prototype.forEach.call(zona.options, function (op) {
            if (op.value === '') { op.textContent = idSede ? '-- Toda la sede --' : '-- Elige sede primero --'; return; }
            mostrar(op, op.dataset.sede === idSede && (!op.hasAttribute('data-inactivo') || op.selected));
        });
        if (zona.selectedOptions.length && zona.selectedOptions[0].disabled) { zona.value = ''; }
        if (!area) { return; }
        var idZona = zona.value;
        Array.prototype.forEach.call(area.options, function (op) {
            if (op.value === '') { return; }
            var ancestros = ' ' + (op.dataset.ancestros || '') + ' ';
            mostrar(op, op.dataset.sede === idSede && (idZona === '' || ancestros.indexOf(' ' + idZona + ' ') !== -1) && (!op.hasAttribute('data-inactivo') || op.selected));
        });
        if (area.selectedOptions.length && area.selectedOptions[0].disabled) { area.value = ''; }
    }

    /* Nuevo Recorrido: edificios de la sede elegida */
    function acomodarEdificios(form) {
        var sede = form.querySelector('[data-rpc-sede]');
        var edificio = form.querySelector('[data-rpc-depende-sede]');
        if (!sede || !edificio) { return; }
        Array.prototype.forEach.call(edificio.options, function (op) {
            if (op.value === '') { return; }
            var ok = op.dataset.sede === sede.value;
            op.hidden = !ok;
            op.disabled = !ok;
        });
        if (edificio.selectedOptions.length && edificio.selectedOptions[0].disabled) { edificio.value = ''; }
    }

    /* ---------- Punto de inspección ---------- */
    function acomodarCategoria(form) {
        var selector = form.querySelector('[data-rpc-categoria]');
        if (!selector) { return; }
        var cat = selector.value;
        form.querySelectorAll('[data-rpc-criterios]').forEach(function (bloque) {
            var on = bloque.getAttribute('data-rpc-criterios') === cat;
            bloque.hidden = !on;
            bloque.querySelectorAll('input').forEach(function (c) { c.disabled = !on; });
        });
        var universales = form.querySelector('[data-rpc-universales]');
        if (universales) {
            universales.hidden = cat === '';
            universales.querySelectorAll('input').forEach(function (c) { c.disabled = cat === ''; });
        }
        var aviso = form.querySelector('[data-rpc-sin-categoria]');
        if (aviso) { aviso.hidden = cat !== ''; }
    }

    function mostrarResultado(form) {
        var salida = form.querySelector('[data-rpc-resultado]');
        if (!salida) { return; }
        var casillas = form.querySelectorAll('.rpc-criterio input:not(:disabled)');
        if (!casillas.length) { salida.hidden = true; return; }
        var malas = Array.prototype.filter.call(casillas, function (c) { return !c.checked; }).length;
        var obs = form.querySelector('[name="observaciones"]');
        var conObs = obs && obs.value.trim() !== '';
        var falla = malas > 0 || conObs;
        salida.hidden = false;
        salida.className = 'rpc-resultado ' + (falla ? 'falla' : 'ok');
        salida.textContent = falla
            ? 'Resultado: FALLA' + (malas > 0 ? ' (' + malas + (malas === 1 ? ' pieza' : ' piezas') + ' con falla)' : ' (hay observaciones)') + ' — se reportará en la Bitácora de Novedades.'
            : 'Resultado: OK — todo sano y presente.';
    }

    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-filtro-rpc-estatus]');
        if (b) { elegirPildora(b, 'data-filtro-rpc-estatus'); filtrarRecorridos(); return; }
        b = e.target.closest('[data-filtro-epc-estado]');
        if (b) { elegirPildora(b, 'data-filtro-epc-estado'); filtrarEquipos(); return; }

        // Confirmación de un botón en particular (p. ej. «Finalizar Recorrido»)
        b = e.target.closest('[data-confirmar-boton]');
        if (b) {
            if (!window.confirm(b.getAttribute('data-confirmar-boton'))) { e.preventDefault(); }
            return;
        }

        // «Ver QR» del catálogo
        b = e.target.closest('[data-ver-qr-pc]');
        if (b) {
            var d = document.getElementById('dialogoQrEquipoPc');
            if (!d) { return; }
            d.querySelector('[data-qr-nombre]').textContent = b.dataset.nombre || '';
            d.querySelector('[data-qr-imagen]').src = b.dataset.qr;
            d.querySelector('[data-qr-enlace]').textContent = b.dataset.enlace || '';
            var imprimir = d.querySelector('[data-qr-imprimir]');
            imprimir.hidden = !b.dataset.imprimir;
            imprimir.href = b.dataset.imprimir || '#';
            d.showModal();
            return;
        }

        // Editar equipo: después del llenado genérico, se acomodan zona y área
        b = e.target.closest('[data-accion="editar-registro"][data-dialogo="dialogoEditarEquipoPc"]');
        if (b) {
            var form = document.querySelector('#dialogoEditarEquipoPc [data-form-epc]');
            if (!form) { return; }
            var valores = {};
            try { valores = JSON.parse(b.dataset.valores || '{}'); } catch (x) { /* sin valores */ }
            form.querySelectorAll('[data-epc-zona] option, [data-epc-area] option').forEach(function (op) { op.disabled = false; op.hidden = false; });
            ['zona_id', 'area_especifica_id'].forEach(function (n) {
                if (form.elements[n]) { form.elements[n].value = valores[n] === null || valores[n] === undefined ? '' : String(valores[n]); }
            });
            acomodarUbicaciones(form);
        }
    });

    document.addEventListener('input', function (e) {
        if (e.target.matches('[data-filtro-rpc="texto"]')) { filtrarRecorridos(); }
        if (e.target.matches('[data-filtro-epc="texto"]')) { filtrarEquipos(); }
        var form = e.target.closest && e.target.closest('[data-form-punto-rpc]');
        if (form) { mostrarResultado(form); }
    });

    document.addEventListener('change', function (e) {
        var el = e.target;
        if (el.matches('[data-filtro-rpc="sede"]')) { filtrarRecorridos(); }
        if (el.matches('select[data-filtro-epc]')) { filtrarEquipos(); }
        if (el.matches('[data-epc-sede]')) { var z = el.form.querySelector('[data-epc-zona]'); if (z) { z.value = ''; } }
        if (el.matches('[data-epc-sede], [data-epc-zona]')) {
            var a = el.form.querySelector('[data-epc-area]');
            if (a) { a.value = ''; }
            acomodarUbicaciones(el.form);
        }
        if (el.matches('[data-rpc-sede]')) { acomodarEdificios(el.form); }
        var punto = el.closest && el.closest('[data-form-punto-rpc]');
        if (punto) {
            if (el.matches('[data-rpc-categoria]')) { acomodarCategoria(punto); }
            mostrarResultado(punto);
        }
    });

    // Escanear un equipo abre su punto de inspección
    document.addEventListener('lector:elegido', function (e) {
        var form = e.target.closest && e.target.closest('[data-escaner-rpc]');
        if (!form || !e.detail || !e.detail.id) { return; }
        window.location.href = form.getAttribute('action') + '?equipo=' + encodeURIComponent(e.detail.id) + '#punto';
    });

    // Al cerrar (y limpiarse) un diálogo, sus combos vuelven a acomodarse
    document.addEventListener('close', function (e) {
        if (!(e.target instanceof HTMLDialogElement)) { return; }
        e.target.querySelectorAll('[data-form-epc]').forEach(acomodarUbicaciones);
        e.target.querySelectorAll('[data-form-nuevo-rpc]').forEach(acomodarEdificios);
    });

    document.addEventListener('DOMContentLoaded', function () {
        filtrarRecorridos();
        filtrarEquipos();
        document.querySelectorAll('[data-form-epc]').forEach(acomodarUbicaciones);
        document.querySelectorAll('[data-form-nuevo-rpc]').forEach(acomodarEdificios);
        document.querySelectorAll('[data-form-punto-rpc]').forEach(function (f) { acomodarCategoria(f); mostrarResultado(f); });

        // Con lector USB (PC: puntero fino) el campo queda listo para leer; en el celular no se abre el teclado solo
        var escaner = document.querySelector('[data-escaner-rpc] [data-lector-entrada]');
        if (escaner && window.matchMedia && window.matchMedia('(pointer: fine)').matches) { escaner.focus({ preventScroll: true }); }
    });
})();
/* Fin Recorridos de Protección Civil */
/* ==========================================================================
   Ajustes de captura (observaciones de QA):
   - Ojo para ver/ocultar en toda contraseña (input.campo[type=password]).
   - Horas siempre en formato de 24 h (HH:MM): los <input type="time"> se
     convierten en un campo de texto con dos puntos automáticos. Se excluye
     el formato de Accidente (no se modifica por indicación del responsable)
     y cualquier campo con data-hora-nativa.
   - Selector de archivo en español, con el nombre del archivo elegido.
   ========================================================================== */
(function () {
    'use strict';

    function prepararContrasena(input) {
        if (input.dataset.conOjo || input.closest('.acceso-entrada')) { return; }
        input.dataset.conOjo = '1';
        var caja = document.createElement('div');
        caja.className = 'campo-contrasena';
        input.parentNode.insertBefore(caja, input);
        caja.appendChild(input);
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn-ver-contrasena';
        b.setAttribute('aria-label', 'Mostrar u ocultar contraseña');
        b.innerHTML = '<i class="bi bi-eye" aria-hidden="true"></i>';
        b.addEventListener('click', function () {
            var ver = input.type === 'password';
            input.type = ver ? 'text' : 'password';
            b.innerHTML = '<i class="bi ' + (ver ? 'bi-eye-slash' : 'bi-eye') + '" aria-hidden="true"></i>';
        });
        caja.appendChild(b);
    }

    function formatearHora(v) {
        var d = v.replace(/\D/g, '').slice(0, 4);
        if (d.length >= 3) { return d.slice(0, 2) + ':' + d.slice(2); }
        return d;
    }

    function prepararHora(input) {
        if (input.dataset.hora24 || input.hasAttribute('data-hora-nativa') || input.closest('[data-formato="accidente"]')) { return; }
        input.dataset.hora24 = '1';
        var valor = (input.value || '').slice(0, 5);
        input.type = 'text';
        input.value = valor;
        input.defaultValue = (input.defaultValue || '').slice(0, 5);
        input.setAttribute('inputmode', 'numeric');
        input.setAttribute('maxlength', '5');
        input.setAttribute('pattern', '([01][0-9]|2[0-3]):[0-5][0-9]');
        input.setAttribute('autocomplete', 'off');
        if (!input.placeholder) { input.placeholder = 'HH:MM (24 h)'; }
        if (!input.title) { input.title = 'Hora en formato de 24 horas, por ejemplo 19:30'; }
        input.classList.add('campo-hora24');
    }

    document.addEventListener('input', function (e) {
        var i = e.target;
        if (!i.dataset || !i.dataset.hora24) { return; }
        var antes = i.value;
        var nuevo = formatearHora(antes);
        if (nuevo !== antes) { i.value = nuevo; }
    });
    // Al salir del campo, "730" → "07:30" y "7" → "07:00"
    document.addEventListener('blur', function (e) {
        var i = e.target;
        if (!i.dataset || !i.dataset.hora24 || !i.value) { return; }
        var d = i.value.replace(/\D/g, '');
        if (d.length === 1 || d.length === 2) { d = ('0' + d).slice(-2) + '00'; }
        else if (d.length === 3) { d = '0' + d; }
        i.value = formatearHora(d);
    }, true);

    // Al llenar un diálogo de edición la hora puede venir con segundos ("19:00:00")
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-accion="editar-registro"]');
        if (!b) { return; }
        setTimeout(function () {
            var d = document.getElementById(b.dataset.dialogo);
            if (!d) { return; }
            d.querySelectorAll('input[data-hora24]').forEach(function (i) { i.value = (i.value || '').slice(0, 5); });
        }, 0);
    });

    function prepararArchivo(input) {
        if (input.dataset.archivoListo) { return; }
        input.dataset.archivoListo = '1';
        var caja = document.createElement('label');
        caja.className = 'campo-archivo';
        caja.setAttribute('for', input.id || '');
        input.parentNode.insertBefore(caja, input);
        caja.appendChild(input);
        var boton = document.createElement('span');
        boton.className = 'campo-archivo-boton';
        boton.innerHTML = '<i class="bi bi-upload me-1" aria-hidden="true"></i>Elegir archivo';
        var nombre = document.createElement('span');
        nombre.className = 'campo-archivo-nombre';
        nombre.textContent = 'Ningún archivo elegido';
        caja.appendChild(boton);
        caja.appendChild(nombre);
        input.addEventListener('change', function () {
            nombre.textContent = input.files && input.files.length ? input.files[0].name : 'Ningún archivo elegido';
        });
        var form = input.form;
        if (form) { form.addEventListener('reset', function () { setTimeout(function () { nombre.textContent = 'Ningún archivo elegido'; }, 0); }); }
    }

    function preparar(raiz) {
        raiz.querySelectorAll('input.campo[type="password"], input.form-control[type="password"]').forEach(prepararContrasena);
        raiz.querySelectorAll('input[type="time"]').forEach(prepararHora);
        raiz.querySelectorAll('input[type="file"]').forEach(prepararArchivo);
    }

    document.addEventListener('DOMContentLoaded', function () {
        preparar(document);
        // Filas que se agregan después (horarios de rutas, llaves…)
        new MutationObserver(function (cambios) {
            cambios.forEach(function (c) {
                c.addedNodes.forEach(function (n) { if (n.nodeType === 1) { preparar(n.matches && n.matches('input') ? n.parentNode || n : n); } });
            });
        }).observe(document.body, { childList: true, subtree: true });
    });
})();
/* Fin Ajustes de captura */
/* ==========================================================================
   Ajustes QA 4: aviso de homónimos (Usuarios y Colaboradores)
   <div data-homonimos="url" data-homonimos-campos="name" data-homonimos-modo="usuarios|colaboradores">
   Al escribir el nombre se consulta si ya hay alguien con ese nombre (sin
   importar mayúsculas ni acentos). En Usuarios además sugiere vincular al
   colaborador y muestra «Sí, es otra persona con el mismo nombre».
   ========================================================================== */
(function () {
    'use strict';

    var esperas = {};
    var consecutivo = 0;

    function cajaDe(form) { return form ? form.querySelector('[data-homonimos]') : null; }

    function campos(caja) { return (caja.dataset.homonimosCampos || '').split(',').filter(Boolean); }

    function valor(form, nombre) {
        var c = form.querySelector('[name="' + nombre + '"]');
        return c ? c.value.trim() : '';
    }

    function limpiar(caja) {
        caja.hidden = true;
        caja.textContent = '';
        var form = caja.closest('form');
        var confirmar = form && form.querySelector('[data-confirmar-homonimo]');
        if (confirmar) {
            confirmar.hidden = true;
            var casilla = confirmar.querySelector('input');
            if (casilla) { casilla.checked = false; }
        }
    }

    function parrafo(texto, clase) {
        var p = document.createElement('p');
        p.className = clase || 'mb-2';
        p.textContent = texto;
        return p;
    }

    function vincular(form, c) {
        var numero = form.querySelector('[name="numero_colaborador"]');
        var oculto = form.querySelector('[data-campo-colaborador]');
        var nombre = form.querySelector('[name="name"]');
        if (numero) { numero.value = c.num_empleado || ''; }
        if (oculto) { oculto.value = String(c.id); }
        if (nombre) { nombre.value = c.nombre_completo; }
        var aviso = form.querySelector('[data-colab-vinculo]');
        if (aviso) { aviso.hidden = false; }
    }

    function pintarUsuarios(caja, datos) {
        var form = caja.closest('form');
        var oculto = form.querySelector('[data-campo-colaborador]');
        var colaboradores = (oculto && oculto.value !== '') ? [] : (datos.colaboradores || []);
        caja.textContent = '';
        if (datos.mensaje) {
            caja.appendChild(parrafo(datos.mensaje, 'fw-semibold mb-2'));
        }
        if (colaboradores.length) {
            caja.appendChild(parrafo(colaboradores.length === 1
                ? 'Hay un colaborador con ese nombre que aún no tiene cuenta:'
                : 'Hay colaboradores con ese nombre que aún no tienen cuenta:', 'mb-1'));
            colaboradores.forEach(function (c) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'opcion-parecido';
                b.textContent = 'Vincular con colaborador ' + (c.num_empleado ? '#' + c.num_empleado + ' ' : '') + c.nombre_completo
                    + (c.puesto ? ' · ' + c.puesto : '') + (c.sede ? ' · ' + c.sede : '');
                b.addEventListener('click', function () {
                    vincular(form, c);
                    revisar(form);
                });
                caja.appendChild(b);
            });
        }
        var confirmar = form.querySelector('[data-confirmar-homonimo]');
        if (confirmar) {
            confirmar.hidden = !datos.requiere_confirmacion;
            if (!datos.requiere_confirmacion) { confirmar.querySelector('input').checked = false; }
        }
        caja.hidden = !datos.mensaje && colaboradores.length === 0;
    }

    function pintarColaboradores(caja, datos) {
        caja.textContent = '';
        if (!datos.mensaje) { caja.hidden = true; return; }
        caja.appendChild(parrafo(datos.mensaje, 'fw-semibold mb-2'));
        (datos.parecidos || []).forEach(function (c) {
            var linea = document.createElement('div');
            linea.className = 'opcion-parecido homonimo-item';
            linea.textContent = (c.num_empleado ? '#' + c.num_empleado : 'Provisional') + ' · ' + c.nombre_completo
                + (c.puesto ? ' · ' + c.puesto : '') + (c.sede ? ' · ' + c.sede : '');
            caja.appendChild(linea);
        });
        caja.hidden = false;
    }

    function revisar(form) {
        var caja = cajaDe(form);
        if (!caja) { return; }
        var lista = campos(caja);
        var params = new URLSearchParams();
        var usuarios = caja.dataset.homonimosModo === 'usuarios';
        if (usuarios) {
            params.set('nombre', valor(form, 'name'));
            var marca = form.querySelector('[data-campo-dialogo]');
            var m = marca ? /^editar-(\d+)$/.exec(marca.value) : null;
            if (m) { params.set('excluir', m[1]); }
            if (params.get('nombre').length < 3) { limpiar(caja); return; }
        } else {
            lista.forEach(function (n) { params.set(n, valor(form, n)); });
            if (!params.get(lista[0]) || !params.get(lista[1])) { limpiar(caja); return; }
        }
        var turno = ++consecutivo;
        caja.dataset.turno = String(turno);
        fetch(caja.dataset.homonimos + '?' + params.toString(), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { if (!r.ok) { throw new Error(String(r.status)); } return r.json(); })
            .then(function (datos) {
                if (caja.dataset.turno !== String(turno)) { return; } // llegó una respuesta más nueva
                if (usuarios) { pintarUsuarios(caja, datos); } else { pintarColaboradores(caja, datos); }
            })
            .catch(function () { /* sin aviso: el servidor vuelve a revisar al guardar */ });
    }

    function programar(form) {
        var caja = cajaDe(form);
        if (!caja) { return; }
        var id = caja.dataset.homonimos + (form.id || form.action);
        clearTimeout(esperas[id]);
        esperas[id] = setTimeout(function () { revisar(form); }, 450);
    }

    document.addEventListener('input', function (e) {
        var c = e.target;
        if (!c.form || !c.name) { return; }
        var caja = cajaDe(c.form);
        if (caja && campos(caja).indexOf(c.name) !== -1) { programar(c.form); }
    });

    // Al abrir la edición de un usuario: se revisa con el nombre ya cargado
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-accion="editar-registro"]');
        if (!b) { return; }
        setTimeout(function () {
            var d = document.getElementById(b.dataset.dialogo);
            var form = d && d.querySelector('form');
            var caja = cajaDe(form);
            if (caja) { limpiar(caja); revisar(form); }
        }, 0);
    });

    // Al cerrar el diálogo se olvidan el aviso y la confirmación
    document.addEventListener('close', function (e) {
        if (!e.target.querySelector) { return; }
        var caja = e.target.querySelector('[data-homonimos]');
        if (caja) { limpiar(caja); }
    }, true);

    // Un diálogo que regresó con errores: se vuelve a mostrar el aviso
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('dialog[data-abrir-al-cargar] [data-homonimos]').forEach(function (caja) {
            var form = caja.closest('form');
            var confirmar = form.querySelector('[data-confirmar-homonimo]');
            var yaVisible = confirmar && !confirmar.hidden;
            revisar(form);
            if (yaVisible) { confirmar.hidden = false; }
        });
    });
})();
/* Fin Ajustes QA 4 */
/* ==========================================================================
   Pases de salida v2: pestañas de la ficha del pase, "usar mi firma
   guardada", verificación de artículos con el lector universal en la
   caseta, regreso parcial y circuito de aprobación (agregar, mover y quitar
   pasos).
   ========================================================================== */
(function () {
    'use strict';

    /* ---------- Pestañas: Resumen · Artículos · Firmas y bitácora ---------- */
    function activarPestana(grupo, clave) {
        var hay = false;
        grupo.querySelectorAll('[data-pestana-pase]').forEach(function (b) {
            var activa = b.getAttribute('data-pestana-pase') === clave;
            hay = hay || activa;
            b.classList.toggle('activa', activa);
            b.setAttribute('aria-selected', activa ? 'true' : 'false');
        });
        if (!hay) { return false; }
        document.querySelectorAll('[data-panel-pase]').forEach(function (p) {
            p.hidden = p.getAttribute('data-panel-pase') !== clave;
        });
        return true;
    }

    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-pestana-pase]');
        if (!b) { return; }
        var grupo = b.closest('[data-pestanas-pase]');
        if (grupo && activarPestana(grupo, b.getAttribute('data-pestana-pase'))) {
            try { window.history.replaceState(null, '', '#' + b.getAttribute('data-pestana-pase')); } catch (x) { /* navegador antiguo */ }
        }
    });

    /* ---------- Firma propia: guardada o en el recuadro ---------- */
    function sincronizarFirmaPropia(caja) {
        var elegido = caja.querySelector('[data-firma-modo]:checked');
        var nueva = caja.querySelector('[data-firma-nueva]');
        if (!nueva) { return; }
        var dibujar = !elegido || elegido.value === 'nueva';
        nueva.hidden = !dibujar;
        if (dibujar && window.Firma) {
            var recuadro = nueva.querySelector('[data-firma]');
            if (recuadro) { window.Firma.preparar(recuadro); }
        }
    }

    document.addEventListener('change', function (e) {
        if (!e.target.matches || !e.target.matches('[data-firma-modo]')) { return; }
        sincronizarFirmaPropia(e.target.closest('[data-firma-propia]'));
    });

    // Si toca dibujar la firma propia, no se envía vacía
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.matches || !form.matches('[data-form-firma-propia]')) { return; }
        var caja = form.querySelector('[data-firma-propia]');
        if (!caja) { return; }
        var nueva = caja.querySelector('[data-firma-nueva]');
        if (!nueva || nueva.hidden) { return; }
        var valor = nueva.querySelector('[data-firma-valor]');
        if (valor && !valor.value) {
            e.preventDefault();
            e.stopImmediatePropagation();
            var recuadro = nueva.querySelector('[data-firma]');
            recuadro.classList.add('falta');
            recuadro.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }, true);

    /* ---------- Caseta: verificar artículos (escaneando o marcando) ---------- */
    function contar(form) {
        var casillas = form.querySelectorAll('[data-verificado]');
        var conteo = form.querySelector('[data-conteo-verificados]');
        if (!conteo || !casillas.length) { return 0; }
        var marcadas = Array.prototype.filter.call(casillas, function (c) { return c.checked; }).length;
        var faltan = casillas.length - marcadas;
        conteo.textContent = faltan === 0
            ? 'Listo: verificaste los ' + casillas.length + ' artículos.'
            : 'Verificados ' + marcadas + ' de ' + casillas.length + (faltan === 1 ? ' — falta 1.' : ' — faltan ' + faltan + '.');
        conteo.className = 'conteo-verificados ' + (faltan === 0 ? 'completo' : 'falta');
        return faltan;
    }

    function estadoLector(caja, texto, clase) {
        var estado = caja && caja.querySelector('[data-lector-estado]');
        if (!estado) { return; }
        estado.hidden = !texto;
        estado.className = 'lector-estado ' + (clase || '');
        estado.textContent = texto || '';
    }

    document.addEventListener('lector:elegido', function (e) {
        var caja = e.target;
        if (!caja.closest || !caja.closest('[data-lector-verificar]')) { return; }
        var form = caja.closest('form');
        var registro = e.detail || {};
        var fila = form.querySelector('[data-articulo-verificar][data-equipo-id="' + String(registro.id) + '"]');
        if (window.Lector) { window.Lector.limpiar(caja); }
        if (!fila) {
            estadoLector(caja, 'Ese equipo NO está en este pase: no debe salir con él.', 'error');
            return;
        }
        fila.querySelector('[data-verificado]').checked = true;
        var escaneado = fila.querySelector('[data-escaneado]');
        if (escaneado) { escaneado.checked = true; }
        var marca = fila.querySelector('[data-marca-escaneado]');
        if (marca) { marca.hidden = false; }
        var faltan = contar(form);
        estadoLector(caja, 'Verificado: ' + (registro.titulo || 'equipo') + (faltan ? '. Escanea el siguiente.' : '. Ya están todos.'), 'ok');
    });

    document.addEventListener('change', function (e) {
        if (!e.target.matches) { return; }
        if (e.target.matches('[data-verificado]')) {
            // Desmarcar a mano quita la marca de escaneado
            if (!e.target.checked) {
                var fila = e.target.closest('[data-articulo-verificar]');
                var escaneado = fila && fila.querySelector('[data-escaneado]');
                if (escaneado) { escaneado.checked = false; }
                var marca = fila && fila.querySelector('[data-marca-escaneado]');
                if (marca) { marca.hidden = true; }
            }
            contar(e.target.closest('form'));
        }
    });

    // Regreso: si regresan menos de los que faltan, se ofrece cerrar con faltantes
    function revisarRegreso(form) {
        var faltan = 0;
        form.querySelectorAll('[data-cantidad-regreso]').forEach(function (c) {
            var pendientes = parseInt(c.getAttribute('data-pendientes') || '0', 10);
            var valor = parseInt(c.value || '0', 10);
            faltan += Math.max(0, pendientes - (isNaN(valor) ? 0 : valor));
        });
        var opcion = form.querySelector('[data-cerrar-faltantes]');
        if (opcion) {
            opcion.hidden = faltan === 0;
            if (faltan === 0) { opcion.querySelector('input').checked = false; }
        }
    }

    document.addEventListener('input', function (e) {
        if (e.target.matches && e.target.matches('[data-cantidad-regreso]')) { revisarRegreso(e.target.closest('form')); }
    });

    // Salida: no se envía hasta verificar cada artículo
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.matches || !form.matches('[data-form-paso-pase][data-paso="salida"]')) { return; }
        if (contar(form) > 0) {
            e.preventDefault();
            e.stopImmediatePropagation();
            var conteo = form.querySelector('[data-conteo-verificados]');
            if (conteo) { conteo.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
        }
    }, true);

    /* ---------- Circuito de aprobación ---------- */
    function acomodarPaso(paso) {
        var tipo = paso.querySelector('[data-paso-tipo]');
        var depto = paso.querySelector('[data-paso-departamento]');
        paso.querySelectorAll('[data-si-tipo]').forEach(function (c) {
            var ver = tipo && c.getAttribute('data-si-tipo') === tipo.value;
            c.hidden = !ver;
            c.querySelectorAll('input, select').forEach(function (x) { x.disabled = !ver; });
        });
        paso.querySelectorAll('[data-si-departamento]').forEach(function (c) {
            var ver = depto && c.getAttribute('data-si-departamento') === depto.value;
            c.hidden = !ver;
            c.querySelectorAll('input, select').forEach(function (x) { x.disabled = !ver; });
        });
    }

    function numerar(lista) {
        lista.querySelectorAll('[data-paso-circuito]').forEach(function (p, i) {
            var n = p.querySelector('[data-numero-paso]');
            if (n) { n.textContent = String(i + 1); }
        });
    }

    document.addEventListener('change', function (e) {
        if (!e.target.matches || !e.target.matches('[data-paso-tipo], [data-paso-departamento]')) { return; }
        acomodarPaso(e.target.closest('[data-paso-circuito]'));
    });

    document.addEventListener('click', function (e) {
        var form = e.target.closest('[data-form-circuito]');
        if (!form) { return; }
        var lista = form.querySelector('[data-pasos-circuito]');
        if (e.target.closest('[data-agregar-paso-circuito]')) {
            var plantilla = form.querySelector('[data-plantilla-paso-circuito]');
            var i = parseInt(lista.getAttribute('data-siguiente') || '0', 10);
            lista.setAttribute('data-siguiente', String(i + 1));
            var envoltura = document.createElement('div');
            envoltura.innerHTML = plantilla.innerHTML.replace(/__i__/g, String(i));
            var nuevo = envoltura.querySelector('[data-paso-circuito]');
            lista.appendChild(nuevo);
            acomodarPaso(nuevo);
            numerar(lista);
            nuevo.querySelector('input[type="text"]').focus();
            return;
        }
        var paso = e.target.closest('[data-paso-circuito]');
        if (!paso) { return; }
        var mover = e.target.closest('[data-mover-paso]');
        if (mover) {
            if (mover.getAttribute('data-mover-paso') === 'arriba' && paso.previousElementSibling) {
                lista.insertBefore(paso, paso.previousElementSibling);
            } else if (mover.getAttribute('data-mover-paso') === 'abajo' && paso.nextElementSibling) {
                lista.insertBefore(paso.nextElementSibling, paso);
            }
            numerar(lista);
            mover.focus();
            return;
        }
        if (e.target.closest('[data-quitar-paso]')) {
            if (lista.querySelectorAll('[data-paso-circuito]').length <= 1) {
                window.alert('El circuito necesita al menos un paso de aprobación.');
                return;
            }
            paso.remove();
            numerar(lista);
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        var grupo = document.querySelector('[data-pestanas-pase]');
        if (grupo) {
            var hash = (window.location.hash || '').replace('#', '');
            if (!activarPestana(grupo, hash)) { activarPestana(grupo, grupo.getAttribute('data-inicial') || 'resumen'); }
        }
        document.querySelectorAll('[data-firma-propia]').forEach(sincronizarFirmaPropia);
        document.querySelectorAll('[data-form-paso-pase]').forEach(function (f) { contar(f); revisarRegreso(f); });
        document.querySelectorAll('[data-paso-circuito]').forEach(acomodarPaso);
        var lista = document.querySelector('[data-pasos-circuito]');
        if (lista) { numerar(lista); }
    });

    // Al cerrar el diálogo de caseta, la lista vuelve a contarse (las casillas no se borran)
    document.addEventListener('close', function (e) {
        if (!(e.target instanceof HTMLDialogElement)) { return; }
        e.target.querySelectorAll('[data-firma-propia]').forEach(sincronizarFirmaPropia);
    }, true);
})();
/* Fin Pases de salida v2 */
/* ==========================================================================
   Eliminar definitivamente (borrado físico controlado; ver docs/tecnico/borrado.md)
   Botón: [data-borrar-definitivo] data-registro data-id data-url (componentes/borrar).
   1) Pregunta al servidor qué lo usa (GET /borrar/{registro}/{id}).
   2) Si algo depende de él lo explica y ofrece "Dar de baja".
   3) Si no, pide teclear su nombre o identificador y lo borra (DELETE).
   En los diálogos de edición compartidos, el id lo toma del botón "Editar".
   ========================================================================== */
(function () {
    'use strict';

    var esperado = '';

    function normalizar(texto) { return String(texto || '').replace(/\s+/g, ' ').trim().toLowerCase(); }

    function token() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function paso(dialogo, nombre) {
        dialogo.querySelectorAll('[data-borrar-paso]').forEach(function (p) { p.hidden = p.getAttribute('data-borrar-paso') !== nombre; });
    }

    function textos(dialogo, selector, valor) {
        dialogo.querySelectorAll(selector).forEach(function (n) { n.textContent = valor; });
    }

    function mostrarError(dialogo, mensaje) {
        textos(dialogo, '[data-borrar-error]', mensaje);
        paso(dialogo, 'error');
    }

    function prepararBaja(dialogo, baja) {
        var form = dialogo.querySelector('[data-borrar-baja-form]');
        var boton = dialogo.querySelector('[data-borrar-baja-boton]');
        var indicacion = dialogo.querySelector('[data-borrar-baja-texto]');
        var campos = dialogo.querySelector('[data-borrar-baja-campos]');
        campos.textContent = '';
        form.removeAttribute('action');
        boton.hidden = true;
        indicacion.hidden = true;
        if (!baja) { return; }
        if (baja.texto) {
            indicacion.textContent = baja.texto;
            indicacion.hidden = false;
            return;
        }
        form.setAttribute('action', baja.url);
        dialogo.querySelector('[data-borrar-baja-metodo]').value = baja.metodo || 'PATCH';
        Object.keys(baja.campos || {}).forEach(function (nombre) {
            var oculto = document.createElement('input');
            oculto.type = 'hidden';
            oculto.name = nombre;
            oculto.value = String(baja.campos[nombre]);
            campos.appendChild(oculto);
        });
        boton.hidden = false;
    }

    function mostrarDependencias(dialogo, datos) {
        textos(dialogo, '[data-borrar-mensaje]', datos.mensaje || '');
        prepararBaja(dialogo, datos.baja);
        paso(dialogo, 'dependencias');
    }

    function respuesta(r) {
        return r.json().catch(function () { return {}; }).then(function (d) { return { estado: r.status, datos: d }; });
    }

    function abrirConfirmacion(boton) {
        var dialogo = document.getElementById('dialogoBorrarDefinitivo');
        var id = boton.getAttribute('data-id');
        if (!dialogo || !id) { return; }

        var form = dialogo.querySelector('[data-borrar-form]');
        var entrada = dialogo.querySelector('[data-borrar-entrada]');
        var url = boton.getAttribute('data-url') + '/' + encodeURIComponent(id);
        form.setAttribute('data-url', url);
        entrada.value = '';
        esperado = '';
        dialogo.querySelector('[data-borrar-enviar]').disabled = true;
        dialogo.querySelector('[data-borrar-error-form]').hidden = true;
        paso(dialogo, 'cargando');
        if (!dialogo.open && typeof dialogo.showModal === 'function') { dialogo.showModal(); }

        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(respuesta)
            .then(function (res) {
                if (res.estado !== 200) {
                    mostrarError(dialogo, res.estado === 404
                        ? 'No se encontró el registro o está fuera de tus sedes.'
                        : (res.datos.mensaje || res.datos.message || 'No tienes permiso para eliminar este registro.'));
                    return;
                }
                var d = res.datos;
                textos(dialogo, '[data-borrar-nombre]', d.nombre);
                textos(dialogo, '[data-borrar-tipo]', d.tipo);
                textos(dialogo, '[data-borrar-tipo-mayuscula]', d.tipo.charAt(0).toUpperCase() + d.tipo.slice(1));
                textos(dialogo, '[data-borrar-confirmar]', d.confirmar);
                if (!d.puede_eliminar) { mostrarDependencias(dialogo, d); return; }
                esperado = normalizar(d.confirmar);
                form.setAttribute('data-url', d.url);
                paso(dialogo, 'confirmar');
                entrada.focus();
            })
            .catch(function () { mostrarError(dialogo, 'Sin conexión: no se pudo revisar el registro. Intenta de nuevo.'); });
    }

    document.addEventListener('click', function (evento) {
        var boton = evento.target.closest('[data-borrar-definitivo]');
        if (boton) { abrirConfirmacion(boton); return; }

        // Diálogos de edición compartidos: el botón de borrar toma el id del registro que se abrió
        var editar = evento.target.closest('[data-accion="editar-registro"], [data-accion="editar-rol"], [data-accion="editar-ruta"]');
        if (editar) {
            var destino = document.getElementById(editar.dataset.dialogo || 'dialogoEditarRol');
            if (destino) {
                destino.querySelectorAll('[data-borrar-definitivo]').forEach(function (b) { b.setAttribute('data-id', editar.dataset.id || ''); });
            }
        }
    });

    document.addEventListener('input', function (evento) {
        if (!evento.target.matches('[data-borrar-entrada]')) { return; }
        var dialogo = evento.target.closest('dialog');
        dialogo.querySelector('[data-borrar-enviar]').disabled = esperado === '' || normalizar(evento.target.value) !== esperado;
    });

    document.addEventListener('submit', function (evento) {
        var form = evento.target;
        if (!form.matches('[data-borrar-form]')) { return; }
        evento.preventDefault();
        var dialogo = form.closest('dialog');
        var enviar = form.querySelector('[data-borrar-enviar]');
        var error = form.querySelector('[data-borrar-error-form]');
        enviar.disabled = true;
        error.hidden = true;

        fetch(form.getAttribute('data-url'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': token() },
            body: new FormData(form)
        })
            .then(respuesta)
            .then(function (res) {
                if (res.estado === 200 && res.datos.ok) { window.location.href = res.datos.redirect; return; }
                // Alguien lo empezó a usar mientras se confirmaba: se explica y se ofrece cerrar
                if (res.estado === 409) { mostrarDependencias(dialogo, { mensaje: res.datos.mensaje, baja: null }); return; }
                error.textContent = res.datos.mensaje || res.datos.message || 'No se pudo eliminar. Intenta de nuevo.';
                error.hidden = false;
                enviar.disabled = false;
            })
            .catch(function () {
                error.textContent = 'Sin conexión: no se eliminó nada. Intenta de nuevo.';
                error.hidden = false;
                enviar.disabled = false;
            });
    });
})();
/* Fin Eliminar definitivamente */
/* ==========================================================================
   Procedimientos (ver docs/tecnico/procedimientos.md): editor de pasos
   (agregar, subir, bajar, quitar), "Todas las sedes / Sedes elegidas",
   QR escaneado con el lector universal (abre el modo lectura) y tamaño de
   letra del modo lectura (se recuerda en este equipo).
   ========================================================================== */
(function () {
    'use strict';

    /* ---------- Editor de pasos ---------- */
    function renumerar(lista) {
        lista.querySelectorAll('[data-paso-fila]').forEach(function (fila, i) {
            var numero = fila.querySelector('[data-paso-numero]');
            if (numero) { numero.textContent = String(i + 1); }
        });
    }

    function agregarPaso(form) {
        var lista = form.querySelector('[data-pasos-editor]');
        var plantilla = form.querySelector('template[data-plantilla-paso]');
        if (!lista || !plantilla) { return; }
        var indice = parseInt(lista.getAttribute('data-siguiente') || '0', 10);
        lista.setAttribute('data-siguiente', String(indice + 1));
        lista.insertAdjacentHTML('beforeend', plantilla.innerHTML.replace(/__i__/g, String(indice)));
        renumerar(lista);
        var texto = lista.lastElementChild && lista.lastElementChild.querySelector('textarea');
        if (texto) { texto.focus(); }
    }

    document.addEventListener('click', function (e) {
        var boton = e.target.closest('[data-agregar-paso], [data-paso-subir], [data-paso-bajar], [data-paso-quitar]');
        if (!boton) { return; }
        var form = boton.closest('form');
        if (!form) { return; }
        if (boton.hasAttribute('data-agregar-paso')) { agregarPaso(form); return; }

        var fila = boton.closest('[data-paso-fila]');
        var lista = fila && fila.parentElement;
        if (!fila || !lista) { return; }
        if (boton.hasAttribute('data-paso-subir') && fila.previousElementSibling) {
            lista.insertBefore(fila, fila.previousElementSibling);
            boton.focus();
        } else if (boton.hasAttribute('data-paso-bajar') && fila.nextElementSibling) {
            lista.insertBefore(fila.nextElementSibling, fila);
            boton.focus();
        } else if (boton.hasAttribute('data-paso-quitar')) {
            if (lista.querySelectorAll('[data-paso-fila]').length > 1) {
                fila.remove();
            } else {
                // Siempre queda un renglón: solo se vacía
                fila.querySelectorAll('textarea, input[type="text"]').forEach(function (c) { c.value = ''; });
                fila.querySelectorAll('input[type="checkbox"]').forEach(function (c) { c.checked = false; });
            }
        }
        renumerar(lista);
    });

    /* ---------- A quién aplica: todas las sedes o sedes elegidas ---------- */
    function sincronizarSedes(form) {
        var elegido = form.querySelector('[data-aplica-sedes]:checked');
        var caja = form.querySelector('[data-sedes-elegidas]');
        if (!caja) { return; }
        caja.hidden = !!elegido && elegido.value !== 'sedes';
    }

    document.addEventListener('change', function (e) {
        if (e.target.matches && e.target.matches('[data-aplica-sedes]')) { sincronizarSedes(e.target.form); }
    });

    // Al enviar, los pasos van en el orden en que se ven en pantalla
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.matches || !form.matches('[data-form-procedimiento]')) { return; }
        var lista = form.querySelector('[data-pasos-editor]');
        if (!lista) { return; }
        lista.querySelectorAll('[data-paso-fila]').forEach(function (fila, i) {
            fila.querySelectorAll('[name^="pasos["]').forEach(function (c) {
                c.name = c.name.replace(/^pasos\[[^\]]*\]/, 'pasos[' + i + ']');
            });
        });
    });

    /* ---------- QR de la hoja leído con el lector universal: abre el modo lectura ---------- */
    document.addEventListener('lector:elegido', function (e) {
        var caja = e.target;
        if (!caja.closest || !caja.closest('[data-lector-procedimiento]')) { return; }
        var registro = e.detail || {};
        if (registro.url) { window.location.href = registro.url; }
    });

    /* ---------- Modo lectura: tamaño de letra (0 a 3) ---------- */
    var CLAVE_LETRA = 'procedimientos.letra';

    function aplicarLetra(nivel) {
        var lectura = document.querySelector('[data-modo-lectura]');
        if (!lectura) { return; }
        nivel = Math.max(0, Math.min(3, isNaN(nivel) ? 1 : nivel));
        lectura.setAttribute('data-letra-nivel', String(nivel));
        try { window.localStorage.setItem(CLAVE_LETRA, String(nivel)); } catch (x) { /* sin almacenamiento */ }
    }

    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-letra]');
        if (!b) { return; }
        var lectura = document.querySelector('[data-modo-lectura]');
        var actual = parseInt((lectura && lectura.getAttribute('data-letra-nivel')) || '1', 10);
        aplicarLetra(actual + parseInt(b.getAttribute('data-letra'), 10));
    });

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-form-procedimiento]').forEach(sincronizarSedes);
        if (document.querySelector('[data-modo-lectura]')) {
            var guardado = null;
            try { guardado = window.localStorage.getItem(CLAVE_LETRA); } catch (x) { guardado = null; }
            aplicarLetra(guardado === null ? 1 : parseInt(guardado, 10));
        }
    });
})();
/* Fin Procedimientos */
/* ==========================================================================
   Altas por verificar (ADR-0006; ver docs/tecnico/altas-por-verificar.md)
   1. Altas rápidas desde Operación (vehículo, persona, empresa externa:
      <form data-alta-padron data-url-parecidos>): antes de crear se pregunta
      "¿Es alguno de estos?" con los parecidos del padrón. Elegir uno lanza el
      mismo evento que el registro (vehiculo:registrado, persona:registrada,
      proveedor:registrado); "No es ninguno" registra el nuevo (pendiente de
      verificar si quien registra no edita el padrón).
   2. Registro Inteligente de Ingreso: cuando la búsqueda de placas, nombre o
      empresa no encuentra nada, se agregan los parecidos (sin mayúsculas,
      acentos, espacios ni guiones) para elegir uno con un toque.
   3. Lista de cada padrón: píldora "Pendientes de verificar" y diálogo
      "Verificar Alta Pendiente" (Es correcto / Ya existía / Rechazar).
   ========================================================================== */
(function () {
    'use strict';

    var EVENTOS = { vehiculos: 'vehiculo:registrado', personas: 'persona:registrada', proveedores: 'proveedor:registrado' };

    function nodo(etiqueta, clase, texto) {
        var e = document.createElement(etiqueta);
        if (clase) { e.className = clase; }
        if (texto !== undefined && texto !== null) { e.textContent = texto; }
        return e;
    }

    function pedirJson(url) {
        return fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { if (!r.ok) { throw new Error(String(r.status)); } return r.json(); });
    }

    function detalleDe(r) {
        return [r.detalle, r.estado_texto].filter(Boolean).join(' · ');
    }

    /* ---------- 1. Altas rápidas: "¿Es alguno de estos?" antes de crear ---------- */
    function urlParecidos(form) {
        var p = new URLSearchParams();
        p.set('padron', form.dataset.altaPadron);
        var origen = form.querySelector('[name="origen"]');
        if (origen && origen.value) { p.set('origen', origen.value); }
        ['placas', 'nombre', 'nombre_completo', 'folio_identificacion'].forEach(function (n) {
            var c = form.querySelector('[name="' + n + '"]');
            if (c && c.value.trim()) { p.set(n, c.value.trim()); }
        });
        return form.dataset.urlParecidos + '?' + p.toString();
    }

    function confirmar(form, valor) {
        var c = form.querySelector('[data-alta-confirmar]');
        if (c) { c.value = valor; }
    }

    function ocultarParecidos(form) {
        var caja = form.querySelector('[data-alta-parecidos]');
        if (caja) { caja.hidden = true; caja.textContent = ''; }
    }

    function usar(form, r) {
        var padron = form.dataset.altaPadron;
        var detalle = Object.assign({}, r);
        if (padron === 'proveedores') { detalle.ya_existia = true; }
        ocultarParecidos(form);
        confirmar(form, '0');
        form.reset();
        var dialogo = form.closest('dialog');
        if (dialogo) { dialogo.close(); }
        document.dispatchEvent(new CustomEvent(EVENTOS[padron], { detail: detalle }));
    }

    function mostrarParecidos(form, lista) {
        var caja = form.querySelector('[data-alta-parecidos]');
        if (!caja) { return; }
        caja.textContent = '';
        caja.appendChild(nodo('p', 'fw-semibold mb-2', form.dataset.altaPadron === 'vehiculos'
            ? '¿Es alguno de estos? Hay placas parecidas en el padrón.'
            : '¿Es alguno de estos? Ya hay registros parecidos en el padrón.'));
        lista.forEach(function (r) {
            var b = nodo('button', 'opcion-parecido');
            b.type = 'button';
            b.appendChild(nodo('strong', '', r.titulo));
            var d = detalleDe(r);
            if (d) { b.appendChild(nodo('small', 'd-block', d)); }
            if (!r.usable) {
                b.disabled = true;
                b.appendChild(nodo('small', 'd-block text-danger', 'Dado de baja: pide que lo reactiven en el padrón.'));
            } else {
                b.addEventListener('click', function () { usar(form, r); });
            }
            caja.appendChild(b);
        });
        var nuevo = nodo('button', 'btn btn-outline-dark fw-bold mt-2 btn-alta-nuevo', 'No es ninguno: registrarlo como nuevo');
        nuevo.type = 'button';
        nuevo.addEventListener('click', function () {
            confirmar(form, '1');
            ocultarParecidos(form);
            form.requestSubmit();
        });
        caja.appendChild(nuevo);
        caja.hidden = false;
        caja.scrollIntoView({ block: 'nearest' });
    }

    // Fase de captura: se revisa antes que el registro rápido de cada padrón
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.matches || !form.matches('form[data-alta-padron][data-url-parecidos]')) { return; }
        var c = form.querySelector('[data-alta-confirmar]');
        if (c && c.value === '1') { return; }
        e.preventDefault();
        e.stopImmediatePropagation();
        var boton = form.querySelector('button[type="submit"]');
        if (boton) { boton.disabled = true; }
        pedirJson(urlParecidos(form))
            .then(function (d) {
                if (boton) { boton.disabled = false; }
                var lista = d.resultados || [];
                if (lista.length) { mostrarParecidos(form, lista); return; }
                confirmar(form, '1');
                form.requestSubmit();
            })
            .catch(function () {
                // Sin respuesta: el servidor vuelve a revisar al registrar
                if (boton) { boton.disabled = false; }
                form.dataset.altaSinRevision = '1';
                confirmar(form, '0');
                form.removeAttribute('data-url-parecidos');
                form.requestSubmit();
            });
    }, true);

    // Si cambia lo capturado, se vuelve a revisar
    document.addEventListener('input', function (e) {
        var form = e.target.form;
        if (!form || !form.matches('form[data-alta-padron]')) { return; }
        if (['placas', 'nombre', 'nombre_completo', 'folio_identificacion'].indexOf(e.target.name) !== -1) {
            confirmar(form, '0');
            ocultarParecidos(form);
        }
    });

    // El servidor también pregunta (409 con "parecidos") si algo cambió entre la revisión y el registro
    document.addEventListener('close', function (e) {
        if (!(e.target instanceof HTMLDialogElement)) { return; }
        e.target.querySelectorAll('form[data-alta-padron]').forEach(function (form) {
            confirmar(form, '0');
            ocultarParecidos(form);
        });
    }, true);

    /* ---------- 2. Registro Inteligente de Ingreso: parecidos cuando no hay coincidencias ---------- */
    var cache = {};

    function urlSugerencias() {
        var s = document.querySelector('[data-alta-sugerencias]');
        return s ? s.dataset.altaSugerencias : '';
    }

    function contextoDe(caja) {
        var env = caja.closest('[data-sugerir="vehiculo"]');
        if (env) { return { padron: 'vehiculos', campo: 'placas', env: env, entrada: env.querySelector('[data-lector-entrada]') }; }
        env = caja.closest('[data-sugerir-persona]');
        if (env) { return { padron: 'personas', campo: 'nombre_completo', env: env, entrada: env.querySelector('[data-nombre-titular]') }; }
        env = caja.closest('[data-sugerir-proveedor]');
        if (env) { return { padron: 'proveedores', campo: 'nombre', env: env, entrada: env.querySelector('input[type="text"]') }; }
        return null;
    }

    function elegirEnIngreso(ctx, caja, r) {
        caja.hidden = true;
        caja.textContent = '';
        if (ctx.padron === 'vehiculos') {
            var lector = ctx.env.querySelector('[data-lector]');
            if (lector && window.Lector) { window.Lector.elegir(lector, { id: r.id, titulo: r.placas, detalle: detalleDe(r), activo: true }); }
            var placas = ctx.env.querySelector('[data-placas]');
            if (placas) { placas.value = r.placas; }
            var form = ctx.env.closest('form');
            ['tipo', 'marca', 'modelo', 'color'].forEach(function (campo) {
                var c = form && form.querySelector('[data-vehiculo-campo="' + campo + '"]');
                if (c && r[campo]) { c.value = r[campo]; }
            });
            return;
        }
        if (ctx.padron === 'personas') {
            document.dispatchEvent(new CustomEvent('persona:registrada', { detail: r }));
            return;
        }
        ctx.entrada.value = (r.nombre || '').toUpperCase();
        var oculto = ctx.env.querySelector('[data-proveedor-id]');
        if (oculto) { oculto.value = r.id; }
    }

    function agregarParecidos(caja, ctx, texto, lista) {
        if (!lista.length || caja.querySelector('[data-alta-parecido]')) { return; }
        var titulo = nodo('div', 'acceso-sugerencia encabezado-parecidos', '¿Es alguno de estos? (parecidos en el padrón)');
        titulo.setAttribute('data-alta-parecido', '');
        caja.appendChild(titulo);
        lista.forEach(function (r) {
            var b = nodo('button', 'acceso-sugerencia parecido');
            b.type = 'button';
            b.setAttribute('data-alta-parecido', '');
            b.appendChild(nodo('strong', '', r.titulo));
            var d = detalleDe(r);
            if (d) { b.appendChild(nodo('small', '', d)); }
            if (!r.usable) {
                b.disabled = true;
            } else {
                b.addEventListener('click', function () { elegirEnIngreso(ctx, caja, r); });
            }
            caja.appendChild(b);
        });
        var nota = nodo('div', 'acceso-sugerencia nada', 'Si no es ninguno, sigue: se registra como nuevo y queda pendiente de verificar.');
        nota.setAttribute('data-alta-parecido', '');
        caja.appendChild(nota);
        caja.hidden = false;
    }

    function revisarCaja(caja) {
        if (caja.hidden || !caja.querySelector('.acceso-sugerencia.nada:not([data-alta-parecido])') || caja.querySelector('[data-alta-parecido]')) { return; }
        var ctx = contextoDe(caja);
        var url = urlSugerencias();
        if (!ctx || !ctx.entrada || !url) { return; }
        var texto = ctx.entrada.value.trim();
        if (texto.length < 3) { return; }
        var clave = ctx.padron + '|' + texto.toUpperCase();
        if (cache[clave]) { agregarParecidos(caja, ctx, texto, cache[clave]); return; }
        var p = new URLSearchParams({ padron: ctx.padron, origen: 'accesos' });
        p.set(ctx.campo, texto);
        pedirJson(url + '?' + p.toString()).then(function (d) {
            cache[clave] = d.resultados || [];
            // Solo si sigue diciendo "sin coincidencias" para el mismo texto
            if (ctx.entrada.value.trim() === texto) { agregarParecidos(caja, ctx, texto, cache[clave]); }
        }).catch(function () { /* sin parecidos */ });
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (typeof MutationObserver === 'undefined' || !urlSugerencias()) { return; }
        document.querySelectorAll('[data-dialogo-ingreso] [data-sugerencias]').forEach(function (caja) {
            new MutationObserver(function () { revisarCaja(caja); }).observe(caja, { childList: true, attributes: true, attributeFilter: ['hidden'] });
        });
    });

    /* ---------- 3. Lista del padrón: píldora "Pendientes de verificar" ---------- */
    function filtrarPendientes(boton) {
        var on = boton.getAttribute('aria-pressed') !== 'true';
        boton.setAttribute('aria-pressed', String(on));
        boton.classList.toggle('active', on);
        document.querySelectorAll('.fichas-grid .ficha-card').forEach(function (f) {
            if (f.classList.contains('ficha-create')) { f.classList.toggle('oculta-por-verificacion', on); return; }
            f.classList.toggle('oculta-por-verificacion', on && !f.querySelector('[data-insignia-verificacion="pendiente"]'));
        });
    }

    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-filtro-verificacion]');
        if (b) { filtrarPendientes(b); }
    });

    document.addEventListener('DOMContentLoaded', function () {
        var b = document.querySelector('[data-filtro-verificacion][data-encender]');
        if (b) { filtrarPendientes(b); }
    });

    /* ---------- 3. Diálogo "Verificar Alta Pendiente" ---------- */
    var actual = null;
    var espera = null;

    function dialogo() { return document.querySelector('[data-dialogo-verificar]'); }

    function camino(valor) {
        var d = dialogo();
        if (!d) { return; }
        d.querySelectorAll('[data-va-form]').forEach(function (f) { f.hidden = f.dataset.vaForm !== valor; });
        d.querySelectorAll('[data-va-camino]').forEach(function (r) { r.checked = r.value === valor; });
        errores([]);
    }

    function errores(lista) {
        var caja = dialogo() && dialogo().querySelector('[data-va-errores]');
        if (!caja) { return; }
        caja.textContent = '';
        lista.forEach(function (m) { caja.appendChild(nodo('div', '', m)); });
        caja.hidden = lista.length === 0;
    }

    function opcionUnir(r) {
        var label = nodo('label', 'opcion-unir');
        var radio = document.createElement('input');
        radio.type = 'radio';
        radio.name = 'destino_id';
        radio.value = r.id;
        label.appendChild(radio);
        var texto = nodo('span', '');
        texto.appendChild(nodo('strong', 'd-block', r.titulo));
        var d = detalleDe(r);
        if (d) { texto.appendChild(nodo('small', 'd-block', d)); }
        label.appendChild(texto);
        return label;
    }

    function pintarLista(caja, lista, vacio) {
        caja.textContent = '';
        if (!lista.length) { if (vacio) { caja.appendChild(nodo('p', 'campo-ayuda m-0', vacio)); } return; }
        lista.forEach(function (r) { caja.appendChild(opcionUnir(r)); });
    }

    // La búsqueda de cada padrón responde su propio resumen; se lleva a título y detalle
    function comoParecido(r) {
        var titulo = r.placas || r.nombre_completo || r.nombre || '';
        var detalle = [r.descripcion, r.propiedad_etiqueta, r.tipo_etiqueta, r.empresa, r.categoria_etiqueta].filter(Boolean).join(' · ');
        return { id: r.id, titulo: titulo, detalle: detalle, usable: r.activo !== false, verificacion: r.verificacion || 'verificado' };
    }

    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-verificar-alta]');
        var d = dialogo();
        if (!b || !d) { return; }
        try { actual = JSON.parse(b.dataset.verificarAlta); } catch (x) { return; }
        d.querySelector('[data-va-titulo]').textContent = actual.titulo;
        d.querySelector('[data-va-registro]').textContent = actual.registro || '';
        d.querySelectorAll('[data-va-form]').forEach(function (f) { f.action = actual[f.dataset.vaForm]; });
        var aceptar = d.querySelector('[data-va-form="aceptar"]');
        Object.keys(actual.valores || {}).forEach(function (campo) {
            var c = aceptar.querySelector('[name="' + campo + '"]');
            if (c) { c.value = actual.valores[campo] === null ? '' : String(actual.valores[campo]); }
        });
        var parecidos = d.querySelector('[data-va-parecidos]');
        pintarLista(parecidos, [], null);
        parecidos.appendChild(nodo('p', 'campo-ayuda m-0', 'Buscando parecidos...'));
        pintarLista(d.querySelector('[data-va-resultados]'), [], null);
        var buscar = d.querySelector('[data-va-buscar]');
        if (buscar) { buscar.value = ''; }
        camino('aceptar');
        pedirJson(actual.parecidos).then(function (r) {
            pintarLista(parecidos, r.resultados || [], 'No hay registros parecidos. Si sabes cuál es, búscalo abajo.');
        }).catch(function () { pintarLista(parecidos, [], 'No se pudieron buscar parecidos: búscalo abajo.'); });
        if (typeof d.showModal === 'function') { d.showModal(); }
    });

    document.addEventListener('change', function (e) {
        if (e.target.matches && e.target.matches('[data-va-camino]')) { camino(e.target.value); }
    });

    document.addEventListener('input', function (e) {
        if (!e.target.matches || !e.target.matches('[data-va-buscar]')) { return; }
        var d = dialogo();
        var caja = d.querySelector('[data-va-resultados]');
        var texto = e.target.value.trim();
        clearTimeout(espera);
        if (texto.length < 2) { pintarLista(caja, [], null); return; }
        espera = setTimeout(function () {
            pedirJson(d.dataset.urlBuscar + '?q=' + encodeURIComponent(texto)).then(function (r) {
                var lista = (r.resultados || []).map(comoParecido).filter(function (x) {
                    return x.usable && x.verificacion === 'verificado' && actual && String(x.id) !== String(actual.id);
                });
                pintarLista(caja, lista.slice(0, 8), 'Sin resultados activos y verificados con ese texto.');
            }).catch(function () { pintarLista(caja, [], 'No se pudo buscar en este momento.'); });
        }, 300);
    });

    function irA(url) {
        var destino = new URL(url, location.href);
        if (destino.pathname + destino.search === location.pathname + location.search) {
            location.hash = destino.hash;
            location.reload();
            return;
        }
        location.href = destino.href;
    }

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.matches || !form.matches('[data-va-form]')) { return; }
        e.preventDefault();
        if (form.dataset.vaForm === 'unir' && !form.querySelector('[name="destino_id"]:checked')) {
            errores(['Elige con cuál registro se une (de los parecidos o de la búsqueda).']);
            return;
        }
        var boton = form.querySelector('button[type="submit"]');
        if (boton) { boton.disabled = true; }
        errores([]);
        fetch(form.action, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData(form)
        })
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { return { estado: r.status, datos: d }; }); })
            .then(function (res) {
                if (boton) { boton.disabled = false; }
                if (res.estado === 200 && res.datos.ok) { irA(res.datos.ir); return; }
                if (res.estado === 403 || res.estado === 404) { errores(['No tienes permiso para verificar este registro o ya no existe. Recarga la pantalla.']); return; }
                var lista = [];
                Object.keys(res.datos.errors || {}).forEach(function (k) { lista = lista.concat(res.datos.errors[k]); });
                errores(lista.length ? lista : [res.datos.message || 'No se pudo guardar. Intenta de nuevo.']);
            })
            .catch(function () {
                if (boton) { boton.disabled = false; }
                errores(['No se pudo guardar en este momento. Revisa tu conexión e intenta de nuevo.']);
            });
    });
})();
/* Fin Altas por verificar */
