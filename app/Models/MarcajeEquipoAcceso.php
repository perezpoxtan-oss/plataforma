<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marcaje recibido de un equipo, una sola vez (`unique(equipo_acceso_id,
 * clave)`): lo que se hizo con él (entrada, salida, rechazado y por qué).
 * El identificador se guarda enmascarado (solo los últimos 4).
 */
class MarcajeEquipoAcceso extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'equipos_acceso_marcajes';

    public const RESULTADOS = [
        'entrada' => 'Entrada registrada',
        'salida' => 'Salida registrada',
        'desconocido' => 'No se encontró a quién pertenece',
        'no_vigente' => 'Credencial no vigente',
        'otra_sede' => 'No pertenece a esta sede',
        'sin_entrada' => 'Salida sin entrada registrada',
        'ignorado' => 'Evento del equipo que no es un marcaje',
    ];

    protected $fillable = ['equipo_acceso_id', 'clave', 'marcado_at', 'identificador', 'sentido', 'resultado', 'detalle', 'colaborador_id', 'acceso_id'];

    protected function casts(): array
    {
        return ['marcado_at' => 'datetime'];
    }

    public function equipo(): BelongsTo
    {
        return $this->belongsTo(EquipoAcceso::class, 'equipo_acceso_id');
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }

    /** «••••5678»: nunca el número completo de una tarjeta. */
    public static function enmascarar(?string $valor): ?string
    {
        $valor = trim((string) $valor);
        if ($valor === '') {
            return null;
        }

        return mb_strlen($valor) <= 4 ? '••'.$valor : '••••'.mb_substr($valor, -4);
    }
}
