<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Movimiento de la Bitácora de transporte (SEGCAT: bitacora_transporte): la
 * llegada o la salida de la unidad de una ruta, o —si la unidad no llegó— un
 * vale de taxi (uno por taxi) con sus pasajeros, monto y destino.
 *
 * Estados: vigente o anulado (nunca se borra); un vale de taxi además puede
 * estar pendiente o autorizado (Vo.Bo.).
 */
class MovimientoTransporte extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    /** Textos de SEGCAT ("LLEGADA (Al Hotel)" ahora dice Sede). */
    public const TIPOS = ['llegada' => 'LLEGADA', 'salida' => 'SALIDA'];

    public const ESTATUS = [
        'a_tiempo' => 'A TIEMPO',
        'retraso' => 'RETRASO',
        'no_llego' => 'NO LLEGO (USO DE TAXIS)',
    ];

    /** Tipo de unidad que se captura => [etiqueta, tipo del padrón vehicular, descripción si es "otro"]. */
    public const TIPOS_UNIDAD = [
        'autobus' => ['Autobús', 'autobus', null],
        'van' => ['Van / Urvan', 'otro', 'VAN / URVAN'],
        'otro' => ['Otro', 'otro', 'OTRO'],
    ];

    public const TIPOS_TAXI = [
        'sedan' => ['Sedán', 'sedan', null],
        'suv' => ['SUV / Camioneta', 'suv', null],
        'van' => ['Van / Urvan', 'otro', 'VAN / URVAN'],
        'otro' => ['Otro', 'otro', 'OTRO'],
    ];

    protected $table = 'movimientos_transporte';

    protected $attributes = ['anulado' => false, 'cantidad_pax' => 0];

    protected $fillable = [
        'empresa_id', 'sede_id', 'ruta_id', 'ruta_horario_id', 'tipo_movimiento', 'estatus', 'fecha',
        'vehiculo_id', 'chofer_id', 'cantidad_pax', 'monto', 'justificacion', 'paradero_id',
        'firma_guardia', 'firma_taxista', 'observaciones', 'lote',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'anulado' => 'boolean',
            'anulado_en' => 'datetime',
            'editado_en' => 'datetime',
            'autorizado_en' => 'datetime',
            'monto' => 'decimal:2',
            'cantidad_pax' => 'integer',
        ];
    }

    /**
     * "fecha" se guarda solo como AAAA-MM-DD (sin hora) en cualquier motor,
     * para que los filtros por día (BETWEEN) funcionen igual en MySQL y SQLite.
     */
    public function setFechaAttribute(mixed $valor): void
    {
        $this->attributes['fecha'] = $valor === null ? null : Carbon::parse($valor)->toDateString();
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    public function ruta(): BelongsTo
    {
        return $this->belongsTo(Ruta::class);
    }

    public function horario(): BelongsTo
    {
        return $this->belongsTo(RutaHorario::class, 'ruta_horario_id');
    }

    public function vehiculo(): BelongsTo
    {
        return $this->belongsTo(Vehiculo::class);
    }

    public function chofer(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'chofer_id');
    }

    public function paradero(): BelongsTo
    {
        return $this->belongsTo(Paradero::class);
    }

    public function pasajeros(): BelongsToMany
    {
        return $this->belongsToMany(Colaborador::class, 'movimiento_transporte_pasajeros', 'movimiento_transporte_id', 'colaborador_id')
            ->orderBy('colaboradores.nombre')->orderBy('colaboradores.apellido_paterno');
    }

    public function registro(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editado_por');
    }

    public function anulador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulado_por');
    }

    public function autorizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autorizado_por');
    }

    public function esTaxi(): bool
    {
        return $this->estatus === 'no_llego';
    }

    public function esLlegada(): bool
    {
        return $this->tipo_movimiento === 'llegada';
    }

    public function autorizado(): bool
    {
        return $this->autorizado_en !== null;
    }

    /** "#000018", como el folio de SEGCAT. */
    public function folio(): string
    {
        return '#'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function etiquetaTipo(): string
    {
        return self::TIPOS[$this->tipo_movimiento] ?? mb_strtoupper((string) $this->tipo_movimiento);
    }

    public function etiquetaEstatus(): string
    {
        return self::ESTATUS[$this->estatus] ?? mb_strtoupper((string) $this->estatus);
    }

    /** "Autobús", "Van / Urvan", "Taxi"… según la unidad del padrón. */
    public function etiquetaUnidad(): string
    {
        if ($this->esTaxi()) {
            return 'Taxi';
        }
        $v = $this->vehiculo;
        if ($v === null) {
            return 'Unidad';
        }

        return $v->tipo === 'otro' && $v->descripcion_otro ? mb_convert_case(mb_strtolower($v->descripcion_otro), MB_CASE_TITLE) : (Vehiculo::TIPOS[$v->tipo] ?? 'Unidad');
    }
}
