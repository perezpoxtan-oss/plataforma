<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use App\Models\Concerns\TieneIdentificador;
use App\Support\HoraLocal;
use App\Support\Lector\Identificable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Llave del Catálogo de llaves (SEGCAT: llaves): llave metálica, tarjeta
 * electrónica, acceso biométrico o clave, de una sede, con lo que abre y
 * en qué horarios.
 *
 * Se encuentra con el lector universal por su código QR (etiqueta del
 * llavero), por la tarjeta o chip asignado (etiqueta_nfc) o tecleando su
 * nomenclatura.
 *
 * Préstamo de llaves (Operación, pendiente): la insignia "EN USO" de la lista
 * saldrá de ahí. Cuando exista la bitácora de préstamos basta con agregar a
 * este modelo la relación del préstamo abierto y, en la consulta de la lista,
 * withExists(['prestamoAbierto as en_uso']): enUso() ya lee ese atributo.
 */
class Llave extends Model implements Identificable
{
    use PerteneceAEmpresa, RegistraAutor, TieneIdentificador;

    /** Se encuentra también tecleando o escaneando su nomenclatura. */
    protected string $columnaLegible = 'nomenclatura';

    /** clave => etiqueta (SEGCAT: cat_tipos_llave) */
    public const TIPOS_DISPOSITIVO = [
        'electronica_rfid' => 'Electrónica (Magnética/RFID)',
        'metalica' => 'Metálica tradicional',
        'biometrica' => 'Biométrica / Huella',
        'clave_pin' => 'Clave / PIN',
    ];

    /** Los que programa una plataforma externa y pueden traer su ID (VingCard, Salto, ZKTeco…). */
    public const CON_ID_EXTERNO = ['electronica_rfid', 'biometrica', 'clave_pin'];

    /** clave => etiqueta (SEGCAT: cat_alcances) */
    public const ALCANCES = [
        'global' => 'Global (Master Key)',
        'zona' => 'Edificio / Zona completa',
        'piso' => 'Piso',
        'area' => 'Área específica / Cuarto',
        'seccion' => 'Sección (grupo de habitaciones)',
        'otra' => 'Otra',
    ];

    /** Alcance => nivel del árbol de Zonas y áreas que se elige. */
    public const NIVEL_DEL_ALCANCE = [
        'zona' => Espacio::EDIFICIO,
        'piso' => Espacio::AREA,
        'area' => Espacio::AREA_ESPECIFICA,
    ];

    /** Días antes de la caducidad en que se avisa "Vence pronto" (SEGCAT: 30). */
    public const DIAS_AVISO_CADUCIDAD = 30;

    protected $attributes = ['activo' => true, 'tipo_dispositivo' => 'metalica', 'alcance' => 'global'];

