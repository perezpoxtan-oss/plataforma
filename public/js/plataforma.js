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
   Bitácora de transporte (seguridad/transporte): diálogo "Registrar Bitácora
   Logística" y "Editar Registro".
   - La lista "Ruta" se filtra por sede y tipo (llegada / salida) y propone el
     horario más cercano a la hora actual de la sede (data-sugerencias).
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
        var sugerencias = leer(dialogo && dialogo.getAttribute('data-sugerencias'), {});

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
