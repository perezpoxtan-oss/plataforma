/*
 * Reservas de áreas (docs/tecnico/reservas.md): formulario de reserva.
 *  - Cascada: primero la zona (o el área) y después el espacio final (una
 *    habitación o sala de la zona, o «Toda la zona»). Si la zona tiene un solo
 *    espacio se elige solo.
 *  - Al elegir el área: montajes con su aforo, tarifas, horario, montaje y
 *    desmontaje, foto y descripción.
 *  - Al elegir área y día: horarios ocupados (incluye montaje y desmontaje).
 *  - Uso interno / Para un cliente: muestra los datos del cliente, la tarifa
 *    y el costo estimado (el servidor calcula el definitivo).
 *  - Cantidad de un servicio vacía: al tocarla se sugiere (por persona = las
 *    personas; por hora = las horas; por evento o pieza = 1).
 * Sin JavaScript en línea (CSP): este archivo se carga solo en el formulario.
 */
(function () {
    'use strict';

    var form = document.querySelector('[data-form-reserva]');
    if (!form) { return; }

    var areas = [];
    try { areas = JSON.parse(form.getAttribute('data-areas') || '[]'); } catch (e) { areas = []; }
    var iva = parseFloat(form.getAttribute('data-iva') || '0') || 0;
    var moneda = form.getAttribute('data-moneda') || 'MXN';
    var urlDisponibilidad = form.getAttribute('data-disponibilidad');
    var excepto = form.getAttribute('data-excepto') || '';

    var selArea = form.querySelector('[data-res-area]');
    var fecha = form.querySelector('[data-res-fecha]');
    var inicio = form.querySelector('[data-res-inicio]');
    var fin = form.querySelector('[data-res-fin]');
    var asistentes = form.querySelector('[data-res-asistentes]');
    var selMontaje = form.querySelector('[data-res-montaje]');
    var selTarifa = form.querySelector('[data-res-tarifa]');
    var cajaOcupado = form.querySelector('[data-res-ocupado]');
    var cajaInfo = form.querySelector('[data-res-info]');
    var cajaCliente = form.querySelector('[data-res-cliente]');
    var cajaCosto = form.querySelector('[data-res-resumen-costo]');
    var textoCosto = form.querySelector('[data-res-costo]');
    var selZona = form.querySelector('[data-res-zona]');
    var campoEspacio = form.querySelector('[data-res-espacio-campo]');
    var opcionesEspacio = Array.prototype.slice.call(selArea.querySelectorAll('option[data-zona]'));
    var nombresTarifa = { hora: 'Por hora (cada hora o fracción)', medio_dia: 'Medio día', dia: 'Día completo', evento: 'Por evento' };

    function area() {
        var id = parseInt(selArea.value, 10);
        for (var i = 0; i < areas.length; i++) { if (areas[i].id === id) { return areas[i]; } }
        return null;
    }

    // Deja en «Espacio» solo los de la zona elegida (sin JavaScript se ven todos)
    function pintarZona() {
        if (!selZona) { return; }
        selZona.required = true;
        var zona = selZona.value;
        var previo = selArea.value;
        var suyas = opcionesEspacio.filter(function (o) { return o.getAttribute('data-zona') === zona; });
        while (selArea.options.length > 1) { selArea.remove(1); }
        selArea.querySelectorAll('optgroup').forEach(function (g) { g.remove(); });
        suyas.forEach(function (o) { selArea.appendChild(o); });
        if (suyas.length === 1) {
            selArea.value = suyas[0].value;
        } else {
            selArea.value = suyas.some(function (o) { return o.value === previo; }) ? previo : '';
        }
        campoEspacio.hidden = suyas.length <= 1;
    }

    function paraCliente() {
        var marcado = form.querySelector('[data-res-para]:checked');
        return marcado ? marcado.value === '1' : false;
    }

    function dinero(n) {
        return '$' + n.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + moneda;
    }

    function opcion(valor, texto, elegido) {
        var o = document.createElement('option');
        o.value = valor;
        o.textContent = texto;
        if (elegido) { o.selected = true; }
        return o;
    }

    function horas() {
        if (!inicio.value || !fin.value) { return 0; }
        var a = inicio.value.split(':'), b = fin.value.split(':');
        var min = (parseInt(b[0], 10) * 60 + parseInt(b[1], 10)) - (parseInt(a[0], 10) * 60 + parseInt(a[1], 10));
        return min > 0 ? Math.ceil(min / 60) : 0;
    }

    function pintarArea() {
        var a = area();
        var previoMontaje = selMontaje.getAttribute('data-valor') || selMontaje.value;
        selMontaje.innerHTML = '';
        selMontaje.appendChild(opcion('', 'Sin montaje especial', false));
        var previoTarifa = selTarifa.getAttribute('data-valor') || selTarifa.value;
        selTarifa.innerHTML = '';
        if (!a) {
            cajaInfo.hidden = true;
            return;
        }
        a.montajes.forEach(function (m) {
            selMontaje.appendChild(opcion(String(m.id), m.nombre + (m.aforo ? ' (hasta ' + m.aforo + ')' : ''), String(m.id) === String(previoMontaje)));
        });
        selMontaje.setAttribute('data-valor', '');
        var claves = Object.keys(a.tarifas || {});
        claves.forEach(function (k) {
            selTarifa.appendChild(opcion(k, (nombresTarifa[k] || k) + ' · ' + dinero(a.tarifas[k]), k === previoTarifa));
        });
        if (claves.length === 0) { selTarifa.appendChild(opcion('', 'Esta área no tiene tarifa (solo servicios)', true)); }
        selTarifa.setAttribute('data-valor', '');

        var partes = [];
        if (a.aforo) { partes.push('Aforo máximo: ' + a.aforo + ' personas.'); }
        if (a.montaje || a.desmontaje) { partes.push('Bloquea ' + a.montaje + ' min antes (montaje) y ' + a.desmontaje + ' min después (desmontaje).'); }
        if (a.inmediata) { partes.push('Sin servicios ni costo, se confirma al momento.'); }
        partes.push('Horario: ' + a.horario.join(' · ') + '.');
        cajaInfo.textContent = '';
        if (a.foto) {
            var img = document.createElement('img');
            img.src = a.foto;
            img.alt = 'Foto de ' + a.nombre;
            img.className = 'res-foto mb-2';
            cajaInfo.appendChild(img);
        }
        if (a.descripcion) {
            var p = document.createElement('p');
            p.className = 'mb-1';
            p.textContent = a.descripcion;
            cajaInfo.appendChild(p);
        }
        var info = document.createElement('div');
        info.textContent = partes.join(' ');
        cajaInfo.appendChild(info);
        cajaInfo.hidden = false;
    }

    var consulta = 0;
    function pintarOcupado() {
        var a = area();
        if (!a || !fecha.value || !urlDisponibilidad) { return; }
        var mia = ++consulta;
        var url = urlDisponibilidad + '?area=' + encodeURIComponent(a.id) + '&fecha=' + encodeURIComponent(fecha.value) + (excepto ? '&excepto=' + encodeURIComponent(excepto) : '');
        fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (datos) {
                if (mia !== consulta || !datos) { return; }
                cajaOcupado.textContent = '';
                var titulo = document.createElement('div');
                titulo.innerHTML = '<i class="bi bi-clock" aria-hidden="true"></i> ';
                titulo.appendChild(document.createTextNode('Horario del día: ' + datos.horario + '.'));
                cajaOcupado.appendChild(titulo);
                if (!datos.ocupado.length) {
                    var libre = document.createElement('div');
                    libre.className = 'text-success fw-semibold';
                    libre.textContent = 'Libre todo el día.';
                    cajaOcupado.appendChild(libre);
                    return;
                }
                var ul = document.createElement('ul');
                datos.ocupado.forEach(function (o) {
                    var li = document.createElement('li');
                    li.textContent = 'Ocupado de ' + o.desde + ' a ' + o.hasta + (o.desde !== o.inicio || o.hasta !== o.fin ? ' (evento de ' + o.inicio + ' a ' + o.fin + ' más montaje y desmontaje)' : '')
                        + (o.otro ? ' · reservado «' + o.otro + '», que comparte lugar con este espacio' : '');
                    ul.appendChild(li);
                });
                cajaOcupado.appendChild(ul);
            })
            .catch(function () { /* sin red: el servidor valida al guardar */ });
    }

    function pintarCliente() {
        var cliente = paraCliente();
        cajaCliente.hidden = !cliente;
        cajaCosto.hidden = !cliente;
        form.querySelectorAll('[data-res-solo-cliente]').forEach(function (el) { el.hidden = !cliente; });
        var nombre = form.querySelector('#cliente_nombre');
        if (nombre) { nombre.required = cliente; }
        calcular();
    }

    function calcular() {
        if (!paraCliente()) { return; }
        var a = area();
        if (!a) { textoCosto.textContent = 'Elige el área, el horario y la tarifa.'; return; }
        var t = selTarifa.value, importeArea = 0;
        if (t && a.tarifas[t] !== undefined) {
            importeArea = t === 'hora' ? a.tarifas[t] * Math.max(1, horas()) : a.tarifas[t];
        }
        var servicios = 0;
        form.querySelectorAll('[data-res-servicio]').forEach(function (inp) {
            var c = parseFloat(inp.value || '0') || 0;
            servicios += c * (parseFloat(inp.getAttribute('data-costo') || '0') || 0);
        });
        var subtotal = importeArea + servicios;
        var impuesto = Math.round(subtotal * iva) / 100;
        textoCosto.textContent = 'Área ' + dinero(importeArea) + ' + servicios ' + dinero(servicios)
            + (iva > 0 ? ' + IVA ' + iva + ' % ' + dinero(impuesto) : '') + ' = ' + dinero(subtotal + impuesto);
        textoCosto.className = 'res-total';
    }

    // Sugerencia de cantidad (criterio 2: un dato se captura una vez)
    form.addEventListener('focusin', function (e) {
        var inp = e.target.closest('[data-res-servicio]');
        if (!inp || inp.value !== '') { return; }
        var unidad = inp.getAttribute('data-unidad');
        var sugerida = unidad === 'persona' ? (parseInt(asistentes.value, 10) || '') : (unidad === 'hora' ? (horas() || '') : 1);
        if (sugerida !== '') {
            inp.value = sugerida;
            inp.select();
            calcular();
        }
    });

    selArea.addEventListener('change', function () { pintarArea(); pintarOcupado(); calcular(); });
    if (selZona) {
        selZona.addEventListener('change', function () { pintarZona(); pintarArea(); pintarOcupado(); calcular(); });
    }
    fecha.addEventListener('change', pintarOcupado);
    [inicio, fin, selTarifa].forEach(function (el) { el.addEventListener('change', calcular); });
    form.addEventListener('input', function (e) { if (e.target.closest('[data-res-servicio]')) { calcular(); } });
    form.querySelectorAll('[data-res-para]').forEach(function (r) { r.addEventListener('change', pintarCliente); });

    pintarZona();
    pintarArea();
    pintarOcupado();
    pintarCliente();
})();
