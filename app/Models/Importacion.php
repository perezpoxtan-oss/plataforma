<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un archivo subido en «Importar desde Excel»: su vista previa y, al
 * guardarlo, el resultado de cada fila (ver docs/tecnico/importacion.md).
 *
 * filas / resultado: lista de {fila, estado, clave, mensaje, errores{columna: motivo}}
 * con el número de renglón del archivo (el 1 es el encabezado).
 */
class Importacion extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    public const VISTA_PREVIA = 'vista_previa';

    public const APLICADA = 'aplicada';

    protected $table = 'importaciones';

    protected $attributes = ['estado' => self::VISTA_PREVIA, 'total_filas' => 0];

    protected $fillable = [
        'empresa_id', 'tipo', 'archivo_nombre', 'archivo_ruta', 'formato', 'total_filas',
        'estado', 'existentes', 'resumen', 'filas', 'resultado', 'aplicada_en',
    ];

    protected function casts(): array
    {
        return [
            'resumen' => 'array',
            'filas' => 'array',
            'resultado' => 'array',
            'aplicada_en' => 'datetime',
            'total_filas' => 'integer',
        ];
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function aplicada(): bool
    {
        return $this->estado === self::APLICADA;
    }
}
