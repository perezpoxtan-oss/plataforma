<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cola de comandos de un equipo (ZKTeco): alta o baja de un usuario. Se
 * entrega cuando el equipo pregunta (GET /iclock/getrequest) y se cierra con
 * su respuesta (POST /iclock/devicecmd). Sin cron.
 */
class ComandoEquipoAcceso extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'equipos_acceso_comandos';

    /** Veces que se reenvía un comando sin respuesta antes de darlo por fallido. */
    public const MAX_INTENTOS = 5;

    protected $attributes = ['estado' => 'pendiente', 'intentos' => 0];

    protected $fillable = ['equipo_acceso_id', 'colaborador_id', 'tipo', 'pin', 'comando'];

    protected function casts(): array
    {
        return ['enviado_at' => 'datetime', 'respondido_at' => 'datetime', 'resultado' => 'integer', 'intentos' => 'integer'];
    }

    public function equipo(): BelongsTo
    {
        return $this->belongsTo(EquipoAcceso::class, 'equipo_acceso_id');
    }
}
