<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Recorrido de Protección Civil (SEGCAT: recorridos_pc_sesiones): la ronda
 * de inspección de equipos de una sede (y, si se eligió, de un edificio o
 * zona).
 *
 * Estatus: nace EN PROCESO; se pueden agregar puntos (uno a la vez) tantas
 * veces como haga falta. «Finalizar Recorrido» (con al menos un equipo) lo
 * cierra como COMPLETO, o CON HALLAZGOS si algún punto quedó con falla. Un
 * recorrido finalizado ya no recibe puntos ni se vuelve a abrir.
 *
 * El primer hallazgo abre un ticket de Protección Civil en la Bitácora de
 * Novedades (novedad_id); los siguientes se anotan en ese mismo ticket.
 */
class RecorridoPc extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'recorridos_pc';

    public const EN_PROCESO = 'en_proceso';

    public const COMPLETO = 'completo';

    public const CON_HALLAZGOS = 'con_hallazgos';

    /** clave => texto en pantalla (SEGCAT). */
    public const ESTATUS = [
        self::EN_PROCESO => 'En Proceso',
        self::COMPLETO => 'Completo',
        self::CON_HALLAZGOS => 'Con Hallazgos',
    ];

    /** Valor que guardaba SEGCAT (para el importador). */
    public const ESTATUS_SEGCAT = [
        'EN_PROCESO' => self::EN_PROCESO,
        'COMPLETO' => self::COMPLETO,
        'CON_HALLAZGOS' => self::CON_HALLAZGOS,
    ];

    protected $attributes = ['estatus' => self::EN_PROCESO];

    protected $fillable = ['empresa_id', 'sede_id', 'espacio_id', 'observaciones_generales'];

    protected function casts(): array
    {
        return ['numero' => 'integer', 'sede_id' => 'integer', 'espacio_id' => 'integer', 'novedad_id' => 'integer', 'finalizado_en' => 'datetime', 'creado_por' => 'integer'];
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class)->withTrashed();
    }

    /** Edificio o zona que se recorrió (opcional). */
    public function espacio(): BelongsTo
    {
        return $this->belongsTo(Espacio::class);
    }

    /** Ticket de Protección Civil abierto por el primer hallazgo. */
    public function novedad(): BelongsTo
    {
        return $this->belongsTo(Novedad::class);
    }

    public function revisiones(): HasMany
    {
        return $this->hasMany(RevisionRecorridoPc::class)->orderBy('id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizado_por');
    }

    public function finalizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalizado_por');
    }

    /** "#00012" */
    public function folio(): string
    {
        return '#'.str_pad((string) $this->numero, 5, '0', STR_PAD_LEFT);
    }

    public function enProceso(): bool
    {
        return $this->estatus === self::EN_PROCESO;
    }

    public function etiquetaEstatus(): string
    {
        return self::ESTATUS[$this->estatus] ?? $this->estatus;
    }
}
