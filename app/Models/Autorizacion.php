<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Solicitud de autorización al responsable de un departamento:
 *  - visita: la caseta registró a alguien que va a ese departamento y espera
 *    «Esperando autorización» (Autorizar ingreso / Rechazar);
 *  - candidato: Recursos Humanos lo aprobó y el departamento decide
 *    (Bajar a entrevistar / Rechazar).
 *
 * Estados: pendiente → autorizada | entrevista | rechazada | cancelada.
 */
class Autorizacion extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    public const TIPOS = ['visita' => 'Visita', 'candidato' => 'Candidato'];

    public const ESTADOS = [
        'pendiente' => 'Esperando respuesta', 'autorizada' => 'Autorizada', 'entrevista' => 'Bajar a entrevistar',
        'rechazada' => 'Rechazada', 'cancelada' => 'Cancelada',
    ];

    /** Respuestas posibles de cada tipo => estado al que lleva. */
    public const RESPUESTAS = [
        'visita' => ['autorizar' => 'autorizada', 'rechazar' => 'rechazada'],
        'candidato' => ['entrevistar' => 'entrevista', 'rechazar' => 'rechazada'],
    ];

    /** Texto de cada botón de respuesta. */
    public const BOTONES = ['autorizar' => 'Autorizar ingreso', 'entrevistar' => 'Bajar a entrevistar', 'rechazar' => 'Rechazar'];

    public const MEDIOS = ['plataforma' => 'Plataforma', 'correo' => 'Correo', 'caseta' => 'Caseta', 'rh' => 'Recursos Humanos'];

    protected $table = 'autorizaciones';

    protected $attributes = ['estado' => 'pendiente'];

    protected $fillable = ['empresa_id', 'sede_id', 'departamento_id', 'tipo', 'acceso_id', 'candidato_id', 'solicitada_en', 'avisados'];

    protected function casts(): array
    {
        return ['solicitada_en' => 'datetime', 'respondida_en' => 'datetime', 'avisados' => 'array'];
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class)->withTrashed();
    }

    public function departamento(): BelongsTo
    {
        return $this->belongsTo(Departamento::class);
    }

    public function acceso(): BelongsTo
    {
        return $this->belongsTo(Acceso::class);
    }

    public function candidato(): BelongsTo
    {
        return $this->belongsTo(Candidato::class);
    }

    public function respondidaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'respondida_por');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function pendiente(): bool
    {
        return $this->estado === 'pendiente';
    }

    /** Nombre de quien espera (visitante o candidato). */
    public function titulo(): string
    {
        return (string) ($this->tipo === 'candidato' ? $this->candidato?->nombre_completo : $this->acceso?->nombre);
    }

    /** Minutos de espera hasta la respuesta (o hasta ahora si sigue pendiente). */
    public function minutosEspera(): int
    {
        return (int) max(0, $this->solicitada_en->diffInMinutes($this->respondida_en ?? now()));
    }
}
