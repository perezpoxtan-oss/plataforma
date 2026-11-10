<?php

namespace App\Mail;

use App\Support\Identidad;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Aviso de texto con botones (misma plantilla que los de Recepción): lo usan
 * la verificación en dos pasos (restablecida) y las solicitudes ARCO
 * (verificar el correo, respuesta). Nunca lleva claves, códigos ni datos
 * personales de terceros.
 */
class AvisoGeneral extends Mailable
{
    /**
     * @param  list<string>  $lineas
     * @param  list<array{0: string, 1: string}>  $botones  [texto, dirección]
     */
    public function __construct(
        public readonly string $titulo,
        public readonly array $lineas,
        public readonly array $botones = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->titulo.' · '.app(Identidad::class)->get('nombre_corto'));
    }

    public function content(): Content
    {
        return new Content(view: 'correos.recepcion');
    }
}
