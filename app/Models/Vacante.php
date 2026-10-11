<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Vacante de la bolsa de trabajo (lección 36). Recursos Humanos la captura,
 * la publica (aparece en /empleos/{empresa} y en el cartel con QR), la pausa
 * o la cierra (cubierta o cancelada). Los candidatos que se postulan por
 * internet, los que registra la caseta o los que RR. HH. liga quedan con
 * vacante_id.
 *
 * Fase 2 de candidatos: «plazas» decide cuándo se cubre (al elegir a tantas
 * personas como plazas, las demás postulaciones con el departamento pasan a
 * «Considerar») y «jefe_ve_cv» si quien entrevista ve el CV en PDF.
 *
 * Estados (ver TRANSICIONES): Borrador → Publicada ⇄ Pausada → Cerrada;
 * Cerrada → Borrador (reabrir). Borrador → Cerrada solo como «cancelada».
 */
class Vacante extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    public const ESTADOS = ['borrador' => 'Borrador', 'publicada' => 'Publicada', 'pausada' => 'Pausada', 'cerrada' => 'Cerrada'];

    /** Color de la pastilla de cada estado. */
    public const COLORES = ['borrador' => 'gris', 'publicada' => 'verde', 'pausada' => 'ambar', 'cerrada' => 'rojo'];

    public const TRANSICIONES = [
        'borrador' => ['publicada', 'cerrada'],
        'publicada' => ['pausada', 'cerrada'],
        'pausada' => ['publicada', 'cerrada'],
        'cerrada' => ['borrador'],
    ];

    /** Texto del botón que lleva a cada estado (según desde dónde). */
    public const BOTONES = ['publicada' => 'Publicar', 'pausada' => 'Pausar', 'cerrada' => 'Cerrar', 'borrador' => 'Reabrir como borrador'];

    public const MOTIVOS_CIERRE = ['cubierta' => 'Cubierta (ya se contrató)', 'cancelada' => 'Cancelada'];

    public const CONTRATOS = ['indeterminado' => 'Tiempo indeterminado (planta)', 'determinado' => 'Tiempo determinado', 'temporada' => 'Por temporada', 'practicas' => 'Prácticas profesionales'];

    public const JORNADAS = ['completa' => 'Tiempo completo', 'medio_tiempo' => 'Medio tiempo', 'por_horas' => 'Por horas', 'fines_semana' => 'Fines de semana'];

    public const PERIODOS = ['mensual' => 'al mes', 'quincenal' => 'a la quincena', 'semanal' => 'a la semana'];

    protected $table = 'vacantes';

    protected $attributes = ['estado' => 'borrador', 'plazas' => 1, 'jefe_ve_cv' => false, 'todas_las_sedes' => false, 'sueldo_periodo' => 'mensual', 'sueldo_a_tratar' => false];

    protected $fillable = [
        'empresa_id', 'titulo', 'puesto_id', 'departamento_id', 'plazas', 'jefe_ve_cv', 'todas_las_sedes', 'tipo_contrato', 'jornada', 'turno_id', 'horario',
        'sueldo_min', 'sueldo_max', 'sueldo_periodo', 'sueldo_a_tratar', 'descripcion', 'requisitos', 'prestaciones', 'escolaridad_minima', 'experiencia',
        'fecha_publicacion', 'fecha_cierre', 'contacto_nombre', 'contacto_telefono', 'contacto_correo',
    ];

    protected function casts(): array
    {
        return [
            'todas_las_sedes' => 'boolean', 'sueldo_a_tratar' => 'boolean', 'jefe_ve_cv' => 'boolean', 'requisitos' => 'array', 'prestaciones' => 'array',
            'sueldo_min' => 'decimal:2', 'sueldo_max' => 'decimal:2', 'plazas' => 'integer',
            'fecha_publicacion' => 'date', 'fecha_cierre' => 'date', 'publicada_en' => 'datetime', 'cerrada_en' => 'datetime',
        ];
    }

    // -------------------------------------------------------------- Relaciones

    public function sedes(): BelongsToMany
    {
        return $this->belongsToMany(Sede::class, 'sede_vacante');
    }

    public function puesto(): BelongsTo
    {
        return $this->belongsTo(Puesto::class);
    }

    public function departamento(): BelongsTo
    {
        return $this->belongsTo(Departamento::class);
    }

    public function turno(): BelongsTo
    {
        return $this->belongsTo(Turno::class);
    }

    public function candidatos(): HasMany
    {
        return $this->hasMany(Candidato::class);
    }

    /** Postulaciones a esta vacante (una persona puede tener varias en el tiempo). */
    public function postulaciones(): HasMany
    {
        return $this->hasMany(Postulacion::class);
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function editadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizado_por');
    }

    // ------------------------------------------------------------------ Scopes

    /**
     * Vacantes que aplican en alguna de estas sedes.
     *
     * @param  array<int>  $sedeIds
     */
    public function scopeAplicanEn(Builder $consulta, array $sedeIds): Builder
    {
        return $consulta->where(fn ($q) => $q->where('vacantes.todas_las_sedes', true)
            ->orWhereHas('sedes', fn ($s) => $s->whereIn('sedes.id', $sedeIds)));
    }

    /**
     * Publicadas y vigentes en la fecha local $hoy (Y-m-d): ya empezó su
     * publicación y no ha pasado su fecha de cierre.
     */
    public function scopeVigentes(Builder $consulta, string $hoy): Builder
    {
        return $consulta->where('vacantes.estado', 'publicada')
            ->where(fn ($q) => $q->whereNull('vacantes.fecha_publicacion')->orWhereDate('vacantes.fecha_publicacion', '<=', $hoy))
            ->where(fn ($q) => $q->whereNull('vacantes.fecha_cierre')->orWhereDate('vacantes.fecha_cierre', '>=', $hoy));
    }

    // ------------------------------------------------------------------ Ayudas

    public function etiquetaEstado(): string
    {
        if ($this->estado === 'cerrada' && $this->cierre_motivo) {
            return 'Cerrada · '.($this->cierre_motivo === 'cubierta' ? 'cubierta' : 'cancelada');
        }

        return self::ESTADOS[$this->estado] ?? $this->estado;
    }

    public function puedePasarA(string $estado): bool
    {
        return in_array($estado, self::TRANSICIONES[$this->estado] ?? [], true);
    }

    /** ¿Está publicada y dentro de sus fechas (en la fecha local $hoy)? */
    public function vigente(string $hoy): bool
    {
        return $this->estado === 'publicada'
            && ($this->fecha_publicacion === null || $this->fecha_publicacion->format('Y-m-d') <= $hoy)
            && ($this->fecha_cierre === null || $this->fecha_cierre->format('Y-m-d') >= $hoy);
    }

    /** «$9,000 a $11,000 al mes», «Desde $9,000 al mes», «A tratar». */
    public function sueldoTexto(): string
    {
        $dinero = fn ($v) => '$'.number_format((float) $v, 0);
        $periodo = self::PERIODOS[$this->sueldo_periodo] ?? 'al mes';
        $texto = match (true) {
            $this->sueldo_min !== null && $this->sueldo_max !== null && (float) $this->sueldo_min !== (float) $this->sueldo_max => $dinero($this->sueldo_min).' a '.$dinero($this->sueldo_max).' '.$periodo,
            $this->sueldo_min !== null => ($this->sueldo_max !== null ? '' : 'Desde ').$dinero($this->sueldo_min).' '.$periodo,
            $this->sueldo_max !== null => 'Hasta '.$dinero($this->sueldo_max).' '.$periodo,
            default => null,
        };
        if ($texto === null) {
            return $this->sueldo_a_tratar ? 'Sueldo a tratar' : 'Sueldo por definir';
        }

        return $texto.($this->sueldo_a_tratar ? ' (a tratar)' : '');
    }

    /** Nombres de las sedes donde aplica (requiere sedes cargadas). */
    public function sedesTexto(): string
    {
        return $this->todas_las_sedes ? 'Todas las sedes' : ($this->sedes->pluck('nombre')->join(', ') ?: 'Sin sede');
    }

    /** @return list<string> */
    public function listaRequisitos(): array
    {
        return array_values(array_filter((array) $this->requisitos, 'is_string'));
    }

    /** @return list<string> */
    public function listaPrestaciones(): array
    {
        return array_values(array_filter((array) $this->prestaciones, 'is_string'));
    }
}
