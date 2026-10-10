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
 * Lo que avanza por etapas vive aquí (mismas etapas y transiciones que
 * Candidato::ETAPAS / TRANSICIONES; la fase 2 las cambiará). La ficha
 * conserva datos personales, solicitud, CV, documentos y firma, y lleva un
 * ESPEJO de la postulación activa (candidatos.etapa, vacante, fechas…) para
 * que las listas y filtros de siempre sigan igual: la que manda es la
 * postulación (Postulaciones::reflejar()).
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

    /** A qué aplica: la vacante publicada, el puesto del catálogo o el texto libre. */
    public function titulo(): string
    {
        return $this->vacantePublicada?->titulo ?? $this->puesto?->nombre ?? $this->vacante ?? 'Sin vacante definida';
    }
}
