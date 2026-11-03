<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Horario corporativo predefinido (Matutino, Vespertino, Nocturno...).
 * Puede cruzar la medianoche: 23:00 a 07:00 termina al día siguiente.
 * Se usa en todas las sedes (incluidas las futuras) o solo en las elegidas.
 */
class Turno extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $attributes = ['activo' => true, 'todas_las_sedes' => true];

    protected $fillable = ['empresa_id', 'nombre', 'hora_inicio', 'hora_fin', 'todas_las_sedes', 'activo', 'dias_laborables'];

    /** Días de la semana (ISO: 1 = lunes … 7 = domingo) para Asistencia (ADR-0024). */
    public const DIAS = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];

    public const DIAS_CORTOS = [1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom'];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'todas_las_sedes' => 'boolean'];
    }

    public function sedes(): BelongsToMany
    {
        return $this->belongsToMany(Sede::class, 'sede_turno');
    }

    /**
     * Turnos que se usan en alguna de estas sedes.
     *
     * @param  array<int>  $sedeIds
     */
    public function scopeAplicanEn(Builder $consulta, array $sedeIds): Builder
    {
        return $consulta->where(fn ($q) => $q->where('todas_las_sedes', true)
            ->orWhereHas('sedes', fn ($s) => $s->whereIn('sedes.id', $sedeIds)));
    }

    public function inicio(): string
    {
        return substr((string) $this->hora_inicio, 0, 5);
    }

    public function fin(): string
    {
        return substr((string) $this->hora_fin, 0, 5);
    }

    /** Termina al día siguiente (p. ej. 23:00 a 07:00). */
    public function cruzaMedianoche(): bool
    {
        return $this->fin() < $this->inicio();
    }

    /** Duración en minutos, contando el paso de la medianoche. */
    public function minutos(): int
    {
        $aMinutos = fn (string $h) => ((int) substr($h, 0, 2)) * 60 + (int) substr($h, 3, 2);
        $diferencia = $aMinutos($this->fin()) - $aMinutos($this->inicio());

        return $diferencia <= 0 ? $diferencia + 1440 : $diferencia;
    }

    /**
     * Días que se trabaja (Asistencia): vacío = todos los días.
     *
     * @return list<int>
     */
    public function diasLaborables(): array
    {
        $dias = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $this->dias_laborables)),
            fn (int $d) => $d >= 1 && $d <= 7)));
        sort($dias);

        return $dias === [] ? [1, 2, 3, 4, 5, 6, 7] : $dias;
    }

    public function trabajaEl(int $diaIso): bool
    {
        return in_array($diaIso, $this->diasLaborables(), true);
    }

    /** «Lun a Vie», «Lun, Mié, Vie» o «Todos los días». */
    public function textoDias(): string
    {
        $dias = $this->diasLaborables();
        if (count($dias) === 7) {
            return 'Todos los días';
        }
        $seguidos = $dias === range($dias[0], $dias[count($dias) - 1]);

        return $seguidos && count($dias) > 2
            ? self::DIAS_CORTOS[$dias[0]].' a '.self::DIAS_CORTOS[$dias[count($dias) - 1]]
            : implode(', ', array_map(fn ($d) => self::DIAS_CORTOS[$d], $dias));
    }

    /** "8 h" o "7 h 30 min". */
    public function duracion(): string
    {
        $h = intdiv($this->minutos(), 60);
        $m = $this->minutos() % 60;

        return trim(($h > 0 ? "{$h} h" : '').($m > 0 ? " {$m} min" : ''));
    }
}
