/*
 * Respaldos y traslado / Exportar empresa: mientras un paquete se prepara,
 * la página pide la siguiente parte al servidor (cada parte dura unos
 * segundos, para no pasarse del tiempo del hospedaje). Al terminar, recarga.
 * Sin JavaScript queda el botón «Continuar».
 */
(function () {
    'use strict';

    var caja = document.getElementById('avance-paquete');
    if (!caja) { return; }

    var url = caja.getAttribute('data-continuar');
    var barra = caja.querySelector('[data-avance-barra]');
    var paso = caja.querySelector('[data-avance-paso]');
    var tamano = caja.querySelector('[data-avance-tamano]');
    var aviso = caja.querySelector('[data-avance-aviso]');
    var boton = caja.querySelector('[data-avance-boton]');
    var fallas = 0;

    function token() {
        var m = document.querySelector('meta[name="csrf-token"]');
        if (m) { return m.content; }
        var campo = caja.querySelector('input[name="_token"]');
        return campo ? campo.value : '';
    }

    function pintar(datos) {
        if (barra) { barra.style.width = String(datos.avance) + '%'; }
        caja.querySelector('[role="progressbar"]').setAttribute('aria-valuenow', String(datos.avance));
        if (paso) { paso.textContent = (datos.paso || 'En espera') + ' · ' + String(datos.avance) + ' %'; }
        if (tamano && datos.tamano) { tamano.textContent = datos.tamano; }
        if (aviso && datos.aviso) { aviso.textContent = datos.aviso; }
    }

    function siguiente() {
        if (boton) { boton.disabled = true; }
        fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': token() }
        })
            .then(function (r) { if (!r.ok) { throw new Error(String(r.status)); } return r.json(); })
            .then(function (datos) {
                fallas = 0;
                pintar(datos);
                if (datos.estado === 'en_proceso') {
                    // Si el servidor dejó la parte a la tarea automática, se pregunta con menos prisa
                    window.setTimeout(siguiente, datos.aviso ? 30000 : 400);
                } else {
                    window.location.reload();
                }
            })
            .catch(function () {
                fallas += 1;
                if (aviso) { aviso.textContent = 'Se interrumpió la conexión; reintentando… (el servidor no pierde lo avanzado)'; }
                if (fallas < 20) {
                    window.setTimeout(siguiente, Math.min(60000, 3000 * fallas));
                } else if (boton) {
                    boton.disabled = false;
                }
            });
    }

    window.setTimeout(siguiente, 300);
}());
