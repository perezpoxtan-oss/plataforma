<?php

namespace App\Mail;

use App\Support\Identidad;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * «Olvidé mi contraseña» o «Restablecer contraseña» desde Usuarios: enlace
 * firmado de una hora para crear una contraseña nueva. Sin datos sensibles.
 */
class RestablecerContrasena extends Mailable
{
    public function __construct(
        public readonly string $nombre,
        public readonly string $enlace,
        public readonly int $minutos,
        public readonly bool $pedidoPorAdministrador = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Crea tu contraseña nueva · '.app(Identidad::class)->get('nombre_corto'));
    }

    public function content(): Content
    {
        return new Content(view: 'correos.restablecer-contrasena');
    }
}
