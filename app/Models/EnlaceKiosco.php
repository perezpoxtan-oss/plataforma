<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Enlace temporal del kiosco de auto-registro: lo abre UN candidato con su
 * celular (QR o código corto) para llenar su CV sin iniciar sesión.
 * Solo se guarda la huella (SHA-256) del token, nunca el token.
 */
class EnlaceKiosco extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'enlaces_kiosco';

    protected $fillable = ['empresa_id', 'candidato_id', 'token_hash', 'codigo', 'expira_en', 'usos_maximos'];

    protected function casts(): array
    {
        return ['expira_en' => 'datetime', 'ultimo_uso_en' => 'datetime', 'revocado_en' => 'datetime', 'usos' => 'integer', 'usos_maximos' => 'integer'];
    }

    public function candidato(): BelongsTo
    {
        return $this->belongsTo(Candidato::class);
    }

    public function vigente(): bool
    {
        return $this->revocado_en === null && $this->expira_en->isFuture() && $this->usos < $this->usos_maximos;
    }
}
