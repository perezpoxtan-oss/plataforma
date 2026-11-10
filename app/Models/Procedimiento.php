<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use App\Models\Concerns\TieneIdentificador;
use App\Support\Lector\Identificable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Un procedimiento operativo (qué hacer ante un robo, un incendio, la entrega
 * de turno…). El contenido vive en sus versiones (ProcedimientoVersion); aquí
 * van la clave, la categoría y copias del estado para listar rápido:
 *
 *   estado          borrador | en_revision | publicado | retirado
 *   version_vigente número de la versión publicada (la que se consulta y se firma)
 *   version_trabajo número de la versión en borrador o en revisión (si hay)
 *
 * Mientras se trabaja una versión nueva, la anterior sigue vigente.
 * Su QR impreso (codigo_qr) abre el modo lectura con el lector universal.
 */
class Procedimiento extends Model implements Identificable
{
    use PerteneceAEmpresa, RegistraAutor, TieneIdentificador;

    public const BORRADOR = 'borrador';

    public const EN_REVISION = 'en_revision';

    public const PUBLICADO = 'publicado';

    public const RETIRADO = 'retirado';

    /** estado => [texto, clase de color] */
    public const ESTADOS = [
        self::BORRADOR => ['Borrador', 'borrador'],
        self::EN_REVISION => ['En revisión', 'revision'],
        self::PUBLICADO => ['Publicado', 'publicado'],
        self::RETIRADO => ['Retirado (obsoleto)', 'retirado'],
    ];

    /** Se encuentra también tecleando o leyendo su clave (PRO-SEG-001). */
    protected string $columnaLegible = 'clave';

    protected $attributes = ['estado' => self::BORRADOR];

    protected $fillable = ['empresa_id', 'clave', 'categoria_id', 'titulo', 'etiqueta_nfc'];

    protected function casts(): array
    {
        return [
            'version_vigente' => 'integer', 'version_trabajo' => 'integer',
            'publicado_en' => 'datetime', 'retirado_en' => 'datetime',
        ];
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(ProcedimientoCategoria::class, 'categoria_id');
    }

    public function versiones(): HasMany
    {
        return $this->hasMany(ProcedimientoVersion::class)->orderBy('numero');
    }

    /** La versión publicada vigente (la que se consulta y se firma). */
    public function vigente(): HasOne
    {
        return $this->hasOne(ProcedimientoVersion::class)->where('estado', ProcedimientoVersion::PUBLICADA);
    }

    /** La versión en borrador o en revisión (si hay). */
    public function trabajo(): HasOne
    {
        return $this->hasOne(ProcedimientoVersion::class)->whereIn('estado', [ProcedimientoVersion::BORRADOR, ProcedimientoVersion::EN_REVISION]);
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(ProcedimientoEvento::class)->orderBy('id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizado_por');
    }

    public function retiro(): BelongsTo
    {
        return $this->belongsTo(User::class, 'retirado_por');
    }

    /** @return array{0: string, 1: string} [texto, clase] */
    public function insignia(): array
    {
        return self::ESTADOS[$this->estado] ?? self::ESTADOS[self::BORRADOR];
    }

    public function estaPublicado(): bool
    {
        return $this->estado === self::PUBLICADO;
    }

    // ---------------------------------------------------------- Lector universal

    public static function tipoLector(): string
    {
        return 'procedimiento';
    }

    public static function permisoLector(): string
    {
        return 'procedimientos.ver';
    }

    public function resumenLector(): array
    {
        return [
            'titulo' => $this->clave.' · '.$this->titulo,
            'detalle' => $this->version_vigente ? 'Procedimiento · versión '.$this->version_vigente : 'Procedimiento · '.$this->insignia()[0],
            'activo' => $this->estado === self::PUBLICADO,
            'sede_id' => null,
        ];
    }

    public function urlLector(): string
    {
        return route('procedimientos.leer', $this->id);
    }
}
