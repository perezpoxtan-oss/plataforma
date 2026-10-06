<?php

namespace App\Mail;

use App\Support\Identidad;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Avisos de Procedimientos:
 *  - publicado: se publicó una versión que debes leer y firmar ("Leí y entendí");
 *  - recordatorio: los procedimientos que aún no firmas.
 * Sin datos personales: clave, título, versión y el enlace al modo lectura.
 */
class AvisoProcedimiento extends Mailable
{
    /**
     * @param  list<array{clave: string, titulo: string, version: int, enlace: string}>  $procedimientos
     */
    public function __construct(
        public readonly string $tipo,
        public readonly array $procedimientos,
        public readonly ?string $resumen,
        public readonly string $enlace,
    ) {}

    public function envelope(): Envelope
    {
        $primero = $this->procedimientos[0] ?? ['clave' => '', 'titulo' => ''];
        $asunto = $this->tipo === 'publicado'
            ? "Procedimiento {$primero['clave']} — léelo y firma de enterado"
            : (count($this->procedimientos) === 1 ? 'Tienes 1 procedimiento por leer y firmar' : 'Tienes '.count($this->procedimientos).' procedimientos por leer y firmar');

        return new Envelope(subject: $asunto.' · '.app(Identidad::class)->get('nombre_corto'));
    }

    public function content(): Content
    {
        return new Content(view: 'correos.procedimiento');
    }
}
