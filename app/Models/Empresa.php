<?php

namespace App\Models;

use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Cliente de la plataforma (tenant). No usa el filtro de empresa porque
 * es la entidad que lo define; el acceso se controla con permisos.
 */
class Empresa extends Model
{
    use RegistraAutor, SoftDeletes;

    protected $table = 'empresas';

    /** Mismos valores por defecto que la base de datos (disponibles antes de recargar). */
    protected $attributes = ['activo' => true, 'zona_horaria' => 'America/Mexico_City', 'idioma' => 'es', 'moneda' => 'MXN'];

    protected $fillable = [
        'rubro_id', 'nombre_comercial', 'razon_social', 'rfc', 'logo_ruta',
        'zona_horaria', 'idioma', 'moneda', 'activo',
    ];

    /** Avisos por correo y su valor si la empresa no lo ha cambiado. */
    public const AVISOS = [
        'alta_provisional' => ['Avisar a Recursos Humanos cuando la caseta registre un alta provisional de colaborador', true],
    ];

    public function aviso(string $clave): bool
    {
        return (bool) ($this->preferencias['avisos'][$clave] ?? (self::AVISOS[$clave][1] ?? false));
    }

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'preferencias' => 'array'];
    }

    public function rubro(): BelongsTo
    {
        return $this->belongsTo(Rubro::class);
    }

    public function sedes(): HasMany
    {
        return $this->hasMany(Sede::class);
    }

    public function modulos(): BelongsToMany
    {
        return $this->belongsToMany(Modulo::class, 'empresa_modulos')
            ->withPivot(['nombre_visible', 'orden', 'activo'])
            ->withTimestamps();
    }
}
