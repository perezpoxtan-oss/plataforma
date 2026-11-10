<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Reserva de un área (docs/tecnico/reservas.md). Fechas en hora universal;
 * el área queda bloqueada de bloqueo_desde (inicio − montaje) a bloqueo_hasta
 * (fin + desmontaje).
 *
 * Estados: por_aprobar → confirmada | rechazada | cambio_pedido | cancelada;
 * cambio_pedido → por_aprobar (al modificar) | cancelada; confirmada →
 * por_aprobar (si una modificación vuelve a pedir aprobación) | cancelada.
 */
class Reserva extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    public const POR_APROBAR = 'por_aprobar';

    public const CAMBIO_PEDIDO = 'cambio_pedido';

    public const CONFIRMADA = 'confirmada';

    public const RECHAZADA = 'rechazada';

    public const CANCELADA = 'cancelada';

    public const ESTADOS = [
        self::POR_APROBAR => 'Por aprobar', self::CAMBIO_PEDIDO => 'Cambio pedido', self::CONFIRMADA => 'Confirmada',
        self::RECHAZADA => 'Rechazada', self::CANCELADA => 'Cancelada',
    ];

    /** Estados que ocupan el área (bloquean el horario). */
    public const OCUPAN = [self::POR_APROBAR, self::CAMBIO_PEDIDO, self::CONFIRMADA];

    protected $table = 'reservas';

    protected $attributes = ['estado' => self::POR_APROBAR, 'para_cliente' => false, 'cortesia' => false, 'asistentes' => 1, 'moneda' => 'MXN',
        'importe_area' => 0, 'importe_servicios' => 0, 'descuento' => 0, 'iva_porcentaje' => 0, 'iva' => 0, 'total' => 0];

    protected $fillable = [
        'empresa_id', 'sede_id', 'reserva_area_id', 'espacio_id', 'folio', 'titulo', 'tipo_uso_id', 'montaje_id', 'asistentes',
        'inicio', 'fin', 'bloqueo_desde', 'bloqueo_hasta', 'estado', 'solicitante_id',
        // Orden de evento (migración 2026_11_07_000100)
        'departamento_solicitante_id', 'excede_aforo', 'motivo_excepcion_aforo', 'dia_completo', 'montaje_minutos', 'desmontaje_minutos', 'indicaciones_montaje',
        'para_cliente', 'cliente_nombre', 'cliente_empresa', 'cliente_correo', 'cliente_telefono',
        // C-34: el residente que reserva y su vivienda
        'residente_id', 'vivienda_id',
        'tarifa', 'importe_area', 'importe_area_manual', 'importe_servicios', 'descuento', 'cortesia', 'ajuste_motivo',
        'iva_porcentaje', 'iva', 'total', 'moneda', 'notas',
    ];

    protected function casts(): array
    {
        return [
            'inicio' => 'datetime', 'fin' => 'datetime', 'bloqueo_desde' => 'datetime', 'bloqueo_hasta' => 'datetime',
            'confirmada_en' => 'datetime', 'cancelada_en' => 'datetime',
            'para_cliente' => 'boolean', 'cortesia' => 'boolean', 'asistentes' => 'integer',
            'excede_aforo' => 'boolean', 'dia_completo' => 'boolean', 'montaje_minutos' => 'integer', 'desmontaje_minutos' => 'integer',
            'importe_area' => 'decimal:2', 'importe_area_manual' => 'decimal:2', 'importe_servicios' => 'decimal:2', 'descuento' => 'decimal:2',
            'iva_porcentaje' => 'decimal:2', 'iva' => 'decimal:2', 'total' => 'decimal:2',
        ];
    }

    /** C-34 (D-11): residente que reserva (condominio y fraccionamiento). */
    public function residente(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'residente_id');
    }

    public function vivienda(): BelongsTo
    {
        return $this->belongsTo(Espacio::class, 'vivienda_id');
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class)->withTrashed();
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(ReservaArea::class, 'reserva_area_id');
    }

    public function espacio(): BelongsTo
    {
        return $this->belongsTo(Espacio::class);
    }

    public function tipoUso(): BelongsTo
    {
        return $this->belongsTo(ReservaTipoUso::class, 'tipo_uso_id');
    }

    public function montaje(): BelongsTo
    {
        return $this->belongsTo(ReservaMontaje::class, 'montaje_id');
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitante_id');
    }

    /** Departamento que pide el evento («Solicitado por: … — Capacitación»). */
    public function departamentoSolicitante(): BelongsTo
    {
        return $this->belongsTo(Departamento::class, 'departamento_solicitante_id');
    }

    /** Minutos de montaje de esta reserva (los suyos o, si no tiene, los del área). */
    public function minutosMontaje(): int
    {
        return $this->montaje_minutos ?? (int) ($this->area?->montaje_minutos ?? 0);
    }

    /** Minutos de desmontaje de esta reserva (los suyos o, si no tiene, los del área). */
    public function minutosDesmontaje(): int
    {
        return $this->desmontaje_minutos ?? (int) ($this->area?->desmontaje_minutos ?? 0);
    }

    /** «Juan Pérez — Capacitación». */
    public function solicitadoPorTexto(): string
    {
        return trim(($this->solicitante?->name ?? '—').($this->departamentoSolicitante ? ' — '.$this->departamentoSolicitante->nombre : ''));
    }

    public function canceladaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelada_por');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReservaServicioItem::class)->orderBy('id');
    }

    public function aprobaciones(): HasMany
    {
        return $this->hasMany(ReservaAprobacion::class)->orderBy('id');
    }

    public function ordenes(): HasMany
    {
        return $this->hasMany(ReservaOrden::class)->orderBy('id');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(ReservaEvento::class)->orderByDesc('id');
    }

    /** @param  Builder<Reserva>  $q */
    public function scopeOcupan(Builder $q): void
    {
        $q->whereIn('reservas.estado', self::OCUPAN);
    }

    public function activa(): bool
    {
        return in_array($this->estado, self::OCUPAN, true);
    }

    public function yaEmpezo(): bool
    {
        return $this->inicio !== null && $this->inicio->lessThanOrEqualTo(now());
    }

    public function terminada(): bool
    {
        return $this->estado === self::CONFIRMADA && $this->fin !== null && $this->fin->lessThan(now());
    }

    /** Texto del estado («Terminada» si ya pasó). */
    public function estadoTexto(): string
    {
        return $this->terminada() ? 'Terminada' : (self::ESTADOS[$this->estado] ?? $this->estado);
    }

    /** Folio visible (RES-000123). */
    public function folioTexto(): string
    {
        return $this->folio ?: 'RES-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }
}
