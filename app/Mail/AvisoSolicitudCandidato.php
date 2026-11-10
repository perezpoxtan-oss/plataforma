<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Correo al candidato con el enlace para completar su solicitud de empleo
 * (postulación corta por internet, ADR-0010). Texto neutral: sin resultados
 * ni datos de nadie más; el enlace solo abre su propia solicitud.
 */
class AvisoSolicitudCandidato extends Mailable
{
    public function __construct(
        public readonly string $nombre,
        public readonly string $empresa,
        public readonly string $url,
        public readonly int $horas,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Completa tu solicitud de empleo'.($this->empresa !== '' ? ' en '.$this->empresa : ''));
    }

    public function content(): Content
    {
        return new Content(view: 'correos.solicitud-candidato');
    }
}
