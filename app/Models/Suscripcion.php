<?php

namespace App\Models;

use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Suscripción de una empresa cliente (una por empresa). La administra solo el
 * Super Administrador; el Administrador del cliente la ve en «Empresas».
 *
 * No usa el filtro de empresa (como Empresa): es un dato de la PLATAFORMA
 * sobre el cliente, no un dato operativo del cliente. Quien la lee para un
 * usuario de empresa siempre filtra por su empresa_id.
 *
 * Estados (ver docs/tecnico/suscripciones.md):
 *   prueba → (vence) en_gracia → (fin de gracia) solo_consulta → (pago) activa
 *   activa → por_vencer (N días antes) → en_gracia → solo_consulta
 *   cancelada / suspendida: solo consulta puesta a mano.
 * El estado efectivo se calcula con las fechas en cada petición; la tarea
 * diaria solo lo guarda y manda los avisos.
 */
class Suscripcion extends Model
{
    use RegistraAutor;

    public const PRUEBA = 'prueba';

    public const ACTIVA = 'activa';

    public const POR_VENCER = 'por_vencer';

    public const EN_GRACIA = 'en_gracia';

    public const SOLO_CONSULTA = 'solo_consulta';

    public const CANCELADA = 'cancelada';

    public const ESTADOS = [
        self::PRUEBA => 'En prueba',
        self::ACTIVA => 'Activa',
        self::POR_VENCER => 'Por vencer',
        self::EN_GRACIA => 'En periodo de gracia',
        self::SOLO_CONSULTA => 'Solo consulta',
        self::CANCELADA => 'Cancelada',
    ];

    /** Clase de la etiqueta de estado (verde, ámbar, rojo). */
    public const COLORES = [
        self::PRUEBA => 'pendiente', self::ACTIVA => 'activo', self::POR_VENCER => 'pendiente',
        self::EN_GRACIA => 'pendiente', self::SOLO_CONSULTA => 'inactivo', self::CANCELADA => 'inactivo',
    ];

    public const PERIODICIDADES = ['mensual' => 'Mensual', 'anual' => 'Anual'];

    /** Zona con la que se cuentan los días de vigencia (la plataforma guarda en UTC). */
    public const ZONA = 'America/Mexico_City';

    protected $table = 'suscripciones';

    protected $attributes = [
        'sedes_contratadas' => 1, 'periodicidad' => 'mensual', 'moneda' => 'MXN', 'estado' => self::ACTIVA,
        'es_prueba' => false, 'suspendida' => false,
    ];

    protected $fillable = [
        'empresa_id', 'plan_id', 'solicitud_compra_id', 'sedes_contratadas', 'periodicidad', 'moneda', 'iva_porcentaje',
        'precio_subtotal', 'precio_iva', 'precio_total', 'detalle', 'promocion_id', 'promocion_hasta', 'estado', 'es_prueba', 'suspendida',
        'inicio_en', 'vence_en', 'gracia_hasta', 'avisos_enviados', 'notas',
    ];

    protected function casts(): array
    {
        return [
            'sedes_contratadas' => 'integer', 'iva_porcentaje' => 'decimal:2', 'precio_subtotal' => 'decimal:2', 'precio_iva' => 'decimal:2',
            'precio_total' => 'decimal:2', 'detalle' => 'array', 'promocion_hasta' => 'date', 'es_prueba' => 'boolean', 'suspendida' => 'boolean',
            'inicio_en' => 'date', 'vence_en' => 'date', 'gracia_hasta' => 'date', 'avisos_enviados' => 'array', 'revisado_en' => 'datetime',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class)->withTrashed();
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(SolicitudCompra::class, 'solicitud_compra_id');
    }

    public function promocion(): BelongsTo
    {
        return $this->belongsTo(Promocion::class);
    }

    public function paquetes(): BelongsToMany
    {
        return $this->belongsToMany(Paquete::class, 'suscripcion_paquete');
    }

    public function excepciones(): HasMany
    {
        return $this->hasMany(SuscripcionExcepcion::class);
    }

    public function historial(): HasMany
    {
        return $this->hasMany(SuscripcionHistorial::class)->orderByDesc('id');
    }

    public function revisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisado_por');
    }

    /** Fecha de hoy en la zona de la plataforma, como fecha sin hora (comparable con vence_en). */
    public static function hoy(): Carbon
    {
        return Carbon::parse(now(self::ZONA)->toDateString());
    }

    /**
     * Estado según las fechas de hoy (lo que realmente vale). Cancelada y
     * suspendida a mano no cambian con las fechas.
     *
     * @param  int  $diasAviso  días antes del vencimiento en que pasa a «por vencer»
     */
    public function estadoEfectivo(?Carbon $hoy = null, int $diasAviso = 7): string
    {
        if ($this->estado === self::CANCELADA) {
            return self::CANCELADA;
        }
        if ($this->suspendida) {
            return self::SOLO_CONSULTA;
        }
        $hoy = ($hoy ?? self::hoy())->copy()->startOfDay();
        $vence = $this->vence_en->copy()->startOfDay();
        if ($hoy->lte($vence)) {
            if ($this->es_prueba) {
                return self::PRUEBA;
            }

            return $hoy->diffInDays($vence) <= $diasAviso ? self::POR_VENCER : self::ACTIVA;
        }
        $gracia = ($this->gracia_hasta ?? $vence)->copy()->startOfDay();

        return $hoy->lte($gracia) ? self::EN_GRACIA : self::SOLO_CONSULTA;
    }

    /** ¿Solo puede ver y exportar? */
    public function soloConsulta(?Carbon $hoy = null): bool
    {
        return in_array($this->estadoEfectivo($hoy), [self::SOLO_CONSULTA, self::CANCELADA], true);
    }

    public function estadoTexto(?string $estado = null): string
    {
        $estado ??= $this->estado;

        return self::ESTADOS[$estado] ?? ucfirst((string) $estado);
    }

    public function periodicidadTexto(): string
    {
        return self::PERIODICIDADES[$this->periodicidad] ?? (string) $this->periodicidad;
    }

    /** Días que faltan para vencer (negativo = ya venció). */
    public function diasParaVencer(?Carbon $hoy = null): int
    {
        $hoy = ($hoy ?? self::hoy())->copy()->startOfDay();

        return (int) $hoy->diffInDays($this->vence_en->copy()->startOfDay(), false);
    }
}
