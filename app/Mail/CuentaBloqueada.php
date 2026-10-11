<?php

namespace App\Mail;

use App\Support\Identidad;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Aviso de seguridad: una cuenta se bloqueó por intentos fallidos. Va a
 * quien administra usuarios de esa sede y a la persona afectada. Solo el
 * nombre, la duración y el origen recortado; nada sensible.
 */
class CuentaBloqueada extends Mailable
{
    public function __construct(
        public readonly string $texto,
        public readonly bool $paraAfectado,
        public readonly ?string $enlace,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: ($this->paraAfectado ? 'Tu cuenta se bloqueó temporalmente' : 'Cuenta bloqueada por intentos fallidos').' · '.app(Identidad::class)->get('nombre_corto'));
    }

    public function content(): Content
    {
        return new Content(view: 'correos.cuenta-bloqueada');
    }
}
