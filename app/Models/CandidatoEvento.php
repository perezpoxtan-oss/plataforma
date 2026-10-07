<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historial del candidato: cada cambio de etapa, aviso y respuesta, con quién y cuándo.
 */
class CandidatoEvento extends Model
{
    use PerteneceAEmpresa;

    protected $table = 'candidato_eventos';

    protected $fillable = ['empresa_id', 'candidato_id', 'evento', 'etapa_anterior', 'etapa_nueva', 'comentario', 'user_id'];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
