<?php

namespace App\Mail;

use App\Support\Identidad;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A Recursos Humanos: la caseta registró a alguien que no estaba en el directorio.
 * Sin datos personales: solo nombre, sede, quién lo registró y el enlace.
 */
class AltaProvisionalRegistrada extends Mailable
{
    public function __construct(
        public readonly string $nombre,
        public readonly ?string $sede,
        public readonly string $registradoPor,
        public readonly string $fecha,
        public readonly string $enlace,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Alta provisional por validar: {$this->nombre} · ".app(Identidad::class)->get('nombre_corto'));
    }

    public function content(): Content
    {
        return new Content(view: 'correos.alta-provisional');
    }
}
