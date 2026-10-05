<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Ticket de la Bitácora de Novedades (SEGCAT: bitacora_novedades).
 *
 * Nace ABIERTO; se puede pasar a PENDIENTE DE TURNO y de vuelta; RESUELTO
 * exige la resolución y fija la fecha de cierre. Un caso Resuelto solo se
 * edita después de "Reabrir Caso" con justificación. Lo propio de cada
 * categoría vive en su tabla de detalle (ver App\Services\Novedades\Formatos).
 */
class Novedad extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'novedades';

    /** clave => etiqueta en pantalla (SEGCAT etiquetaCategoria()). */
    public const CATEGORIAS = [
        'sin_clasificar' => 'Sin Clasificar',
        'incidente_general' => 'Reporte General',
        'accidente' => 'Accidente / Lesión',
        'habitacion' => 'Valores a la Vista',
        'proteccion_civil' => 'Siniestro Protección Civil',
        'recorrido_pc' => 'Recorrido Protección Civil',
        'lost_found' => 'Lost & Found',
        'robo' => 'Robo',
    ];

    /** Valor que guardaba SEGCAT (para el importador). */
    public const CATEGORIAS_SEGCAT = [
        'SIN CLASIFICAR' => 'sin_clasificar',
        'INCIDENTE GENERAL' => 'incidente_general',
        'ACCIDENTE' => 'accidente',
        'HABITACION' => 'habitacion',
        'PROTECCION_CIVIL' => 'proteccion_civil',
        'RECORRIDO_PC' => 'recorrido_pc',
        'LOST_FOUND' => 'lost_found',
        'ROBO' => 'robo',
    ];

    /**
     * Las que se eligen al despachar o atender un ticket. Recorrido PC ya no
     * se crea aquí (tiene su propio módulo): solo se conserva en los tickets
     * que ya la traían.
     */
    public const CATEGORIAS_ELEGIBLES = ['sin_clasificar', 'incidente_general', 'accidente', 'habitacion', 'proteccion_civil', 'lost_found', 'robo'];

    /** Texto de cada opción en los selectores (como en SEGCAT). */
    public const OPCIONES_CATEGORIA = [
        'sin_clasificar' => '-- Sin clasificar todavía --',
        'incidente_general' => 'Reporte General',
        'accidente' => 'Accidente / Lesión',
        'habitacion' => 'Valores a la Vista',
        'proteccion_civil' => 'Siniestro Protección Civil',
        'lost_found' => 'Lost & Found (Objetos Perdidos)',
        'robo' => 'Robo',
    ];

    public const ABIERTO = 'abierto';

    public const PENDIENTE_TURNO = 'pendiente_turno';

    public const RESUELTO = 'resuelto';

    /** clave => [texto del selector, texto corto] (SEGCAT estatus_caso). */
    public const ESTATUS = [
        self::ABIERTO => ['Abierto / Seguimiento Pendiente', 'ABIERTO / EN PROCESO'],
        self::PENDIENTE_TURNO => ['Pendiente de Turno (lo atiende el siguiente turno)', 'PENDIENTE DE TURNO'],
        self::RESUELTO => ['Resuelto y Cerrado (Documentación Completa)', 'RESUELTO'],
    ];

    public const ESTATUS_SEGCAT = [
        'ABIERTO / EN PROCESO' => self::ABIERTO,
        'PENDIENTE DE TURNO' => self::PENDIENTE_TURNO,
        'RESUELTO' => self::RESUELTO,
    ];

    protected $attributes = ['categoria' => 'sin_clasificar', 'estatus' => self::ABIERTO];

    protected $fillable = [
        'empresa_id', 'sede_id', 'categoria', 'reportado_por', 'reportado_colaborador_id', 'asignado_a', 'area_id',
        'area_especifica_id', 'ubicacion', 'involucrados', 'ocurrio_en', 'descripcion', 'como_sucedio', 'resolucion', 'origen_novedad_id',
    ];

    protected function casts(): array
    {
        return [
            'ocurrio_en' => 'datetime', 'cerrado_en' => 'datetime', 'numero' => 'integer', 'sede_id' => 'integer', 'area_id' => 'integer',
            'area_especifica_id' => 'integer', 'asignado_a' => 'integer', 'creado_por' => 'integer',
        ];
    }

    // ------------------------------------------------------------ Relaciones

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class)->withTrashed();
    }

    public function asignado(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asignado_a');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizado_por');
    }

    public function cerrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cerrado_por');
    }

    public function reportadoColaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'reportado_colaborador_id');
    }

    /** Área General: edificio o piso de Zonas y áreas. */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Espacio::class, 'area_id');
    }

    /** Habitación (área específica) donde ocurrió, si aplica. */
    public function areaEspecifica(): BelongsTo
    {
        return $this->belongsTo(Espacio::class, 'area_especifica_id');
    }

    public function origen(): BelongsTo
    {
        return $this->belongsTo(self::class, 'origen_novedad_id');
    }

    public function notas(): HasMany
    {
        return $this->hasMany(NovedadNota::class)->orderBy('id');
    }

    public function testigos(): HasMany
    {
        return $this->hasMany(NovedadTestigo::class)->orderBy('id');
    }

    public function reporteGeneral(): HasOne
    {
        return $this->hasOne(NovedadReporteGeneral::class);
    }

    public function accidenteHuesped(): HasOne
    {
        return $this->hasOne(AccidenteHuesped::class);
    }

    public function accidenteColaborador(): HasOne
    {
        return $this->hasOne(AccidenteColaborador::class);
    }

    public function accidenteDictamen(): HasOne
    {
        return $this->hasOne(AccidenteDictamen::class);
    }

    public function accidenteGuardavidas(): HasOne
    {
        return $this->hasOne(AccidenteGuardavidas::class);
    }

    public function accidenteIncapacidad(): HasOne
    {
        return $this->hasOne(AccidenteIncapacidad::class);
    }

    public function firmas(): HasMany
    {
        return $this->hasMany(AccidenteFirma::class);
    }

    public function valoresVista(): HasOne
    {
        return $this->hasOne(ValoresVistaDetalle::class);
    }

    public function valoresVistaPersonas(): HasMany
    {
        return $this->hasMany(ValoresVistaPersona::class)->orderBy('id');
    }

    public function valoresVistaAperturas(): HasMany
    {
        return $this->hasMany(ValoresVistaApertura::class)->orderBy('id');
    }

    public function valoresVistaZonas(): HasMany
    {
        return $this->hasMany(ValoresVistaZona::class)->orderBy('id');
    }

    public function siniestro(): HasOne
    {
        return $this->hasOne(SiniestroDetalle::class);
    }

    public function siniestroServicios(): HasMany
    {
        return $this->hasMany(SiniestroServicio::class)->orderBy('id');
    }

    public function siniestroEquipos(): HasMany
    {
        return $this->hasMany(SiniestroEquipo::class)->orderBy('id');
    }

    public function siniestroDanos(): HasMany
    {
        return $this->hasMany(SiniestroDano::class)->orderBy('id');
    }

    public function recorridoPuntos(): HasMany
    {
        return $this->hasMany(RecorridoPcPunto::class)->orderBy('id');
    }

    public function lostFound(): HasOne
    {
        return $this->hasOne(LostFoundDetalle::class);
    }

    public function articulos(): HasMany
    {
        return $this->hasMany(LostFoundArticulo::class)->orderBy('id');
    }

    public function reportesPerdida(): HasMany
    {
        return $this->hasMany(LostFoundReportePerdida::class)->orderBy('id');
    }

    public function robo(): HasOne
    {
        return $this->hasOne(RoboDetalle::class);
    }

    // ---------------------------------------------------------------- Lectura

    /** "#00012" */
    public function folio(): string
    {
        return '#'.str_pad((string) $this->numero, 5, '0', STR_PAD_LEFT);
    }

    public function etiquetaCategoria(): string
    {
        return self::CATEGORIAS[$this->categoria] ?? $this->categoria;
    }

    public function etiquetaEstatus(): string
    {
        return self::ESTATUS[$this->estatus][1] ?? $this->estatus;
    }

    public function resuelto(): bool
    {
        return $this->estatus === self::RESUELTO;
    }

    /**
     * Área General en texto: "Torre A · Piso 1" (y la habitación si se indica).
     */
    public function textoArea(bool $conHabitacion = true): string
    {
        $partes = [];
        if ($this->area !== null) {
            if ($this->area->nivel === Espacio::AREA && $this->area->padre !== null) {
                $partes[] = $this->area->padre->nombre;
            }
            $partes[] = $this->area->nombre;
        }
        if ($conHabitacion && $this->areaEspecifica !== null) {
            $partes[] = $this->areaEspecifica->nombre;
        }

        return implode(' · ', $partes);
    }

    /**
     * Fecha en la hora local de la sede, para un <input type="datetime-local">.
     */
    public function localParaCampo(?Carbon $fecha): string
    {
        return $fecha === null ? '' : $fecha->copy()->setTimezone($this->zonaSede())->format('Y-m-d\TH:i');
    }

    public function zonaSede(): string
    {
        return $this->sede?->zonaHoraria() ?? config('app.timezone');
    }
}
