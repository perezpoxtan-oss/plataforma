<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Detalle del Siniestro de Protección Civil (SEGCAT: siniestro_pc_detalles).
 * Si hubo lesionados, se abre una sola vez un ticket de Accidente ligado.
 */
class SiniestroDetalle extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    /** clave (valor de SEGCAT) => texto */
    public const TIPOS = [
        'CONATO DE INCENDIO' => 'Conato de Incendio / Fuego',
        'SISMO' => 'Sismo',
        'FENOMENO METEOROLOGICO' => 'Fenómeno Meteorológico (Huracán, Lluvia severa)',
        'DERRAME QUIMICO' => 'Derrame Químico / Material Peligroso',
        'FUGA DE GAS' => 'Fuga de Gas',
        'FALLA ELECTRICA' => 'Falla Eléctrica / Corto Circuito',
        'FALLA ESTRUCTURAL' => 'Falla Estructural / Colapso',
        'OTROS' => 'Otros',
    ];

    /** valor => texto de la casilla (SEGCAT) */
    public const SERVICIOS = [
        'Bomberos' => 'Bomberos',
        'Cruz Roja / Ambulancia' => 'Cruz Roja / Ambulancia',
        'Protección Civil Municipal' => 'PC Municipal',
        'Policía' => 'Policía',
        'Compañía de Gas' => 'Cía. de Gas',
        'CFE' => 'CFE',
        'Otro' => 'Otro',
    ];

    protected $table = 'siniestro_detalles';

    protected $fillable = [
        'empresa_id', 'novedad_id', 'tipo_evento', 'descripcion_otro', 'controlado_en', 'alarma_activada', 'requiere_evacuacion',
        'num_evacuados', 'punto_reunion', 'hubo_lesionados', 'num_lesionados', 'accidente_novedad_id', 'causa_probable', 'acciones_tomadas',
    ];

    protected function casts(): array
    {
        return [
            'controlado_en' => 'datetime', 'alarma_activada' => 'boolean', 'requiere_evacuacion' => 'boolean', 'hubo_lesionados' => 'boolean',
            'num_evacuados' => 'integer', 'num_lesionados' => 'integer',
        ];
    }

    public function novedad(): BelongsTo
    {
        return $this->belongsTo(Novedad::class);
    }

    public function accidente(): BelongsTo
    {
        return $this->belongsTo(Novedad::class, 'accidente_novedad_id');
    }
}
