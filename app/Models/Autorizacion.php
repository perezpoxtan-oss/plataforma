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
 *  - candidato: proceso anterior a la fase 2 de candidatos (Recursos Humanos
 *    lo aprobaba y el departamento respondía «Bajar a entrevistar»). Ya no se
 *    crean: ahora RR. HH. canaliza la entrevista (App\Services\Candidatos\Entrevistas).
 *    Las que existen son historial; las pendientes se cancelaron al cambiar de proceso;
 *  - recepcion: la caseta registró a alguien que viene con Recursos Humanos y
 *    la empresa pide que RR. HH. diga «Que pase» (Que pase / Que espere / No
 *    puede pasar). No es de un departamento (departamento_id = null): la
 *    responde quien atiende Recepción en esa sede. «Que espere» no la cierra:
 *    sigue pendiente y el acceso queda con autorizacion = «espera».
 *
 * Estados: pendiente → autorizada | entrevista | rechazada | cancelada.
 */
class Autorizacion extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    public const TIPOS = ['visita' => 'Visita', 'candidato' => 'Candidato (proceso anterior)', 'recepcion' => 'Recepción de RR. HH.'];

    public const ESTADOS = [
        'pendiente' => 'Esperando respuesta', 'autorizada' => 'Autorizada', 'entrevista' => 'Pidió entrevistarlo (proceso anterior)',
        'rechazada' => 'Rechazada', 'cancelada' => 'Cancelada',
    ];

    /** Respuestas posibles de cada tipo => estado al que lleva. */
    public const RESPUESTAS = [
        'visita' => ['autorizar' => 'autorizada', 'rechazar' => 'rechazada'],
        // Proceso anterior (historial): ya no se responden
        'candidato' => [],
        // «Que espere» no cambia el estado: sigue pendiente (ver Autorizaciones::responder)
        'recepcion' => ['pase' => 'autorizada', 'espere' => 'pendiente', 'no_pasa' => 'rechazada'],
    ];

    /** Respuestas que niegan (botón rojo y confirmación). */
    public const NEGATIVAS = ['rechazar', 'no_pasa'];

    /** Texto de cada botón de respuesta. */
    public const BOTONES = ['autorizar' => 'Autorizar ingreso', 'rechazar' => 'Rechazar',
        'pase' => 'Que pase', 'espere' => 'Que espere', 'no_pasa' => 'No puede pasar'];

    public const MEDIOS = ['plataforma' => 'Plataforma', 'correo' => 'Correo', 'caseta' => 'Caseta', 'rh' => 'Recursos Humanos', 'sistema' => 'Cambio de proceso'];

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
        if ($this->tipo === 'recepcion') {
            return (string) ($this->candidato?->nombre_completo ?? mb_convert_case(mb_strtolower((string) $this->acceso?->nombre), MB_CASE_TITLE));
        }

        return (string) ($this->tipo === 'candidato' ? $this->candidato?->nombre_completo : $this->acceso?->nombre);
    }

    /** Minutos de espera hasta la respuesta (o hasta ahora si sigue pendiente). */
    public function minutosEspera(): int
    {
        return (int) max(0, $this->solicitada_en->diffInMinutes($this->respondida_en ?? now()));
    }
}
