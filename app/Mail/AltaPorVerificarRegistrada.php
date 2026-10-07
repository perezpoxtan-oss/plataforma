<?php

namespace App\Mail;

use App\Support\Identidad;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A quien administra un padrón: la caseta registró desde Operación algo que
 * no estaba (vehículo, empresa externa, persona) y queda pendiente de
 * verificar. Sin datos personales: solo el nombre o las placas, la sede,
 * quién lo registró y el enlace.
 */
class AltaPorVerificarRegistrada extends Mailable
{
    public function __construct(
        public readonly string $tipo,
        public readonly string $titulo,
        public readonly string $padron,
        public readonly ?string $sede,
        public readonly ?string $origen,
        public readonly string $registradoPor,
        public readonly string $fecha,
        public readonly string $enlace,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Alta por verificar ({$this->tipo}): {$this->titulo} · ".app(Identidad::class)->get('nombre_corto'));
    }

    public function content(): Content
    {
        return new Content(view: 'correos.alta-por-verificar');
    }
}
