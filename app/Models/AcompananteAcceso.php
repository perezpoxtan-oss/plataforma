<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persona que llega con el titular de un acceso (SEGCAT:
 * bitacora_acompaniantes): nombre, identificación custodiada y gafete
 * propios. Puede salir antes que el titular (salida individual) y, si el
 * titular es proveedor o contratista, salir un rato y regresar (su gafete
 * sigue reservado mientras está fuera).
 */
class AcompananteAcceso extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'acompanantes_acceso';

    protected $fillable = ['empresa_id', 'acceso_id', 'nombre', 'identificacion', 'gafete_id', 'gafete_texto'];

    protected function casts(): array
    {
        return ['salida_at' => 'datetime', 'salida_temporal_at' => 'datetime', 'regreso_temporal_at' => 'datetime'];
    }

    public function acceso(): BelongsTo
    {
        return $this->belongsTo(Acceso::class);
    }

    public function gafete(): BelongsTo
    {
        return $this->belongsTo(Gafete::class);
    }

    /** ¿Salió un rato y aún no regresa? */
    public function estaFueraTemporal(): bool
    {
        return $this->salida_temporal_at !== null && $this->regreso_temporal_at === null;
    }

    public function nombreVisible(): string
    {
        return $this->nombre ?: 'Sin nombre';
    }
}
