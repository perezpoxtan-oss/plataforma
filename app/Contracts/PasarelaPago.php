<?php

namespace App\Contracts;

use App\Models\SolicitudCompra;
use App\Services\Comercial\Pagos\ResultadoCobro;
use Illuminate\Http\Request;

/**
 * Pasarela de pago del Catálogo comercial. Hoy solo existe la «Manual» (no
 * cobra: registra la solicitud y avisa al Super Administrador). Para cobrar en
 * línea se agrega una clase por pasarela (Mercado Pago, Stripe, Conekta…) que
 * implemente esta interfaz; ver docs/tecnico/catalogo-comercial.md,
 * «Pagos en línea: cómo conectarlos».
 */
interface PasarelaPago
{
    /** Clave corta que se guarda en solicitudes_compra.pasarela («manual», «mercadopago»…). */
    public function clave(): string;

    /** Nombre para mostrar. */
    public function nombre(): string;

    /**
     * Inicia el cobro de una solicitud ya guardada (con su desglose congelado).
     * Una pasarela en línea crea la preferencia u orden de pago y devuelve la
     * dirección a la que se envía al interesado; la Manual no cobra.
     */
    public function crearCobro(SolicitudCompra $solicitud): ResultadoCobro;

    /**
     * Aviso de la pasarela (webhook): valida la firma con la clave secreta,
     * consulta el pago a la pasarela (nunca confía solo en lo que llega) y
     * marca la solicitud como «pagada» con su referencia_pago. Devuelve la
     * solicitud afectada o null si el aviso no es válido o no aplica.
     */
    public function confirmar(Request $request): ?SolicitudCompra;
}
