<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Renglón de servicio de una reserva: copia el nombre, la unidad, el costo y
 * el departamento del catálogo al reservar (si el catálogo cambia después, la
 * reserva conserva lo que se pidió).
 */
class ReservaServicioItem extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'reserva_servicio_items';

    protected $fillable = ['empresa_id', 'reserva_id', 'servicio_id', 'departamento_id', 'nombre', 'unidad', 'cantidad', 'costo_unitario', 'importe', 'notas'];

    protected function casts(): array
    {
        return ['cantidad' => 'decimal:2', 'costo_unitario' => 'decimal:2', 'importe' => 'decimal:2'];
    }

    public function reserva(): BelongsTo
    {
        return $this->belongsTo(Reserva::class);
    }

    public function servicio(): BelongsTo
    {
        return $this->belongsTo(ReservaServicio::class, 'servicio_id');
    }

    public function departamento(): BelongsTo
    {
        return $this->belongsTo(Departamento::class);
    }

    /** «3», «2.5» (sin ceros de más). */
    public function cantidadTexto(): string
    {
        return rtrim(rtrim(number_format((float) $this->cantidad, 2, '.', ''), '0'), '.');
    }
}
