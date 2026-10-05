<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Investigación de un Robo (SEGCAT: robo_detalles). Se crea vacía junto con
 * el ticket para que el caso aparezca desde el primer momento en la pantalla
 * de seguimiento de Robo.
 */
class RoboDetalle extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'robo_detalles';

    protected $fillable = [
        'empresa_id', 'novedad_id', 'hora_aproximada', 'lugar_exacto', 'objetos_descripcion', 'valor_estimado', 'hay_sospechoso',
        'descripcion_sospechoso', 'parte_policia', 'folio_policial', 'canalizado_gerencia', 'canalizado_legal', 'observaciones', 'articulo_vinculado_id',
    ];

    protected function casts(): array
    {
        return [
            'valor_estimado' => 'decimal:2', 'hay_sospechoso' => 'boolean', 'parte_policia' => 'boolean',
            'canalizado_gerencia' => 'boolean', 'canalizado_legal' => 'boolean',
        ];
    }

    public function novedad(): BelongsTo
    {
        return $this->belongsTo(Novedad::class);
    }

    public function articuloVinculado(): BelongsTo
    {
        return $this->belongsTo(LostFoundArticulo::class, 'articulo_vinculado_id');
    }
}
