<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evaluación de una entrevista (candidatos, fase 2):
 *  - rh: la entrevista de filtro de Recursos Humanos (Canalizar al
 *    departamento / Considerar / Rechazar);
 *  - departamento: la del jefe o entrevistador que Recursos Humanos asignó
 *    (Elegir / Considerar / Segunda entrevista / Rechazar).
 *
 * criterios = {«nombre del criterio» => 1..5} con los criterios que la
 * empresa tenía configurados ese día (Recepción → Ajustes); promedio = su
 * promedio. numero = 1.ª, 2.ª entrevista de esa postulación. El comentario
 * es obligatorio para Considerar y Rechazar.
 */
class EvaluacionCandidato extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    public const TIPOS = ['rh' => 'Recursos Humanos', 'departamento' => 'Departamento'];

    /** Resultados de cada tipo, con su texto. */
    public const RESULTADOS = [
        'rh' => ['canalizar' => 'Canalizar al departamento', 'considerar' => 'Considerar', 'rechazar' => 'Rechazar'],
        'departamento' => ['elegir' => 'Elegir', 'considerar' => 'Considerar', 'segunda_entrevista' => 'Segunda entrevista', 'rechazar' => 'Rechazar'],
    ];

    /** Resultados que piden comentario. */
    public const CON_COMENTARIO = ['considerar', 'rechazar'];

    /** Estrellas de cada criterio. */
    public const MINIMO = 1;

    public const MAXIMO = 5;

    protected $table = 'evaluaciones_candidato';

    protected $fillable = ['empresa_id', 'sede_id', 'postulacion_id', 'candidato_id', 'tipo', 'evaluador_id', 'entrevista_en', 'criterios',
        'promedio', 'comentario', 'resultado', 'numero'];

    protected function casts(): array
    {
        return ['criterios' => 'array', 'promedio' => 'decimal:2', 'entrevista_en' => 'datetime', 'numero' => 'integer'];
    }

    public function postulacion(): BelongsTo
    {
        return $this->belongsTo(Postulacion::class);
    }

    public function candidato(): BelongsTo
    {
        return $this->belongsTo(Candidato::class);
    }

    public function evaluador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluador_id');
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class)->withTrashed();
    }

    public function etiquetaResultado(): string
    {
        return self::RESULTADOS[$this->tipo][$this->resultado] ?? (string) $this->resultado;
    }

    public function etiquetaTipo(): string
    {
        return self::TIPOS[$this->tipo] ?? (string) $this->tipo;
    }

    /** «4.2» (o «—» sin calificaciones). */
    public function promedioTexto(): string
    {
        return $this->promedio === null ? '—' : number_format((float) $this->promedio, 1);
    }
}
