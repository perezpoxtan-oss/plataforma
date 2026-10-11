<?php

namespace App\Mail;

use App\Support\Identidad;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Usuario nuevo dado de alta con «Enviar enlace de bienvenida por correo»:
 * la persona crea su propia contraseña (nadie más la conoce). Enlace de 24 h.
 */
class BienvenidaUsuario extends Mailable
{
    public function __construct(
        public readonly string $nombre,
        public readonly string $usuario,
        public readonly string $enlace,
        public readonly int $horas,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Bienvenido a '.app(Identidad::class)->get('nombre').': crea tu contraseña');
    }

    public function content(): Content
    {
        return new Content(view: 'correos.bienvenida');
    }
}
