<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historial visible de una reserva (se creó, se aprobó, se pidió un cambio,
 * se modificó, se canceló, orden lista, recordatorio…). La Bitácora de
 * auditoría guarda además el antes y el después.
 */
class ReservaEvento extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'reserva_eventos';

    protected $fillable = ['empresa_id', 'reserva_id', 'evento', 'titulo', 'detalle', 'user_id'];

    protected function casts(): array
    {
        return ['detalle' => 'array'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
