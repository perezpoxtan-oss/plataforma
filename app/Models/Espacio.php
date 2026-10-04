<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Nodo del árbol de zonas y áreas de una sede.
 */
class Espacio extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    public const EDIFICIO = 'edificio';

    public const AREA = 'area';

    public const AREA_ESPECIFICA = 'area_especifica';

    public const SUBAREA = 'subarea';

    public const ELEMENTO = 'elemento';

    /** Nivel => niveles de padre permitidos (null = cuelga de la sede). */
    public const PADRES = [
        self::EDIFICIO => [null],
        self::AREA => [self::EDIFICIO],
        self::AREA_ESPECIFICA => [self::AREA, self::EDIFICIO],
        self::SUBAREA => [self::AREA_ESPECIFICA],
        self::ELEMENTO => [self::SUBAREA, self::AREA_ESPECIFICA, self::AREA],
    ];

    protected $table = 'espacios';

    /** Mismos valores por defecto que la base de datos (disponibles antes de recargar). */
    protected $attributes = ['activo' => true, 'ruta' => '/', 'profundidad' => 0, 'orden' => 0];

    protected $fillable = [
        'empresa_id', 'sede_id', 'padre_id', 'nivel', 'tipo_espacio_id', 'grupo_espacio_id',
        'nombre', 'codigo', 'orden', 'activo',
    ];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'profundidad' => 'integer', 'orden' => 'integer'];
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    public function padre(): BelongsTo
    {
        return $this->belongsTo(self::class, 'padre_id');
    }

    public function hijos(): HasMany
    {
        return $this->hasMany(self::class, 'padre_id')->orderBy('orden')->orderBy('nombre');
    }

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoEspacio::class, 'tipo_espacio_id');
    }

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(GrupoEspacio::class, 'grupo_espacio_id');
    }

    /**
     * Todo lo que cuelga de este espacio (sin incluirlo).
     */
    public function scopeDescendientesDe(Builder $query, self $espacio): void
    {
        $query->where('ruta', 'like', $espacio->ruta.'%')->whereKeyNot($espacio->getKey());
    }

    /**
     * Ids de los ancestros, del más alto al padre directo.
     *
     * @return list<int>
     */
    public function idsAncestros(): array
    {
        $ids = array_map('intval', array_filter(explode('/', trim((string) $this->ruta, '/'))));
        array_pop($ids);

        return $ids;
    }
}
