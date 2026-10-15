<?php

namespace App\Services\Comercial\Pagos;

/**
 * Lo que responde una pasarela al iniciar un cobro.
 */
final class ResultadoCobro
{
    public function __construct(
        /** «pendiente» (espera contacto o pago) | «redirigir» (ir a url_pago) | «error». */
        public readonly string $estado,
        /** Dirección de pago de la pasarela (solo en «redirigir»). */
        public readonly ?string $urlPago = null,
        /** Folio u orden de la pasarela, si ya existe. */
        public readonly ?string $referencia = null,
        public readonly string $mensaje = '',
    ) {}
}
