<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Candidatos, fase 2: correo al candidato con su cita de entrevista (fecha,
 * hora, lugar y a quién buscar). Texto neutral: nunca lleva resultados,
 * evaluaciones ni datos de otras personas. Lo pide Recursos Humanos al
 * canalizar o reprogramar («Avisar al candidato por correo»).
 */
class AvisoEntrevistaCandidato extends Mailable
{
    public function __construct(
        public readonly string $nombre,
        public readonly string $empresa,
        public readonly string $fecha,
        public readonly string $hora,
        public readonly ?string $lugar,
        public readonly ?string $buscar,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Tu entrevista'.($this->empresa !== '' ? ' en '.$this->empresa : '').': '.$this->fecha.' a las '.$this->hora);
    }

    public function content(): Content
    {
        return new Content(view: 'correos.entrevista-candidato');
    }
}
