<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Paradero del transporte de personal (SEGCAT: cat_paraderos). Es de UNA
 * sede: dos sedes de la misma empresa no comparten paraderos. Se crea desde
 * la pestaña Paraderos o solo, al escribir uno nuevo en una ruta.
 */
class Paradero extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'paraderos';

    protected $attributes = ['activo' => true];

    protected $fillable = ['empresa_id', 'sede_id', 'nombre', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    public function paradas(): HasMany
    {
        return $this->hasMany(RutaParada::class);
    }

    /**
     * Mayúsculas y sin espacios dobles, para que «Plaza las Américas» y
     * «PLAZA  LAS AMÉRICAS» sean el mismo paradero.
     */
    public static function normalizarNombre(?string $nombre): string
    {
        return mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', (string) $nombre)));
    }
}
