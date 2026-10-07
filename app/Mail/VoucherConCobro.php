<?php

namespace App\Mail;

use App\Support\Identidad;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Ronda 5 (LL-04): voucher de reposición con cobro (CXC). Cada área
 * (Seguridad, Recepción, Administración) recibe su copia con el enlace a la
 * hoja imprimible. El colaborador responsable firma, pero no recibe copia.
 */
class VoucherConCobro extends Mailable
{
    public function __construct(
        public readonly string $copia,
        public readonly string $folio,
        public readonly string $articulo,
        public readonly string $origen,
        public readonly string $motivo,
        public readonly float $monto,
        public readonly ?string $sede,
        public readonly ?string $responsable,
        public readonly ?string $firma,
        public readonly string $registradoPor,
        public readonly string $fecha,
        public readonly string $enlace,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Voucher {$this->folio} con cobro — {$this->copia} · ".app(Identidad::class)->get('nombre_corto'));
    }

    public function content(): Content
    {
        return new Content(view: 'correos.voucher-cobro');
    }
}
