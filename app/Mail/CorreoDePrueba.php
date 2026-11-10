<?php

namespace App\Mail;

use App\Support\Identidad;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class CorreoDePrueba extends Mailable
{
    public function __construct(public readonly string $enviadoPor) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Prueba de correo · '.app(Identidad::class)->get('nombre'));
    }

    public function content(): Content
    {
        return new Content(view: 'correos.prueba');
    }
}
