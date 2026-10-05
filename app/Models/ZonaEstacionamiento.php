<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Zona de una sede donde se dejan vehículos (SEGCAT: estacionamientos_zonas):
 * un estacionamiento con cupo de espacios o una zona de descarga (lobby,
 * almacén, andén) sin cupo. La Bitácora de accesos la asignará al registrar
 * la entrada de un vehículo.
 */
class ZonaEstacionamiento extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'zonas_estacionamiento';

    /** Opciones del formulario (textos de SEGCAT). */
    public const TIPOS = [
        'estacionamiento' => 'Estacionamiento (cuenta espacios)',
        'zona_descarga' => 'Zona de Descarga (Lobby, Almacenes — sin cupo)',
    ];

    /** Distintivo de la tarjeta (SEGCAT). */
    public const DISTINTIVOS = [
        'estacionamiento' => 'ESTACIONAMIENTO',
        'zona_descarga' => 'ZONA DE DESCARGA',
    ];

    protected $attributes = ['activo' => true, 'tipo' => 'estacionamiento'];

    protected $fillable = ['empresa_id', 'sede_id', 'nombre', 'tipo', 'cupo_total'];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'cupo_total' => 'integer'];
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class)->withTrashed();
    }

    public function esDescarga(): bool
    {
        return $this->tipo === 'zona_descarga';
    }
}
