<?php

namespace App\Mail;

use App\Support\Identidad;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Avisos del circuito de Pases de salida:
 *  - por_aprobar: a quien debe firmar el siguiente paso de aprobación;
 *  - aprobado / rechazado: al solicitante (y a quien registró el pase);
 *  - vencido: recordatorio diario de un pase que debió regresar.
 * Sin datos personales: folio, motivo, sede, destino, el paso y el enlace.
 */
class AvisoPaseSalida extends Mailable
{
    public const ASUNTOS = [
        'por_aprobar' => 'Pase de salida %s — espera tu aprobación',
        'aprobado' => 'Pase de salida %s — aprobado, listo para salir',
        'rechazado' => 'Pase de salida %s — rechazado: revisa los motivos',
        'vencido' => 'Pase de salida %s — vencido: el equipo no ha regresado',
    ];

    public function __construct(
        public readonly string $tipo,
        public readonly string $folio,
        public readonly string $motivo,
        public readonly ?string $solicitante,
        public readonly ?string $sede,
        public readonly string $destino,
        public readonly int $articulos,
        public readonly ?string $paso,
        public readonly ?string $comentario,
        public readonly ?string $fecha,
        public readonly string $enlace,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: sprintf(self::ASUNTOS[$this->tipo] ?? 'Pase de salida %s', $this->folio).' · '.app(Identidad::class)->get('nombre_corto'));
    }

    public function content(): Content
    {
        return new Content(view: 'correos.pase-salida');
    }
}
