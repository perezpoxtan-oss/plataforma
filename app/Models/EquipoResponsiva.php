<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un equipo dentro de un resguardo (lote), con su modalidad: PRESTADO
 * ("Turno", se devuelve al terminar) o ASIGNADO ("Fijo").
 */
class EquipoResponsiva extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'equipos_responsiva';

    /** clave => texto del formulario (SEGCAT: PRESTADO = "Turno", ASIGNADO = "Fijo") */
    public const MODALIDADES = [
        'prestado' => 'Turno',
        'asignado' => 'Fijo',
    ];

    /** Texto de la hoja impresa (SEGCAT: ticket_responsiva.php). */
    public const MODALIDADES_HOJA = [
        'prestado' => 'Préstamo de Turno',
        'asignado' => 'Asignación Fija',
    ];

    protected $fillable = ['empresa_id', 'responsiva_id', 'equipo_id', 'modalidad'];

    protected function casts(): array
    {
        return ['devuelto_en' => 'datetime'];
    }

    public function responsiva(): BelongsTo
    {
        return $this->belongsTo(Responsiva::class);
    }

    public function equipo(): BelongsTo
    {
        return $this->belongsTo(Equipo::class);
    }

    public function etiquetaModalidad(): string
    {
        return self::MODALIDADES[$this->modalidad] ?? $this->modalidad;
    }
}
