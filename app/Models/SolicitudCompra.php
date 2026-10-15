<?php

namespace App\Models;

use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Solicitud que llega del formulario público «Contratar». El desglose
 * (subtotal, IVA, total y renglones) lo calcula el servidor y queda
 * congelado: si después cambian los precios, la solicitud conserva los suyos.
 * Global de la plataforma (el interesado todavía no es una empresa cliente).
 */
class SolicitudCompra extends Model
{
    use RegistraAutor;

    public const ESTADOS = [
        'nueva' => 'Nueva',
        'contactada' => 'Contactada',
        'cotizada' => 'Cotizada',
        'pagada' => 'Pagada',
        'cancelada' => 'Cancelada',
    ];

    protected $table = 'solicitudes_compra';

    protected $attributes = ['estado' => 'nueva', 'moneda' => 'MXN', 'sedes' => 1, 'acepta_privacidad' => false, 'requiere_cotizacion' => false];

    protected $fillable = [
        'folio', 'nombre', 'empresa', 'correo', 'telefono', 'sedes', 'mensaje', 'plan_id',
        'moneda', 'iva_porcentaje', 'mensual_subtotal', 'mensual_iva', 'mensual_total',
        'unico_subtotal', 'unico_iva', 'unico_total', 'requiere_cotizacion', 'detalle',
        'estado', 'pasarela', 'referencia_pago', 'notas_internas', 'acepta_privacidad', 'privacidad_aceptada_en', 'ip', 'atendida_en',
    ];

    protected function casts(): array
    {
        return [
            'sedes' => 'integer', 'iva_porcentaje' => 'decimal:2',
            'mensual_subtotal' => 'decimal:2', 'mensual_iva' => 'decimal:2', 'mensual_total' => 'decimal:2',
            'unico_subtotal' => 'decimal:2', 'unico_iva' => 'decimal:2', 'unico_total' => 'decimal:2',
            'requiere_cotizacion' => 'boolean', 'detalle' => 'array', 'acepta_privacidad' => 'boolean',
            'privacidad_aceptada_en' => 'datetime', 'atendida_en' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function estadoTexto(): string
    {
        return self::ESTADOS[$this->estado] ?? ucfirst((string) $this->estado);
    }
}
