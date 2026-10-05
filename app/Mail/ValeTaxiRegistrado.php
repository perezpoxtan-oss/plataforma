<?php

namespace App\Mail;

use App\Support\Identidad;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Bitácora de transporte: se registró un vale de taxi (la unidad de la ruta
 * no llegó). SEGCAT: "Vale de Taxi #N — Requiere Autorización".
 * Sin datos de los pasajeros: solo cuántos son.
 */
class ValeTaxiRegistrado extends Mailable
{
    public function __construct(
        public readonly string $folio,
        public readonly float $monto,
        public readonly ?string $sede,
        public readonly ?string $ruta,
        public readonly ?string $conductor,
        public readonly ?string $destino,
        public readonly int $pasajeros,
        public readonly ?string $justificacion,
        public readonly string $registradoPor,
        public readonly string $fecha,
        public readonly string $enlace,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Vale de Taxi {$this->folio} — Requiere Autorización · ".app(Identidad::class)->get('nombre_corto'));
    }

    public function content(): Content
    {
        return new Content(view: 'correos.vale-taxi');
    }
}
