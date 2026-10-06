<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Pase de salida (SEGCAT: pases_salida): autoriza y rastrea la salida física
 * de artículos de una sede.
 *
 * Circuito (v2):
 *   Solicitud → Aprobaciones (pasos configurables por empresa, en orden)
 *     → Salida (caseta de origen verifica cada artículo y firma)
 *     → En destino (si va a otra sede: la caseta destino confirma la llegada
 *       y, para regresar, autoriza la salida de regreso) / Fuera
 *     → Regreso (caseta de origen verifica lo que vuelve; admite regresos
 *       parciales) → Cerrado
 *   Rechazo: el pase vuelve al solicitante, que lo corrige y reenvía o lo cancela.
 *   Venta y traspaso definitivo no esperan regreso: al salir quedan cerrados.
 */
class PaseSalida extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'pases_salida';

    /** clave => texto (SEGCAT: $MOTIVOS_TXT) */
    public const MOTIVOS = [
        'prestamo' => 'Préstamo',
        'venta' => 'Venta',
        'consignacion' => 'Consignación',
        'devolucion' => 'Devolución',
        'reparacion' => 'Reparación',
        'traspaso_definitivo' => 'Traspaso Definitivo',
    ];

    /** Todo espera regreso EXCEPTO estos (un motivo nuevo pedirá regreso: mejor de más que perder el rastro). */
    public const SIN_REGRESO = ['venta', 'traspaso_definitivo'];

    /** clave => texto del selector "Tipo de Destino" */
    public const DESTINOS = [
        'sede' => 'Otra Sede de la Empresa',
        'proveedor' => 'Instalaciones de un Proveedor',
        'colaborador' => 'Un Colaborador se lo lleva (uso/resguardo personal)',
    ];

    public const PENDIENTE = 'pendiente_aprobacion';

    public const RECHAZADO = 'rechazado';

    public const CANCELADO = 'cancelado';

    public const APROBADO = 'aprobado';

    public const SALIO = 'salio';

    public const EN_DESTINO = 'en_destino';

    public const EN_TRANSITO_REGRESO = 'en_transito_regreso';

    public const REGRESO_PARCIAL = 'regreso_parcial';

    public const REGRESADO = 'regresado';

    /**
     * estado => [texto, clase de color] (SEGCAT: $ESTATUS_TXT y $ESTATUS_COLOR;
     * los colores están en plataforma.css: .pase-pendiente = #d97706, etc.).
     */
    public const ESTADOS = [
        self::PENDIENTE => ['Pendiente de Aprobación', 'pendiente'],
        self::RECHAZADO => ['Rechazado — devuelto al solicitante', 'rechazado'],
        self::CANCELADO => ['Cancelado', 'cancelado'],
        self::APROBADO => ['Aprobado, listo para salir', 'aprobado'],
        self::SALIO => ['Salió', 'salio'],
        self::EN_DESTINO => ['En destino, espera regreso', 'en-destino'],
        self::EN_TRANSITO_REGRESO => ['En tránsito de regreso', 'en-transito'],
        self::REGRESO_PARCIAL => ['Regreso parcial — faltan artículos', 'espera'],
        self::REGRESADO => ['Regresado / Cerrado', 'regresado'],
    ];

    /** Estados en que algún artículo está fuera de la propiedad. */
    public const FUERA = [self::SALIO, self::EN_DESTINO, self::EN_TRANSITO_REGRESO, self::REGRESO_PARCIAL];

    /**
     * Pasos físicos de caseta: paso => [título, texto del botón, sede que firma (origen|destino), firmas [rol => texto]].
     * Todos piden "pases_salida.firmar". Cada paso lo firman la persona que
     * entrega o se lleva el equipo y Seguridad (el usuario en sesión).
     */
    public const PASOS_FISICOS = [
        'salida' => ['Salida física', 'Registrar Salida', 'origen', [
            'salida_lleva' => 'Quien se lleva el equipo', 'salida_seguridad' => 'Seguridad (salida)',
        ]],
        'recepcion' => ['Recepción en destino', 'Confirmar Llegada', 'destino', [
            'destino_entrega' => 'Quien entrega en destino', 'destino_seguridad' => 'Seguridad (destino)',
        ]],
        'salida_regreso' => ['Salida de regreso (desde destino)', 'Autorizar Salida de Regreso', 'destino', [
            'retorno_lleva' => 'Quien lo trae de vuelta', 'retorno_seguridad' => 'Seguridad (salida de regreso)',
        ]],
        'regreso' => ['Regreso a origen', 'Registrar Regreso', 'origen', [
            'regreso_entrega' => 'Quien lo entrega al regresar', 'regreso_seguridad' => 'Seguridad (regreso)',
        ]],
    ];

    /**
     * Roles de firma de SEGCAT (pases registrados antes del circuito v2): se
     * conservan solo para mostrar sus firmas en la bitácora y en la hoja.
     */
    public const ROLES_ANTERIORES = [
        'jefe_depto' => 'Jefe de Departamento', 'contraloria_salida' => 'Contraloría', 'gerencia' => 'Gerencia',
        'solicitante_salida' => 'Solicitante', 'recibe_salida' => 'Recibe (se lleva el equipo)', 'seguridad_salida' => 'Seguridad',
        'entrega_destino' => 'Quien trasladó el equipo', 'recibe_destino' => 'Quien recibe en destino', 'seguridad_destino' => 'Seguridad', 'contraloria_destino' => 'Contraloría',
        'jefe_depto_salida_regreso' => 'Jefe de Departamento (destino)', 'contraloria_salida_regreso' => 'Contraloría (destino)', 'gerencia_salida_regreso' => 'Gerencia (destino)',
        'solicitante_salida_regreso' => 'Solicitante', 'seguridad_salida_regreso' => 'Seguridad', 'traslada_salida_regreso' => 'Quien lo traslada de vuelta',
        'recibe_regreso' => 'Recibe (en origen)', 'entrega_regreso' => 'Entrega (trae el equipo de vuelta)', 'seguridad_regreso' => 'Visto bueno de Seguridad', 'contraloria_regreso' => 'Contraloría',
    ];

    protected $attributes = ['estado' => self::PENDIENTE, 'requiere_regreso' => true, 'ronda' => 1, 'cerrado_con_faltantes' => false];

    protected $fillable = [
        'empresa_id', 'sede_id', 'motivo', 'colaborador_id', 'destino_tipo', 'sede_destino_id', 'proveedor_id',
        'colaborador_destino_id', 'destino_direccion', 'destino_telefono', 'fecha_salida_programada', 'fecha_tentativa_regreso',
    ];

    protected function casts(): array
    {
        return [
            'requiere_regreso' => 'boolean',
            'cerrado_con_faltantes' => 'boolean',
            'ronda' => 'integer',
            'fecha_salida_programada' => 'date',
            'fecha_tentativa_regreso' => 'date',
            'recordatorio_vencido_en' => 'date',
            'aprobado_en' => 'datetime',
            'rechazado_en' => 'datetime',
            'cancelado_en' => 'datetime',
            'salio_en' => 'datetime',
            'recibido_destino_en' => 'datetime',
            'salio_regreso_en' => 'datetime',
            'regreso_en' => 'datetime',
        ];
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class)->withTrashed();
    }

    public function sedeDestino(): BelongsTo
    {
        return $this->belongsTo(Sede::class, 'sede_destino_id')->withTrashed();
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_id');
    }

    public function colaboradorDestino(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_destino_id');
    }

    public function articulos(): HasMany
    {
        return $this->hasMany(PaseSalidaArticulo::class)->orderBy('id');
    }

    public function firmas(): HasMany
    {
        return $this->hasMany(PaseSalidaFirma::class)->orderBy('id');
    }

    /** Todas las aprobaciones de todas las rondas. */
    public function aprobaciones(): HasMany
    {
        return $this->hasMany(PaseSalidaAprobacion::class)->orderBy('ronda')->orderBy('orden');
    }

    public function bitacora(): HasMany
    {
        return $this->hasMany(PaseSalidaBitacora::class)->orderBy('id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizado_por');
    }

    public function rechazador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rechazado_por');
    }

    public static function requiereRegreso(?string $motivo): bool
    {
        return ! in_array($motivo, self::SIN_REGRESO, true);
    }

    public static function folioDe(int $numero): string
    {
        return 'PS-'.str_pad((string) $numero, 6, '0', STR_PAD_LEFT);
    }

    public function etiquetaMotivo(): string
    {
        return self::MOTIVOS[$this->motivo] ?? $this->motivo;
    }

    public function estaFuera(): bool
    {
        return in_array($this->estado, self::FUERA, true);
    }

    /** Terminó: regresó, salió sin esperar regreso, o se canceló. */
    public function cerrado(): bool
    {
        return $this->estado === self::REGRESADO || $this->estado === self::CANCELADO
            || ($this->estado === self::SALIO && ! $this->requiere_regreso);
    }

    /**
     * Fuera de la propiedad, espera regreso y su fecha tentativa ya pasó
     * ($hoy = fecha local de la sede de origen, "Y-m-d").
     */
    public function vencido(string $hoy): bool
    {
        return $this->estaFuera() && $this->requiere_regreso && $this->fecha_tentativa_regreso !== null
            && $this->fecha_tentativa_regreso->format('Y-m-d') < $hoy;
    }

    /**
     * Texto y clase de color de la insignia. Como en SEGCAT: "Salió" dice si
     * espera regreso o quedó cerrado, y "Vencido" manda sobre todo lo demás.
     *
     * @return array{0: string, 1: string}
     */
    public function insignia(bool $vencido): array
    {
        if ($vencido) {
            return ['Vencido — Debió Regresar', 'vencido'];
        }
        if ($this->estado === self::SALIO) {
            return $this->requiere_regreso ? ['Salió — Espera Regreso', 'espera'] : ['Salió — Cerrado', 'cerrado'];
        }
        if ($this->estado === self::REGRESADO && $this->cerrado_con_faltantes) {
            return ['Cerrado con faltantes', 'faltantes'];
        }

        return self::ESTADOS[$this->estado] ?? [$this->estado, 'otro'];
    }

    /**
     * Texto del botón de la ficha (SEGCAT: "Ver / Aprobar", "Ver / Registrar Salida"…).
     */
    public function textoBoton(): string
    {
        return match (true) {
            $this->estado === self::PENDIENTE => 'Ver / Aprobar',
            $this->estado === self::RECHAZADO => 'Ver / Corregir',
            $this->estado === self::APROBADO => 'Ver / Registrar Salida',
            $this->estado === self::SALIO && $this->requiere_regreso => 'Ver / Confirmar Llegada o Regreso',
            $this->estado === self::EN_DESTINO => 'Ver / Autorizar Salida de Regreso',
            in_array($this->estado, [self::EN_TRANSITO_REGRESO, self::REGRESO_PARCIAL], true) => 'Ver / Confirmar Regreso',
            default => 'Ver Detalle',
        };
    }

    /**
     * Paso físico de caseta que toca ahora, o null (en aprobación, rechazado,
     * cancelado o cerrado).
     */
    public function pasoFisico(): ?string
    {
        $haciaSede = $this->destino_tipo === 'sede';

        return match (true) {
            $this->estado === self::APROBADO => 'salida',
            $this->estado === self::SALIO && $this->requiere_regreso && $haciaSede => 'recepcion',
            $this->estado === self::SALIO && $this->requiere_regreso => 'regreso',
            $this->estado === self::EN_DESTINO && $haciaSede => 'salida_regreso',
            in_array($this->estado, [self::EN_TRANSITO_REGRESO, self::REGRESO_PARCIAL], true) => 'regreso',
            default => null,
        };
    }

    /** Sede donde se firma un paso físico: la destino recibe y autoriza la salida de regreso. */
    public function sedeDelPaso(string $paso): ?int
    {
        return (self::PASOS_FISICOS[$paso][2] ?? 'origen') === 'destino' ? $this->sede_destino_id : $this->sede_id;
    }

    /**
     * Pasos físicos que aplican a este pase, en orden.
     *
     * @return list<string>
     */
    public function pasosFisicos(): array
    {
        if (! $this->requiere_regreso) {
            return ['salida'];
        }

        return $this->destino_tipo === 'sede' ? ['salida', 'recepcion', 'salida_regreso', 'regreso'] : ['salida', 'regreso'];
    }

    /** Texto de una firma por su rol (circuito actual o el de SEGCAT). */
    public static function etiquetaRol(string $rol): string
    {
        foreach (self::PASOS_FISICOS as [, , , $roles]) {
            if (isset($roles[$rol])) {
                return $roles[$rol];
            }
        }

        return self::ROLES_ANTERIORES[$rol] ?? ($rol === 'aprobacion' ? 'Aprobación' : $rol);
    }

    /** A dónde va: nombre de la sede, del proveedor o del colaborador. */
    public function nombreDestino(): string
    {
        return match ($this->destino_tipo) {
            'sede' => $this->sedeDestino?->nombre ?? '—',
            'proveedor' => $this->proveedor?->nombre ?? '—',
            'colaborador' => $this->colaboradorDestino?->nombreCompleto() ?? '—',
            default => '—',
        };
    }

    /** Fecha sin hora a mediodía UTC, para que @fecha no la mueva de día por la zona horaria. */
    public static function dia(?Carbon $fecha): ?Carbon
    {
        return $fecha === null ? null : Carbon::createFromFormat('Y-m-d H:i:s', $fecha->format('Y-m-d').' 12:00:00', 'UTC');
    }
}
