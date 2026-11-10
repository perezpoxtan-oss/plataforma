<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Visto bueno que espera una reserva: el del ÁREA (su responsable) y el de
 * CADA DEPARTAMENTO de los servicios que lo requieren, en paralelo. Todas las
 * vigentes deben aprobar; cualquiera puede rechazar o pedir un cambio.
 *
 * `vigente` marca la que vale hoy para su parte; al modificar la reserva, la
 * parte cuya huella cambió (o que no estaba aprobada) se vuelve a pedir y la
 * anterior queda de historial. Recordatorio y escalamiento como las demás
 * esperas (recordatorio_en, escalado_en, escalado_a).
 */
class ReservaAprobacion extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    public const PENDIENTE = 'pendiente';

    public const APROBADA = 'aprobada';

    public const RECHAZADA = 'rechazada';

    public const CAMBIO = 'cambio';

    public const CANCELADA = 'cancelada';

    public const ESTADOS = [
        self::PENDIENTE => 'Por aprobar', self::APROBADA => 'Aprobada', self::RECHAZADA => 'Rechazada',
        self::CAMBIO => 'Pidió un cambio', self::CANCELADA => 'Ya no aplica',
    ];

    protected $table = 'reserva_aprobaciones';

    protected $attributes = ['estado' => self::PENDIENTE, 'vigente' => true];

    protected $fillable = ['empresa_id', 'reserva_id', 'tipo', 'departamento_id', 'estado', 'vigente', 'huella', 'solicitada_en', 'avisados'];

    protected function casts(): array
    {
        return [
            'vigente' => 'boolean', 'avisados' => 'array', 'escalado_a' => 'array',
            'solicitada_en' => 'datetime', 'respondida_en' => 'datetime', 'recordatorio_en' => 'datetime', 'escalado_en' => 'datetime',
        ];
    }

    public function reserva(): BelongsTo
    {
        return $this->belongsTo(Reserva::class);
    }

    public function departamento(): BelongsTo
    {
        return $this->belongsTo(Departamento::class);
    }

    public function respondidaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'respondida_por');
    }

    public function pendiente(): bool
    {
        return $this->estado === self::PENDIENTE;
    }

    /** «Área: Salón Maya» o «Cocina». */
    public function parte(): string
    {
        return $this->tipo === 'area' ? 'Responsable del área' : (string) ($this->departamento?->nombre ?? 'Departamento');
    }

    /** Clave de la parte (una vigente por parte). */
    public function clave(): string
    {
        return $this->tipo === 'area' ? 'area' : 'departamento:'.$this->departamento_id;
    }
}
