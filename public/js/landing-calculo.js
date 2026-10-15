/* ==========================================================================
   Cálculo de la calculadora de planes. MISMAS reglas que el servidor
   (App\Services\Comercial\Calculadora): una prueba compara ambos resultados.
   Todo en centavos y con enteros.
     - Sedes cobradas = el mayor entre las sedes pedidas y las mínimas del plan.
     - Plan: precio por sede × sedes cobradas (al mes). «Cotizar»: sin precio.
     - Paquete: precio × (sedes cobradas si es por sede; si no, 1); al mes o pago único.
     - IVA = redondeo(subtotal × IVA / 100), con el IVA en centésimas de punto.
     - Un paquete que no se suma al plan elegido no se cuenta y se informa.
   Funciona en el navegador (window.CalculoPlanes) y en Node (module.exports).
   ========================================================================== */
(function (raiz, fabrica) {
    'use strict';
    var api = fabrica();
    if (typeof module === 'object' && module.exports) { module.exports = api; } else { raiz.CalculoPlanes = api; }
}(typeof self !== 'undefined' ? self : globalThis, function () {
    'use strict';

    function entero(valor, minimo, maximo) {
        var n = parseInt(valor, 10);
        if (isNaN(n)) { n = minimo; }
        return Math.max(minimo, Math.min(maximo, n));
    }

    function totales(subtotal, iva) {
        var impuesto = Math.floor((subtotal * iva + 5000) / 10000);
        return { subtotal: subtotal, iva: impuesto, total: subtotal + impuesto };
    }

    function buscar(lista, id) {
        for (var i = 0; i < lista.length; i++) { if (lista[i].id === id) { return lista[i]; } }
        return null;
    }

    function compatible(paquete, plan) {
        return paquete.planes.length === 0 || (plan !== null && paquete.planes.indexOf(plan.id) !== -1);
    }

    /**
     * catalogo: {moneda, iva, planes: [{id, nombre, precio, minimas, cotizar}], paquetes: [{id, nombre, modalidad, precio, porSede, planes}]}
     */
    function calcular(catalogo, planId, sedes, paquetesIds) {
        var plan = planId ? buscar(catalogo.planes, parseInt(planId, 10)) : null;
        var pedidas = entero(sedes, 1, 999);
        var cobradas = Math.max(pedidas, plan ? plan.minimas : 1);
        var renglones = [];
        var incompatibles = [];
        var mensual = 0;
        var unico = 0;

        if (plan) {
            var precioPlan = plan.cotizar ? 0 : plan.precio;
            mensual += precioPlan * cobradas;
            renglones.push({ tipo: 'plan', id: plan.id, nombre: plan.nombre, modalidad: 'suscripcion', precio: precioPlan, cantidad: cobradas, importe: precioPlan * cobradas, cotizar: plan.cotizar });
        }

        var vistos = {};
        (paquetesIds || []).forEach(function (valor) {
            var id = parseInt(valor, 10);
            var paquete = buscar(catalogo.paquetes, id);
            if (!paquete || vistos[id]) { return; }
            vistos[id] = true;
            if (!compatible(paquete, plan)) { incompatibles.push(paquete.nombre); return; }
            var cantidad = paquete.porSede ? cobradas : 1;
            var importe = paquete.precio * cantidad;
            if (paquete.modalidad === 'unico') { unico += importe; } else { mensual += importe; }
            renglones.push({ tipo: 'paquete', id: paquete.id, nombre: paquete.nombre, modalidad: paquete.modalidad, precio: paquete.precio, cantidad: cantidad, importe: importe, cotizar: false });
        });

        return {
            sedes: pedidas,
            sedes_cobradas: cobradas,
            requiere_cotizacion: plan ? plan.cotizar : false,
            renglones: renglones,
            incompatibles: incompatibles,
            mensual: totales(mensual, catalogo.iva),
            unico: totales(unico, catalogo.iva)
        };
    }

    /** 123450 → "$1,234.50" */
    function formato(centavos) {
        var negativo = centavos < 0;
        var c = Math.abs(centavos);
        var enteros = String(Math.floor(c / 100)).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        var resto = String(c % 100);
        return (negativo ? '-' : '') + '$' + enteros + '.' + (resto.length < 2 ? '0' + resto : resto);
    }

    return { calcular: calcular, formato: formato, compatible: compatible };
}));
