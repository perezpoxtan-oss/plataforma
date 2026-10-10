<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historial del candidato: cada cambio de etapa, aviso y respuesta, con quién y cuándo.
 */
class CandidatoEvento extends Model
{
    use PerteneceAEmpresa;

    /** Título de cada evento en el historial (los cambios de etapa muestran la etapa). */
    public const EVENTOS = [
        'registrado' => 'Registrado', 'visita' => 'Volvió a la caseta', 'postulacion' => 'Nueva postulación', 'recepcion' => 'Paso a Recursos Humanos',
        'autocaptura' => 'Llenó su solicitud', 'autocaptura_revisada' => 'Solicitud revisada', 'contratado' => 'Contratado',
        'evaluacion_rh' => 'Evaluación de RR. HH.', 'canalizado' => 'Canalizado al departamento', 'reprogramado' => 'Entrevista reprogramada',
        'evaluacion_departamento' => 'Evaluación del departamento', 'llegada_entrevista' => 'Llegó a su entrevista', 'correo_candidato' => 'Correo al candidato',
        // Proceso anterior (historial)
        'enviado_departamento' => 'Aviso al departamento', 'sin_responsable' => 'Departamento sin responsable',
    ];

    protected $table = 'candidato_eventos';

    public function titulo(): string
    {
        if (in_array($this->evento, ['etapa', 'registrado'], true) && $this->etapa_nueva !== null) {
            return Candidato::ETAPAS[$this->etapa_nueva] ?? $this->etapa_nueva;
        }

        return self::EVENTOS[$this->evento] ?? ucfirst(str_replace('_', ' ', (string) $this->evento));
    }

    protected $fillable = ['empresa_id', 'candidato_id', 'evento', 'etapa_anterior', 'etapa_nueva', 'comentario', 'user_id'];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
