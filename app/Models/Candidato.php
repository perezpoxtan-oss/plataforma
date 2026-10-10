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
 * Una ficha por persona (fase 1): cada vez que aplica nace una POSTULACIÓN
 * (App\Models\Postulacion), que es la que avanza por etapas. Las columnas de
 * etapa, vacante, departamento, puesto y fechas de esta tabla son un ESPEJO
 * de la postulación activa (manda la postulación; ver
 * App\Services\Candidatos\Postulaciones).
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

    public const ORIGENES = ['caseta' => 'Caseta', 'rh' => 'Recursos Humanos', 'kiosco' => 'Kiosco (él mismo)', 'web' => 'Bolsa de trabajo (internet)'];

    // Solicitud de empleo formal (lección 36)

    public const SEXOS = ['mujer' => 'Mujer', 'hombre' => 'Hombre', 'no_decir' => 'Prefiero no decirlo'];

    public const ESTADOS_CIVILES = [
        'soltero' => 'Soltero(a)', 'casado' => 'Casado(a)', 'union_libre' => 'Unión libre', 'divorciado' => 'Divorciado(a)', 'viudo' => 'Viudo(a)',
    ];

    public const LICENCIAS = ['automovilista' => 'Automovilista', 'chofer' => 'Chofer', 'motociclista' => 'Motociclista', 'federal' => 'Federal'];

    /** Documento obtenido en cada renglón de escolaridad. */
    public const DOCUMENTOS_ESTUDIO = ['certificado' => 'Certificado', 'titulo' => 'Título', 'cedula' => 'Cédula profesional', 'trunco' => 'Trunco (sin terminar)'];

    public const MEDIOS_VACANTE = [
        'bolsa_web' => 'Página de empleos de la empresa', 'redes' => 'Redes sociales', 'recomendacion' => 'Me lo recomendó un conocido',
        'cartel' => 'Cartel en la entrada', 'periodico' => 'Periódico o bolsa de trabajo', 'otro' => 'Otro',
    ];

    public const FIRMAS_MEDIO = ['kiosco' => 'en el kiosco', 'web' => 'en la bolsa de trabajo', 'rh' => 'con Recursos Humanos'];

    /**
     * Datos personales sensibles: solo los ve quien tiene candidatos.ver (Recursos
     * Humanos). Nunca van a la auditoría (se enmascaran), al resumen del
     * departamento, a las notificaciones ni a los correos; en el CSV solo si se
     * pide expresamente (columnas marcadas).
     */
    public const SENSIBLES = ['curp', 'rfc', 'nss', 'calle_numero', 'colonia', 'codigo_postal', 'municipio', 'estado_domicilio', 'tiempo_residencia',
        'telefono_fijo', 'emergencia_nombre', 'emergencia_parentesco', 'emergencia_telefono'];
    // Fin Solicitud de empleo formal

    protected $table = 'candidatos';

    protected $attributes = ['etapa' => 'registrado', 'origen' => 'caseta', 'autocaptura_pendiente' => false];

    protected $fillable = [
        'empresa_id', 'sede_id', 'persona_id', 'acceso_id', 'departamento_id', 'puesto_id', 'vacante',
        'nombre_completo', 'telefono', 'correo', 'fecha_nacimiento', 'ciudad',
        'escolaridad', 'experiencia', 'habilidades', 'idiomas', 'disponibilidad', 'disponibilidad_notas', 'pretension', 'referencias',
        'notas_rh', 'origen', 'llegada_en',
        // Solicitud de empleo formal (lección 36)
        'nombre', 'apellido_paterno', 'apellido_materno', 'sexo', 'lugar_nacimiento', 'nacionalidad', 'estado_civil', 'dependientes',
        'curp', 'rfc', 'nss', 'licencia_tipo', 'licencia_vigencia',
        'calle_numero', 'colonia', 'codigo_postal', 'municipio', 'estado_domicilio', 'tiempo_residencia', 'telefono_fijo',
        'emergencia_nombre', 'emergencia_parentesco', 'emergencia_telefono', 'referencias_laborales',
        'medio_vacante', 'tiene_familiares', 'familiares_nombre', 'trabajo_antes_aqui', 'rolar_turnos', 'puede_viajar', 'cambiar_residencia',
        'fecha_inicio_posible',
    ];

    protected function casts(): array
    {
        return [
            'escolaridad' => 'array', 'experiencia' => 'array', 'referencias' => 'array', 'pretension' => 'decimal:2',
            'fecha_nacimiento' => 'date', 'autocaptura_pendiente' => 'boolean', 'autocaptura_en' => 'datetime',
            'privacidad_aceptada_en' => 'datetime', 'llegada_en' => 'datetime', 'avisado_rh_en' => 'datetime', 'revision_en' => 'datetime',
            'aprobado_rh_en' => 'datetime', 'enviado_departamento_en' => 'datetime', 'respuesta_departamento_en' => 'datetime',
            'entrevista_en' => 'datetime', 'decision_en' => 'datetime', 'contratado_en' => 'datetime',
            // Solicitud de empleo formal
            'referencias_laborales' => 'array', 'licencia_vigencia' => 'date', 'fecha_inicio_posible' => 'date', 'dependientes' => 'integer',
            'tiene_familiares' => 'boolean', 'trabajo_antes_aqui' => 'boolean', 'rolar_turnos' => 'boolean', 'puede_viajar' => 'boolean',
            'cambiar_residencia' => 'boolean', 'declaracion_aceptada_en' => 'datetime', 'firma_en' => 'datetime',
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

    /** Todas sus postulaciones, la más reciente primero. */
    public function postulaciones(): HasMany
    {
        return $this->hasMany(Postulacion::class)->orderByDesc('id');
    }

    /** La postulación activa (la más reciente): la que refleja esta ficha. */
    public function postulacionActiva(): HasOne
    {
        return $this->hasOne(Postulacion::class)->latestOfMany();
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

    /** Vacante de la bolsa de trabajo a la que aplica (la columna «vacante» es el texto libre de antes). */
    public function vacantePublicada(): BelongsTo
    {
        return $this->belongsTo(Vacante::class, 'vacante_id');
    }

    /** Quién de RR. HH. capturó la firma por el candidato (null = firmó él mismo). */
    public function firmaCapturadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'firma_capturada_por');
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

    /** Domicilio en una línea (para la hoja impresa y el alta como colaborador). */
    public function domicilioCompleto(): ?string
    {
        $partes = array_filter([
            $this->calle_numero, $this->colonia ? 'Col. '.$this->colonia : null, $this->codigo_postal ? 'C.P. '.$this->codigo_postal : null,
            $this->municipio, $this->estado_domicilio,
        ], fn ($p) => is_string($p) && trim($p) !== '');

        return $partes === [] ? null : implode(', ', $partes);
    }

    /**
     * Nombre y apellidos: los capturados por separado o, si solo hay nombre
     * completo (caseta), una sugerencia partiéndolo (RR. HH. lo confirma).
     *
     * @return array{nombre: string, paterno: string, materno: string}
     */
    public function partesNombre(): array
    {
        if ($this->nombre !== null && $this->nombre !== '') {
            return ['nombre' => $this->nombre, 'paterno' => (string) $this->apellido_paterno, 'materno' => (string) $this->apellido_materno];
        }
        $p = preg_split('/\s+/u', trim((string) $this->nombre_completo)) ?: [];
        if (count($p) >= 3) {
            $materno = array_pop($p);
            $paterno = array_pop($p);

            return ['nombre' => implode(' ', $p), 'paterno' => $paterno, 'materno' => $materno];
        }

        return ['nombre' => $p[0] ?? '', 'paterno' => $p[1] ?? '', 'materno' => ''];
    }

    /** ¿Ya firmó la solicitud (declaración aceptada y firma guardada)? */
    public function solicitudFirmada(): bool
    {
        return $this->firma_ruta !== null && $this->declaracion_aceptada_en !== null;
    }

    /** Minutos desde que llegó (o se registró) hasta ahora. */
    public function minutosEsperando(): int
    {
        return (int) max(0, ($this->llegada_en ?? $this->created_at ?? now())->diffInMinutes(now()));
    }
}