    protected $fillable = [
        'empresa_id', 'sede_id', 'departamento_id', 'puesto_id', 'colaborador_id', 'nomenclatura', 'descripcion',
        'tipo_dispositivo', 'alcance', 'alcance_otro', 'id_externo', 'plataforma_externa', 'fecha_caducidad',
        'etiqueta_nfc', 'activo',
    ];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'fecha_caducidad' => 'date'];
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    public function departamento(): BelongsTo
    {
        return $this->belongsTo(Departamento::class);
    }

    public function puesto(): BelongsTo
    {
        return $this->belongsTo(Puesto::class);
    }

    /** Responsable permanente (excepcional: el préstamo normal va en la bitácora). */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }

    public function horarios(): HasMany
    {
        return $this->hasMany(HorarioLlave::class)->orderBy('hora_inicio')->orderBy('id');
    }

    /** Zonas, pisos o áreas específicas que abre (según el alcance). */
    public function espacios(): BelongsToMany
    {
        return $this->belongsToMany(Espacio::class, 'espacio_llave');
    }

    /** Secciones (grupos de habitaciones) que abre. */
    public function grupos(): BelongsToMany
    {
        return $this->belongsToMany(GrupoEspacio::class, 'grupo_espacio_llave');
    }

    public function etiquetaTipo(): string
    {
        return self::TIPOS_DISPOSITIVO[$this->tipo_dispositivo] ?? $this->tipo_dispositivo;
    }

    public function etiquetaAlcance(): string
    {
        return self::ALCANCES[$this->alcance] ?? $this->alcance;
    }

    /**
     * Lugares que abre, en texto: "Torre A", "Piso 1 (Torre A)", "101",
     * "Vista al mar", el texto libre de "Otra" o "Toda la sede".
     *
     * @return list<string>
     */
    public function lugares(): array
    {
        return match ($this->alcance) {
            'global' => ['Toda la sede'],
            'otra' => array_values(array_filter([$this->alcance_otro])),
            'seccion' => $this->grupos->sortBy('nombre')->pluck('nombre')->values()->all(),
            default => $this->espacios->sortBy('nombre', SORT_NATURAL)->map(fn (Espacio $e) => $e->nivel === Espacio::AREA && $e->relationLoaded('padre') && $e->padre
                ? $e->nombre.' ('.$e->padre->nombre.')'
                : $e->nombre)->values()->all(),
        };
    }

    /**
     * Resumen corto del alcance para la ficha y la etiqueta impresa:
     * "Toda la sede", "Torre A", "Torre A y 2 más".
     */
    public function resumenLugares(): string
    {
        $lugares = $this->lugares();
        if ($lugares === []) {
            return 'Sin lugares asignados';
        }

        return $lugares[0].(count($lugares) > 1 ? ' y '.(count($lugares) - 1).' más' : '');
    }

    /**
     * Estado de la caducidad, contando los días en la hora local de quien lo ve
     * (SEGCAT: vencida < 0 días, "Vence pronto" <= 30, vigente).
     *
     * @return array{clase: string, texto: string, dias: int}|null
     */
    public function caducidad(): ?array
    {
        if ($this->fecha_caducidad === null) {
            return null;
        }

        $zona = app(HoraLocal::class)->zona();
        $hoy = Carbon::now($zona)->startOfDay();
        $vence = Carbon::createFromFormat('Y-m-d', $this->fecha_caducidad->format('Y-m-d'), $zona)->startOfDay();
        $dias = (int) $hoy->diffInDays($vence, false);

        return match (true) {
            $dias < 0 => ['clase' => 'vencida', 'texto' => 'VENCIDA', 'dias' => $dias],
            $dias <= self::DIAS_AVISO_CADUCIDAD => ['clase' => 'pronto', 'texto' => 'Vence pronto', 'dias' => $dias],
            default => ['clase' => 'ok', 'texto' => 'Vigente', 'dias' => $dias],
        };
    }

    /**
     * La fecha de caducidad es un día (no una hora). Para mostrarla con
     *
     * @fecha(…, 'd/m/Y') sin que el cambio de zona horaria la mueva al día
     * anterior, se entrega a mediodía UTC.
     */
    public function caducidadParaMostrar(): ?Carbon
    {
        return $this->fecha_caducidad === null ? null : Carbon::createFromFormat('Y-m-d H:i:s', $this->fecha_caducidad->format('Y-m-d').' 12:00:00', 'UTC');
    }

    /**
     * ¿Está prestada ahora? Gancho para el Préstamo de llaves: la consulta
     * de la lista agregará "en_uso" (withExists) cuando exista la bitácora.
     */
    public function enUso(): bool
    {
        return (bool) ($this->attributes['en_uso'] ?? false);
    }

    public static function tipoLector(): string
    {
        return 'llave';
    }

    public static function permisoLector(): string
    {
        return 'llaves.ver';
    }

    public function resumenLector(): array
    {
        $this->loadMissing('sede:id,nombre');

        return [
            'titulo' => $this->nomenclatura,
            'detalle' => $this->etiquetaTipo().' · '.($this->sede?->nombre ?? '—').' · '.$this->descripcion,
            'activo' => (bool) $this->activo,
            'sede_id' => $this->sede_id,
        ];
    }

    public function urlLector(): string
    {
        return route('llaves.index').'#llave-'.$this->id;
    }
}
