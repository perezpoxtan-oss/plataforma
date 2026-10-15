<?php

namespace App\Mail;

use App\Support\Identidad;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Suscripciones: avisos al Administrador del cliente y al Super
 * Administrador (por vencer, venció, mitad de la gracia, solo consulta, fin
 * de la prueba, cliente activado).
 */
class AvisoSuscripcion extends Mailable
{
    public function __construct(
        public readonly string $titulo,
        public readonly string $texto,
        public readonly ?string $enlace = null,
        public readonly string $boton = 'Ver la suscripción',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->titulo.' · '.app(Identidad::class)->get('nombre_corto'));
    }

    public function content(): Content
    {
        return new Content(view: 'correos.aviso-suscripcion');
    }
}
