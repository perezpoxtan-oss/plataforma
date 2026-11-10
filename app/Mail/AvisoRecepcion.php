<?php

namespace App\Mail;

use App\Support\Identidad;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Avisos de Recepción y Autorizaciones: llegó un candidato, una visita o un
 * candidato espera la respuesta del departamento, respuesta registrada.
 *
 * Sin CV ni datos de contacto: nombre, a qué viene y botones. Los botones de
 * respuesta llevan una dirección firmada que pide iniciar sesión y confirmar.
 */
class AvisoRecepcion extends Mailable
{
    /**
     * @param  list<string>  $lineas
     * @param  list<array{0: string, 1: string}>  $botones  [texto, dirección]
     */
    public function __construct(
        public readonly string $titulo,
        public readonly array $lineas,
        public readonly array $botones,
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
