<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una versión de un procedimiento (v1, v2…), con su circuito:
 *
 *   borrador --enviar--> en_revision --aprobar (firma)--> publicada
 *   en_revision --rechazar (comentario)--> borrador
 *   publicada --se aprueba una versión nueva--> reemplazada
 *   borrador de una versión nueva --descartar--> descartada
 *
 * Una versión publicada o reemplazada no se edita nunca.
 */
class ProcedimientoVersion extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    public const BORRADOR = 'borrador';

    public const EN_REVISION = 'en_revision';

    public const PUBLICADA = 'publicada';

    public const REEMPLAZADA = 'reemplazada';

    public const DESCARTADA = 'descartada';

    /** estado => [texto, clase] */
    public const ESTADOS = [
        self::BORRADOR => ['Borrador', 'borrador'],
        self::EN_REVISION => ['En revisión', 'revision'],
        self::PUBLICADA => ['Vigente', 'publicado'],
        self::REEMPLAZADA => ['Reemplazada', 'reemplazada'],
        self::DESCARTADA => ['Descartada', 'retirado'],
    ];

    protected $table = 'procedimiento_versiones';

    protected $attributes = ['estado' => self::BORRADOR, 'aplica_todas_sedes' => true];

    protected $fillable = [
        'empresa_id', 'procedimiento_id', 'numero', 'categoria_id', 'titulo', 'objetivo', 'alcance', 'responsables',
        'notas', 'resumen_cambios', 'aplica_todas_sedes',
    ];

    protected function casts(): array
    {
        return [
            'numero' => 'integer', 'aplica_todas_sedes' => 'boolean',
            'enviado_en' => 'datetime', 'aprobado_en' => 'datetime', 'rechazado_en' => 'datetime', 'reemplazada_en' => 'datetime',
        ];
    }

    public function procedimiento(): BelongsTo
    {
        return $this->belongsTo(Procedimiento::class);
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(ProcedimientoCategoria::class, 'categoria_id');
    }

    public function pasos(): HasMany
    {
        return $this->hasMany(ProcedimientoPaso::class, 'version_id')->orderBy('orden');
    }

    public function aplicaciones(): HasMany
    {
        return $this->hasMany(ProcedimientoAplicacion::class, 'version_id');
    }

    public function adjuntos(): HasMany
    {
        return $this->hasMany(ProcedimientoAdjunto::class, 'version_id')->orderBy('id');
    }

    public function acuses(): HasMany
    {
        return $this->hasMany(ProcedimientoAcuse::class, 'version_id');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(ProcedimientoEvento::class, 'version_id')->orderBy('id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizado_por');
    }

    public function envio(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enviado_por');
    }

    public function aprobador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }

    public function rechazador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rechazado_por');
    }

    /** @return array{0: string, 1: string} */
    public function insignia(): array
    {
        return self::ESTADOS[$this->estado] ?? self::ESTADOS[self::BORRADOR];
    }

    public function editable(): bool
    {
        return $this->estado === self::BORRADOR;
    }

    /**
     * Ids elegidos de un tipo de aplicación (sede, departamento, puesto).
     *
     * @return list<int>
     */
    public function idsDe(string $tipo): array
    {
        return $this->aplicaciones->where('tipo', $tipo)->pluck($tipo.'_id')->map(fn ($id) => (int) $id)->values()->all();
    }
}
