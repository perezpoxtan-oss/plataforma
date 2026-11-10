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

    /** Cómo se reserva una zona con espacios interiores (migración 2026_11_06_000100). */
    public const COMPLETA = 'completa';

    public const INTERIORES = 'interiores';

    public const MODOS = [self::COMPLETA => 'Toda la zona como un solo espacio', self::INTERIORES => 'Por sus espacios interiores'];

    /** Configuración que un espacio interior recibe de su zona como valores iniciales. */
    public const HEREDABLES = [
        'aforo_maximo', 'confirmacion_inmediata', 'montaje_minutos', 'desmontaje_minutos', 'horario',
        'tarifa_hora', 'tarifa_medio_dia', 'tarifa_dia', 'tarifa_evento', 'responsable_id', 'suplente_id',
    ];

    protected $table = 'reserva_areas';

    protected $attributes = ['reservable' => true, 'modo' => self::COMPLETA, 'confirmacion_inmediata' => false, 'montaje_minutos' => 0, 'desmontaje_minutos' => 0];

    protected $fillable = [
        'empresa_id', 'sede_id', 'espacio_id', 'reservable', 'modo', 'aforo_maximo', 'confirmacion_inmediata', 'montaje_minutos', 'desmontaje_minutos',
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

    /** La zona solo agrupa: se reservan sus espacios interiores. */
    public function porInteriores(): bool
    {
        return $this->modo === self::INTERIORES;
    }

    /** @var array<int, string>|null */
    private ?array $nombresRuta = null;

    /**
     * «Torre A › Piso 1 › 101»: dónde está el espacio (sus ancestros en Zonas
     * y edificios y él mismo). Una consulta por área, solo cuando se pide.
     */
    public function rutaTexto(string $separador = ' › '): string
    {
        $espacio = $this->espacio;
        if ($espacio === null) {
            return $this->nombre();
        }
        if ($this->nombresRuta === null) {
            $ids = $espacio->idsAncestros();
            $nombres = $ids === [] ? collect() : Espacio::whereIn('id', $ids)->pluck('nombre', 'id');
            $this->nombresRuta = collect($ids)->map(fn ($id) => $nombres[$id] ?? null)->filter()->values()->all();
        }

        return implode($separador, [...$this->nombresRuta, $espacio->nombre]);
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
