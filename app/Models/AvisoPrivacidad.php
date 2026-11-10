<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una versión publicada del aviso de privacidad (docs/tecnico/privacidad.md).
 * empresa_id nulo = aviso de la página pública de la plataforma. Las
 * versiones no se editan: publicar un texto nuevo crea la siguiente.
 * Se escribe siempre con la empresa fija (Tenant::conEmpresa) y se lee con
 * su filtro explícito (AvisosPrivacidad).
 */
class AvisoPrivacidad extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'avisos_privacidad';

    protected $fillable = ['empresa_id', 'numero', 'texto', 'huella', 'publicado_en', 'publicado_por'];

    protected function casts(): array
    {
        return ['numero' => 'integer', 'publicado_en' => 'datetime'];
    }

    public function publicador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'publicado_por');
    }
}
