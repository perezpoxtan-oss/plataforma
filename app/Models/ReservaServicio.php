<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Servicio o requerimiento que se puede pedir con una reserva (alimentos,
 * bebidas, coffee break, proyector, internet, mobiliario…): catálogo
 * editable por empresa. El DEPARTAMENTO lo autoriza (si requiere aprobación)
 * y lo atiende (orden de servicio al confirmarse la reserva).
 */
class ReservaServicio extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    public const CATEGORIAS = [
        'alimentos' => 'Alimentos', 'bebidas' => 'Bebidas', 'audio_video' => 'Audio y video', 'computo' => 'Cómputo',
        'red' => 'Internet y red', 'mobiliario' => 'Mobiliario', 'otro' => 'Otro',
    ];

    /** unidad => [texto, texto corto] */
    public const UNIDADES = [
        'pieza' => ['Por pieza', 'pza.'], 'persona' => ['Por persona', 'pers.'], 'hora' => ['Por hora', 'h'], 'evento' => ['Por evento', 'evento'],
    ];

    protected $table = 'reserva_servicios';

    protected $attributes = ['activo' => true, 'orden' => 0, 'categoria' => 'otro', 'unidad' => 'pieza', 'requiere_aprobacion' => true];

    protected $fillable = ['empresa_id', 'nombre', 'categoria', 'unidad', 'costo', 'departamento_id', 'requiere_aprobacion', 'orden', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'requiere_aprobacion' => 'boolean', 'costo' => 'decimal:2', 'orden' => 'integer'];
    }

    public function departamento(): BelongsTo
    {
        return $this->belongsTo(Departamento::class);
    }
}
