<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Configuración de reserva de un espacio de Zonas y edificios (1 a 1): no es
 * otro catálogo de lugares. Un espacio marcado «Reservable» tiene aforo,
 * montajes (cada uno con su aforo), horario por día, minutos de montaje y
 * desmontaje que bloquean el área, tarifas opcionales, foto y descripción, y
 * su responsable de aprobación (si no tiene, el responsable general de
 * reservas de la empresa).
 */
class ReservaArea extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    /** Días de la semana (ISO: 1 = lunes). */
    public const DIAS = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];

    /** tarifa => [columna, texto] */
    public const TARIFAS = [
        'hora' => ['tarifa_hora', 'Por hora'], 'medio_dia' => ['tarifa_medio_dia', 'Medio día'],
        'dia' => ['tarifa_dia', 'Día completo'], 'evento' => ['tarifa_evento', 'Por evento'],
    ];

    protected $table = 'reserva_areas';

    protected $attributes = ['reservable' => true, 'confirmacion_inmediata' => false, 'montaje_minutos' => 0, 'desmontaje_minutos' => 0];

    protected $fillable = [
        'empresa_id', 'sede_id', 'espacio_id', 'reservable', 'aforo_maximo', 'confirmacion_inmediata', 'montaje_minutos', 'desmontaje_minutos',
        'horario', 'tarifa_hora', 'tarifa_medio_dia', 'tarifa_dia', 'tarifa_evento', 'descripcion', 'foto', 'responsable_id', 'suplente_id',
    ];

    protected function casts(): array
    {
        return [
            'reservable' => 'boolean', 'confirmacion_inmediata' => 'boolean', 'horario' => 'array',
            'aforo_maximo' => 'integer', 'montaje_minutos' => 'integer', 'desmontaje_minutos' => 'integer',
            'tarifa_hora' => 'decimal:2', 'tarifa_medio_dia' => 'decimal:2', 'tarifa_dia' => 'decimal:2', 'tarifa_evento' => 'decimal:2',
        ];
    }

    public function espacio(): BelongsTo
    {
        return $this->belongsTo(Espacio::class);
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class)->withTrashed();
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    public function suplente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'suplente_id');
    }

    public function montajes(): BelongsToMany
    {
        return $this->belongsToMany(ReservaMontaje::class, 'reserva_area_montajes', 'reserva_area_id', 'reserva_montaje_id')
            ->withPivot('aforo')->orderBy('reserva_montajes.orden')->orderBy('reserva_montajes.nombre');
    }

    public function nombre(): string
    {
        return (string) ($this->espacio?->nombre ?? 'Área');
    }

    /**
     * Tarifas capturadas: tarifa => monto.
     *
     * @return array<string, float>
     */
    public function tarifas(): array
    {
        $r = [];
        foreach (self::TARIFAS as $clave => [$columna]) {
            if ($this->{$columna} !== null && (float) $this->{$columna} > 0) {
                $r[$clave] = (float) $this->{$columna};
            }
        }

        return $r;
    }

    /** Aforo de un montaje en esta área (o el máximo del área). */
    public function aforoPara(?int $montajeId): ?int
    {
        if ($montajeId !== null) {
            $montaje = $this->montajes->firstWhere('id', $montajeId);
            if ($montaje !== null && $montaje->pivot->aforo !== null) {
                return (int) $montaje->pivot->aforo;
            }
        }

        return $this->aforo_maximo;
    }
}
