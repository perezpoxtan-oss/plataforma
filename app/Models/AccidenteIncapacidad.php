<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Accidente: Uso Exclusivo Recursos Humanos. SEGCAT: accidente_rh.
 */
class AccidenteIncapacidad extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'accidente_incapacidades';

    protected $fillable = ['empresa_id', 'novedad_id', 'dias_incapacidad', 'fecha_presenta'];

    protected function casts(): array
    {
        return ['fecha_presenta' => 'date', 'dias_incapacidad' => 'integer'];
    }

    public function novedad(): BelongsTo
    {
        return $this->belongsTo(Novedad::class);
    }
}
