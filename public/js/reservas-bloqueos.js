/*
 * Reservas de áreas — «Bloquear un sitio» (docs/tecnico/reservas.md, «Bloqueos»).
 *  - Al cambiar el sitio, los días o las horas: cuántas reservas caen en el
 *    bloqueo («Hay 3 reservas en esas fechas; se avisará a sus solicitantes»)
 *    y cuáles. Así se ve el impacto antes de guardar; el servidor lo vuelve a
 *    revisar y, si apareció otra reserva, lo muestra antes de bloquear.
 *  - «Todo el día» oculta las horas; «Hasta el día» nunca queda antes de «Desde».
 * Sin JavaScript en línea (CSP): este archivo se carga solo en el formulario.
 */
(function () {
    'use strict';

    var form = document.querySelector('[data-form-bloqueo]');
    if (!form) { return; }

    var url = form.getAttribute('data-impacto-url');
    var caja = form.querySelector('[data-bloqueo-impacto]');
    var texto = form.querySelector('[data-bloqueo-impacto-texto]');
    var lista = form.querySelector('[data-bloqueo-impacto-lista]');
    var visto = form.querySelector('[data-bloqueo-impacto-visto]');
    var todoElDia = form.querySelector('[data-bloqueo-todo-el-dia]');
    var desde = form.querySelector('#fecha_desde');
    var hasta = form.querySelector('#fecha_hasta');

    function pintarHoras() {
        var ocultar = todoElDia && todoElDia.checked;
        form.querySelectorAll('[data-bloqueo-hora]').forEach(function (el) { el.hidden = ocultar; });
    }

    var consulta = 0;
    var espera = null;
    function revisar() {
        if (!url) { return; }
        var mia = ++consulta;
        var datos = new URLSearchParams();
        ['reserva_area_id', 'fecha_desde', 'hora_desde', 'fecha_hasta', 'hora_hasta'].forEach(function (n) {
            var el = form.elements[n];
            if (el) { datos.append(n, el.value); }
        });
        datos.append('todo_el_dia', todoElDia && todoElDia.checked ? '1' : '0');
        fetch(url + '?' + datos.toString(), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (r) {
                if (mia !== consulta || !r) { return; }
                lista.textContent = '';
                if (!r.valido) {
                    caja.hidden = true;
                    visto.value = '-1';
                    return;
                }
                visto.value = String(r.total);
                texto.textContent = r.texto;
                (r.reservas || []).forEach(function (linea) {
                    var li = document.createElement('li');
                    li.textContent = linea;
                    lista.appendChild(li);
                });
                caja.hidden = false;
            })
            .catch(function () { /* sin red: el servidor revisa al guardar */ });
    }
    function programar() {
        clearTimeout(espera);
        espera = setTimeout(revisar, 250);
    }

    if (desde && hasta) {
        desde.addEventListener('change', function () {
            if (!hasta.value || hasta.value < desde.value) { hasta.value = desde.value; }
        });
    }
    if (todoElDia) { todoElDia.addEventListener('change', pintarHoras); }
    form.querySelectorAll('[data-bloqueo-campo]').forEach(function (el) { el.addEventListener('change', programar); });

    pintarHoras();
    revisar();
})();
