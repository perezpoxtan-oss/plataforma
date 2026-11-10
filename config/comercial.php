<?php

/*
 * Catálogo comercial (ver docs/tecnico/catalogo-comercial.md).
 * Los precios, planes, paquetes, IVA y textos se configuran desde la
 * plataforma (Estructura → Catálogo comercial), no aquí. Aquí solo va lo
 * que NO debe cambiarse desde la interfaz: la pasarela de pago y sus claves.
 */
return [
    // Pasarela de pago: «manual» (no cobra; registra y avisa). Ver App\Services\Comercial\Pagos\Pasarelas.
    'pasarela' => env('COMERCIAL_PASARELA', 'manual'),

    // Ruta reservada POST /pagos/webhook/{pasarela}. Apagada: responde 404 hasta conectar una pasarela real.
    'webhooks_habilitados' => (bool) env('COMERCIAL_WEBHOOKS', false),

    // Claves de la pasarela (vacías: nada de pagos reales todavía). Nunca en el repositorio.
    'claves' => [
        // 'mercadopago' => ['publica' => env('MERCADOPAGO_PUBLICA'), 'secreta' => env('MERCADOPAGO_SECRETA'), 'firma' => env('MERCADOPAGO_FIRMA')],
    ],
];
