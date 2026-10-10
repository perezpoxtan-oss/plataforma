<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Postulación: cada vez que una persona (su ficha, Candidato) aplica a una
 * vacante o «a lo que haya». Una vacante tiene muchas postulaciones y una
 * persona puede postularse varias veces (vuelve meses después, a otra vacante).
 *
 * Lo que avanza por etapas vive aquí (Candidato::ETAPAS / TRANSICIONES,
 * fase 2): la entrevista de RR. HH., la canalización al departamento con su
 * entrevistador y su cita, la evaluación del departamento y la elección. La
 * ficha conserva datos personales, solicitud, CV, documentos y firma, y lleva
 * un ESPEJO de la postulación activa (candidatos.etapa, vacante, fechas…)
 * para que las listas y filtros sigan igual: la que manda es la postulación
 * (Postulaciones::reflejar()).
 */
class Postulacion extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'postulaciones';

    protected $attributes = ['etapa' => 'registrado', 'origen' => 'caseta'];

    protected $fillable = ['empresa_id', 'sede_id', 'candidato_id', 'vacante_id', 'departamento_id', 'puesto_id', 'vacante', 'origen'];

    protected function casts(): array
    {
        return [
            'revision_en' => 'datetime', 'aprobado_rh_en' => 'datetime', 'entrevista_en' => 'datetime', 'decision_en' => 'datetime',
            'enviado_departamento_en' => 'datetime', 'respuesta_departamento_en' => 'datetime', 'contratado_en' => 'datetime',
            // Fase 2: entrevistas, canalización y elección
            'cita_en' => 'datetime', 'cita_ahora' => 'boolean', 'numero_entrevista' => 'integer', 'entrevista_rh_en' => 'datetime',
            'canalizado_en' => 'datetime', 'evaluado_en' => 'datetime', 'elegido_en' => 'datetime', 'no_se_presento_en' => 'datetime',
        ];
    }

    // -------------------------------------------------------------- Relaciones

    public function candidato(): BelongsTo
    {
        return $this->belongsTo(Candidato::class);
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class)->withTrashed();
    }

    public function vacantePublicada(): BelongsTo
    {
        return $this->belongsTo(Vacante::class, 'vacante_id');
    }

    public function departamento(): BelongsTo
    {
        return $this->belongsTo(Departamento::class);
    }

    public function puesto(): BelongsTo
    {
        return $this->belongsTo(Puesto::class);
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }

    public function decisionPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decision_por');
    }

    /** Quien entrevista en el departamento (lo asigna Recursos Humanos al canalizar). */
    public function entrevistador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entrevistador_id');
    }

    public function canalizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'canalizado_por');
    }

    /** Evaluaciones de sus entrevistas (RR. HH. y departamento), en orden. */
    public function evaluaciones(): HasMany
    {
        return $this->hasMany(EvaluacionCandidato::class)->orderBy('id');
    }

    /** Visitas a la caseta ligadas a esta postulación. */
    public function accesos(): HasMany
    {
        return $this->hasMany(Acceso::class)->orderBy('id');
    }

    // ------------------------------------------------------------------ Ayudas

    public function etiquetaEtapa(): string
    {
        return Candidato::ETAPAS[$this->etapa] ?? $this->etapa;
    }

    public function puedePasarA(string $etapa): bool
    {
        return in_array($etapa, Candidato::TRANSICIONES[$this->etapa] ?? [], true);
    }

    public function abierta(): bool
    {
        return in_array($this->etapa, Candidato::ABIERTAS, true);
    }

    /** ¿Ya pasó la hora de la cita? (para «No se presentó») */
    public function citaPasada(): bool
    {
        return $this->etapa === 'canalizado' && ($this->cita_ahora || ($this->cita_en !== null && $this->cita_en->lte(now())));
    }

    /** «Entrevista 2.ª», «1.ª»… */
    public function numeroTexto(): string
    {
        return max(1, (int) $this->numero_entrevista).'.ª';
    }

    /** A qué aplica: la vacante publicada, el puesto del catálogo o el texto libre. */
    public function titulo(): string
    {
        return $this->vacantePublicada?->titulo ?? $this->puesto?->nombre ?? $this->vacante ?? 'Sin vacante definida';
    }
}
