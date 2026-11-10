<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Orden de servicio de un departamento para una reserva confirmada (qué,
 * cuánto, cuándo, dónde y montaje). El departamento la marca «Listo» y quien
 * reservó y el responsable del área ven el avance. Si la reserva cambia lo de
 * ese departamento, la orden vuelve a pendiente (huella).
 */
class ReservaOrden extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    public const PENDIENTE = 'pendiente';

    public const LISTA = 'lista';

    public const CANCELADA = 'cancelada';

    protected $table = 'reserva_ordenes';

    protected $attributes = ['estado' => self::PENDIENTE];

    protected $fillable = ['empresa_id', 'reserva_id', 'departamento_id', 'estado', 'huella'];

    protected function casts(): array
    {
        return ['lista_en' => 'datetime', 'sin_quien_atienda' => 'boolean'];
    }

    public function reserva(): BelongsTo
    {
        return $this->belongsTo(Reserva::class);
    }

    public function departamento(): BelongsTo
    {
        return $this->belongsTo(Departamento::class);
    }

    public function listaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lista_por');
    }
}
