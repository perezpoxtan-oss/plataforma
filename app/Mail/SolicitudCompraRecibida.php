<?php

namespace App\Mail;

use App\Models\SolicitudCompra;
use App\Support\Identidad;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Catálogo comercial: llegó una solicitud de la página pública.
 *  - interno: al Super Administrador y a los correos de Ajustes, con el enlace a la solicitud.
 *  - acuse: al interesado, con su folio y su desglose (sin enlaces internos).
 */
class SolicitudCompraRecibida extends Mailable
{
    public function __construct(
        public readonly SolicitudCompra $solicitud,
        public readonly string $resumen,
        public readonly ?string $enlace,
        public readonly bool $interno,
    ) {}

    public function envelope(): Envelope
    {
        $nombre = app(Identidad::class)->get('nombre_corto');

        return new Envelope(subject: $this->interno
            ? "Nueva solicitud {$this->solicitud->folio}: {$this->solicitud->nombre} · {$nombre}"
            : "Recibimos tu solicitud {$this->solicitud->folio} · {$nombre}");
    }

    public function content(): Content
    {
        return new Content(view: 'correos.solicitud-compra');
    }
}
