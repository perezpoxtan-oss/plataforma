<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use App\Models\Concerns\TieneIdentificador;
use App\Services\Novedades\Formatos\RecorridoPc as FormatoRecorridoPc;
use App\Services\RecorridosPc\Ubicaciones;
use App\Support\Lector\Identificable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Equipo del Catálogo de Equipos de Protección Civil (SEGCAT: cat_equipos_pc):
 * extintores, hidrantes, detectores y demás infraestructura fija de una sede
 * que se inspecciona en los Recorridos de Protección Civil. Separado del
 * equipo prestable de la guardia (Equipos de seguridad).
 *
 * Se encuentra con el lector universal por su QR, por la etiqueta NFC/RFID
 * asignada o tecleando su Núm. de Serie / ID ("EXT-01").
 */
class EquipoPc extends Model implements Identificable
{
    use PerteneceAEmpresa, RegistraAutor, TieneIdentificador;

    protected $table = 'equipos_pc';

    /** Se encuentra también tecleando o escaneando el Núm. de Serie / ID. */
    protected string $columnaLegible = 'numero_serie';

    /** clave => etiqueta (SEGCAT: $ETIQUETAS_TIPO_PC, mismo orden). */
    public const CATEGORIAS = [
        'EXTINTOR' => 'Extintor',
        'HIDRANTE' => 'Hidrante',
        'ESTACION_MANUAL' => 'Estación Manual',
        'DETECTOR_HUMO' => 'Detector de Humo',
        'MANTA_ANTIFLAMA' => 'Manta Antiflama',
        'SALIDA_EMERGENCIA' => 'Salida de Emergencia',
        'ASPERSORES' => 'Aspersor',
        'RACK_BOMBEROS' => 'Rack de Bomberos',
        'BOTIQUIN' => 'Botiquín',
        'SENALETICA' => 'Señalética',
        'GAS_LP' => 'Gas L.P.',
        'TABLERO_ELECTRICO' => 'Tablero Eléctrico',
        'LAMPARA_EMERGENCIA' => 'Lámpara de Emergencia',
    ];

    /** Icono de cada categoría (lista y punto de inspección). */
    public const ICONOS = [
        'EXTINTOR' => 'bi-fire',
        'HIDRANTE' => 'bi-droplet-half',
        'ESTACION_MANUAL' => 'bi-bell',
        'DETECTOR_HUMO' => 'bi-cloud-haze2',
        'MANTA_ANTIFLAMA' => 'bi-shield',
        'SALIDA_EMERGENCIA' => 'bi-door-open',
        'ASPERSORES' => 'bi-moisture',
        'RACK_BOMBEROS' => 'bi-person-badge',
        'BOTIQUIN' => 'bi-bandaid',
        'SENALETICA' => 'bi-signpost',
        'GAS_LP' => 'bi-fuel-pump',
        'TABLERO_ELECTRICO' => 'bi-lightning-charge',
        'LAMPARA_EMERGENCIA' => 'bi-lightbulb',
    ];

    protected $attributes = ['activo' => true];

    protected $fillable = ['empresa_id', 'sede_id', 'categoria', 'numero_serie', 'espacio_id', 'referencia', 'etiqueta_nfc'];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'sede_id' => 'integer', 'espacio_id' => 'integer'];
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class)->withTrashed();
    }

    /** Zona, piso o área específica donde está instalado (Zonas y áreas). */
    public function espacio(): BelongsTo
    {
        return $this->belongsTo(Espacio::class);
    }

    public function revisiones(): HasMany
    {
        return $this->hasMany(RevisionRecorridoPc::class);
    }

    public function etiquetaCategoria(): string
    {
        return self::CATEGORIAS[$this->categoria] ?? $this->categoria;
    }

    public function icono(): string
    {
        return self::ICONOS[$this->categoria] ?? 'bi-shield-check';
    }

    /**
     * Piezas a revisar de una categoría (las de SEGCAT) más los «Criterios
     * Operativos Universales».
     *
     * @return array<string, string> clave => texto
     */
    public static function criterios(string $categoria): array
    {
        return FormatoRecorridoPc::piezas($categoria);
    }

    /**
     * Solo las piezas propias de la categoría (sin las universales).
     *
     * @return array<string, string>
     */
    public static function componentes(string $categoria): array
    {
        return FormatoRecorridoPc::CATEGORIAS[$categoria][1] ?? [];
    }

    /** "1. Extintores": texto del selector de categoría del recorrido (SEGCAT). */
    public static function opcionRecorrido(string $categoria): string
    {
        return FormatoRecorridoPc::CATEGORIAS[$categoria][0] ?? (self::CATEGORIAS[$categoria] ?? $categoria);
    }

    /** Mayúsculas, sin espacios en los extremos ni dobles: " ext  01 " -> "EXT 01". */
    public static function normalizarSerie(?string $serie): string
    {
        return mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', (string) $serie)));
    }

    public static function tipoLector(): string
    {
        return 'equipo_pc';
    }

    public static function permisoLector(): string
    {
        return 'equipos_pc.ver';
    }

    public function resumenLector(): array
    {
        $this->loadMissing(['sede:id,nombre', 'espacio:id,nombre,ruta']);
        $ubicacion = $this->espacio !== null ? app(Ubicaciones::class)->texto($this->espacio) : null;

        return [
            'titulo' => $this->numero_serie,
            'detalle' => implode(' · ', array_filter([$this->etiquetaCategoria(), $ubicacion, $this->sede?->nombre])),
            'activo' => (bool) $this->activo,
            'sede_id' => $this->sede_id,
        ];
    }

    /**
     * A donde lleva el QR o la etiqueta NFC (p. ej. el iPhone al acercarla):
     * al recorrido abierto de su sede, listo para inspeccionarlo; si no hay,
     * a su ficha del catálogo.
     */
    public function urlLector(): string
    {
        return route('equipos_pc.ir', $this->id);
    }
}
