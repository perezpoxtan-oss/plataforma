<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Punto de inspección de un Recorrido de Protección Civil (SEGCAT:
 * recorridos_pc_items): un equipo revisado con el resultado de cada
 * criterio. OK si todos los criterios están sanos y no hay observación; si
 * no, FALLA (hallazgo).
 */
class RevisionRecorridoPc extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'recorrido_pc_revisiones';

    public const OK = 'ok';

    public const FALLA = 'falla';

    protected $fillable = ['empresa_id', 'recorrido_pc_id', 'equipo_pc_id', 'identificador', 'categoria', 'ubicacion', 'criterios', 'resultado', 'observaciones'];

    protected function casts(): array
    {
        return ['criterios' => 'array', 'equipo_pc_id' => 'integer', 'recorrido_pc_id' => 'integer'];
    }

    public function recorrido(): BelongsTo
    {
        return $this->belongsTo(RecorridoPc::class, 'recorrido_pc_id');
    }

    public function equipo(): BelongsTo
    {
        return $this->belongsTo(EquipoPc::class, 'equipo_pc_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function esFalla(): bool
    {
        return $this->resultado === self::FALLA;
    }

    public function etiquetaCategoria(): string
    {
        return EquipoPc::CATEGORIAS[$this->categoria] ?? $this->categoria;
    }

    /**
     * Piezas o criterios que se marcaron con falla (no sanos / no presentes).
     *
     * @return list<string>
     */
    public function criteriosConFalla(): array
    {
        $textos = EquipoPc::criterios($this->categoria);
        $fallas = [];
        foreach ((array) $this->criterios as $clave => $sano) {
            if (! $sano) {
                $fallas[] = $textos[$clave] ?? (string) $clave;
            }
        }

        return $fallas;
    }

    /** "EXT-01 (Extintor) — Torre A · Piso 1: Falla en Manómetro, Precinto. Obs: …" (ticket y reporte). */
    public function resumenHallazgo(): string
    {
        $fallas = $this->criteriosConFalla();

        return $this->identificador.' ('.$this->etiquetaCategoria().')'
            .($this->ubicacion ? ' — '.$this->ubicacion : '')
            .($fallas !== [] ? ': Falla en '.implode(', ', $fallas).'.' : '.')
            .($this->observaciones ? ' Obs: '.$this->observaciones : '');
    }
}
