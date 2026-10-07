<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Ficha del candidato (CV digital). Nace cuando la caseta registra a alguien
 * que viene a Recursos Humanos como candidato (ligado a su acceso y al Padrón
 * de personas), cuando RR. HH. lo captura o cuando él mismo llena su CV en el
 * kiosco. Es dato personal: solo lo ven Recursos Humanos y, en resumen, el
 * responsable del departamento al que aplica.
 *
 * Etapas (ver TRANSICIONES):
 *   Registrado → En revisión RR. HH. → Aprobado por RR. HH. (enviado al departamento)
 *   → Entrevista → Seleccionado → Contratado
 *   y en cualquier momento antes de contratar: Descartado o En cartera.
 */
class Candidato extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    public const ETAPAS = [
        'registrado' => 'Registrado',
        'revision' => 'En revisión RR. HH.',
        'aprobado_rh' => 'Aprobado por RR. HH.',
        'entrevista' => 'Entrevista',
        'seleccionado' => 'Seleccionado',
        'contratado' => 'Contratado',
        'cartera' => 'En cartera',
        'descartado' => 'Descartado',
    ];

    /** Color de la pastilla de cada etapa. */
    public const COLORES = [
        'registrado' => 'gris', 'revision' => 'azul', 'aprobado_rh' => 'indigo', 'entrevista' => 'ambar',
        'seleccionado' => 'verde', 'contratado' => 'esmeralda', 'cartera' => 'cian', 'descartado' => 'rojo',
    ];

    /**
     * A qué etapas se puede pasar desde cada una. «Contratado» solo con el
     * botón Contratar (crea el colaborador); la respuesta del departamento
     * mueve «Aprobado por RR. HH.» a «Entrevista» o a «En cartera».
     */
    public const TRANSICIONES = [
        'registrado' => ['revision', 'cartera', 'descartado'],
        'revision' => ['aprobado_rh', 'entrevista', 'cartera', 'descartado'],
        'aprobado_rh' => ['entrevista', 'revision', 'cartera', 'descartado'],
        'entrevista' => ['seleccionado', 'cartera', 'descartado'],
        'seleccionado' => ['entrevista', 'cartera', 'descartado'],
        'cartera' => ['revision', 'descartado'],
        'descartado' => ['revision'],
        'contratado' => [],
    ];

    /** Texto del botón que lleva a cada etapa. */
    public const BOTONES_ETAPA = [
        'revision' => 'Tomar en revisión', 'aprobado_rh' => 'Aprobar y enviar al departamento', 'entrevista' => 'Pasar a entrevista',
        'seleccionado' => 'Seleccionar', 'cartera' => 'Guardar en cartera', 'descartado' => 'Descartar',
    ];

    /** Etapas en las que el candidato sigue «en proceso». */
    public const ABIERTAS = ['registrado', 'revision', 'aprobado_rh', 'entrevista', 'seleccionado'];

    public const ESCOLARIDAD = [
        'primaria' => 'Primaria', 'secundaria' => 'Secundaria', 'bachillerato' => 'Bachillerato / Preparatoria', 'tecnico' => 'Carrera técnica',
        'licenciatura' => 'Licenciatura', 'posgrado' => 'Maestría / Posgrado',
    ];

    public const DISPONIBILIDAD = [
        'inmediata' => 'Inmediata', 'una_semana' => 'En una semana', 'quince_dias' => 'En 15 días', 'un_mes' => 'En un mes o más',
    ];

    public const ORIGENES = ['caseta' => 'Caseta', 'rh' => 'Recursos Humanos', 'kiosco' => 'Kiosco (él mismo)'];

    protected $table = 'candidatos';

    protected $attributes = ['etapa' => 'registrado', 'origen' => 'caseta', 'autocaptura_pendiente' => false];

    protected $fillable = [
        'empresa_id', 'sede_id', 'persona_id', 'acceso_id', 'departamento_id', 'puesto_id', 'vacante',
        'nombre_completo', 'telefono', 'correo', 'fecha_nacimiento', 'ciudad',
        'escolaridad', 'experiencia', 'habilidades', 'idiomas', 'disponibilidad', 'disponibilidad_notas', 'pretension', 'referencias',
        'notas_rh', 'origen', 'llegada_en',
    ];

    protected function casts(): array
    {
        return [
            'escolaridad' => 'array', 'experiencia' => 'array', 'referencias' => 'array', 'pretension' => 'decimal:2',
            'fecha_nacimiento' => 'date', 'autocaptura_pendiente' => 'boolean', 'autocaptura_en' => 'datetime',
            'privacidad_aceptada_en' => 'datetime', 'llegada_en' => 'datetime', 'avisado_rh_en' => 'datetime', 'revision_en' => 'datetime',
            'aprobado_rh_en' => 'datetime', 'enviado_departamento_en' => 'datetime', 'respuesta_departamento_en' => 'datetime',
            'entrevista_en' => 'datetime', 'decision_en' => 'datetime', 'contratado_en' => 'datetime',
        ];
    }

    // -------------------------------------------------------------- Relaciones

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class)->withTrashed();
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    public function acceso(): BelongsTo
    {
        return $this->belongsTo(Acceso::class);
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

    public function documentos(): HasMany
    {
        return $this->hasMany(CandidatoDocumento::class)->orderBy('id');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(CandidatoEvento::class)->orderByDesc('id');
    }

    public function autorizaciones(): HasMany
    {
        return $this->hasMany(Autorizacion::class)->orderByDesc('id');
    }

    /** La solicitud al departamento más reciente. */
    public function ultimaAutorizacion(): HasOne
    {
        return $this->hasOne(Autorizacion::class)->latestOfMany();
    }

    public function enlaces(): HasMany
    {
        return $this->hasMany(EnlaceKiosco::class)->orderByDesc('id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function editadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizado_por');
    }

    public function decisionPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decision_por');
    }

    // ------------------------------------------------------------------ Ayudas

    public function etiquetaEtapa(): string
    {
        return self::ETAPAS[$this->etapa] ?? $this->etapa;
    }

    /** Puesto al que aplica: el del catálogo o el texto libre. */
    public function puestoVisible(): ?string
    {
        return $this->puesto?->nombre ?? $this->vacante;
    }

    public function puedePasarA(string $etapa): bool
    {
        return in_array($etapa, self::TRANSICIONES[$this->etapa] ?? [], true);
    }

    public function enProceso(): bool
    {
        return in_array($this->etapa, self::ABIERTAS, true);
    }

    /** Escolaridad más alta capturada (para el resumen). */
    public function escolaridadMaxima(): ?string
    {
        $niveles = array_keys(self::ESCOLARIDAD);
        $mejor = null;
        foreach ((array) $this->escolaridad as $fila) {
            $nivel = is_array($fila) ? ($fila['nivel'] ?? null) : null;
            if ($nivel !== null && in_array($nivel, $niveles, true) && ($mejor === null || array_search($nivel, $niveles, true) > array_search($mejor, $niveles, true))) {
                $mejor = $nivel;
            }
        }

        return $mejor === null ? null : self::ESCOLARIDAD[$mejor];
    }

    /** Años de experiencia sumados (aproximado, para el resumen). */
    public function anosExperiencia(): int
    {
        return (int) array_sum(array_map(fn ($f) => is_array($f) ? (int) ($f['anos'] ?? 0) : 0, (array) $this->experiencia));
    }

    /** Minutos desde que llegó (o se registró) hasta ahora. */
    public function minutosEsperando(): int
    {
        return (int) max(0, ($this->llegada_en ?? $this->created_at ?? now())->diffInMinutes(now()));
    }
}
