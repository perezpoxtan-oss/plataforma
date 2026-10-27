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
        'en_prueba' => 'Prueba activada',
        'pagada' => 'Pagada',
        'cancelada' => 'Cancelada',
    ];

    /** Qué pide: contratar, probar gratis o, si ya es cliente, renovar o ampliar. */
    public const TIPOS = [
        'compra' => 'Contratar',
        'prueba' => 'Prueba gratis',
        'renovacion' => 'Renovar',
        'ampliacion' => 'Ampliar o cambiar de plan',
    ];

    protected $table = 'solicitudes_compra';

    protected $attributes = ['tipo' => 'compra', 'periodicidad' => 'mensual', 'estado' => 'nueva', 'moneda' => 'MXN', 'sedes' => 1, 'acepta_privacidad' => false, 'requiere_cotizacion' => false];

    protected $fillable = [
        'folio', 'tipo', 'periodicidad', 'periodo_subtotal', 'periodo_iva', 'periodo_total', 'descuento', 'codigo_promocion',
        'promocion_id', 'empresa_id', 'activada_en', 'nombre', 'empresa', 'correo', 'telefono', 'sedes', 'mensaje', 'plan_id', 'rubro_id',
        'moneda', 'iva_porcentaje', 'mensual_subtotal', 'mensual_iva', 'mensual_total',
        'unico_subtotal', 'unico_iva', 'unico_total', 'requiere_cotizacion', 'detalle',
        'estado', 'pasarela', 'referencia_pago', 'notas_internas', 'acepta_privacidad', 'privacidad_aceptada_en', 'aviso_privacidad_id', 'ip', 'atendida_en',
    ];

    protected function casts(): array
    {
        return [
            'sedes' => 'integer', 'iva_porcentaje' => 'decimal:2',
            'mensual_subtotal' => 'decimal:2', 'mensual_iva' => 'decimal:2', 'mensual_total' => 'decimal:2',
            'unico_subtotal' => 'decimal:2', 'unico_iva' => 'decimal:2', 'unico_total' => 'decimal:2',
            'requiere_cotizacion' => 'boolean', 'detalle' => 'array', 'acepta_privacidad' => 'boolean',
            'privacidad_aceptada_en' => 'datetime', 'atendida_en' => 'datetime', 'activada_en' => 'datetime',
            'periodo_subtotal' => 'decimal:2', 'periodo_iva' => 'decimal:2', 'periodo_total' => 'decimal:2', 'descuento' => 'decimal:2',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** Giro que eligió al contratar (C-05); null en las solicitudes de antes. */
    public function rubro(): BelongsTo
    {
        return $this->belongsTo(Rubro::class);
    }

    public function empresaCliente(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id')->withTrashed();
    }

    public function promocion(): BelongsTo
    {
        return $this->belongsTo(Promocion::class);
    }

    public function esPrueba(): bool
    {
        return $this->tipo === 'prueba';
    }

    public function tipoTexto(): string
    {
        return self::TIPOS[$this->tipo] ?? (string) $this->tipo;
    }

    /** «al mes» o «al año» */
    public function periodoTexto(): string
    {
        return $this->periodicidad === 'anual' ? 'al año' : 'al mes';
    }

    public function estadoTexto(): string
    {
        return self::ESTADOS[$this->estado] ?? ucfirst((string) $this->estado);
    }
}
