<?php

namespace App\Models;

use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ajuste a mano de un cliente respecto a su plan: usuarios o sedes de más (o
 * de menos), módulos agregados o quitados. Siempre con motivo y autor; se da
 * de baja (no se borra) para conservar quién lo autorizó.
 */
class SuscripcionExcepcion extends Model
{
    use RegistraAutor;

    public const TIPOS = [
        'usuarios' => 'Usuarios activos de más',
        'sedes' => 'Sedes de más',
        'modulo_agregado' => 'Módulo agregado',
        'modulo_quitado' => 'Módulo quitado',
    ];

    protected $table = 'suscripcion_excepciones';

    protected $attributes = ['activo' => true];

    protected $fillable = ['suscripcion_id', 'empresa_id', 'tipo', 'cantidad', 'modulo_id', 'motivo', 'activo'];

    protected function casts(): array
    {
        return ['cantidad' => 'integer', 'activo' => 'boolean', 'dado_de_baja_en' => 'datetime'];
    }

    public function suscripcion(): BelongsTo
    {
        return $this->belongsTo(Suscripcion::class);
    }

    public function modulo(): BelongsTo
    {
        return $this->belongsTo(Modulo::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function texto(): string
    {
        return match ($this->tipo) {
            'usuarios' => ($this->cantidad >= 0 ? '+' : '').$this->cantidad.' usuarios activos',
            'sedes' => ($this->cantidad >= 0 ? '+' : '').$this->cantidad.' sedes',
            'modulo_agregado' => 'Agrega «'.($this->modulo?->nombre ?? 'módulo').'»',
            'modulo_quitado' => 'Quita «'.($this->modulo?->nombre ?? 'módulo').'»',
            default => (string) $this->tipo,
        };
    }
}
