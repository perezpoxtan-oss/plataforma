<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quién autoriza por un departamento: el titular (jefe) y sus suplentes,
 * en todas las sedes del departamento (sede_id null) o solo en una.
 */
class DepartamentoResponsable extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'departamento_responsables';

    protected $attributes = ['es_suplente' => false];

    protected $fillable = ['empresa_id', 'departamento_id', 'user_id', 'sede_id', 'es_suplente'];

    protected function casts(): array
    {
        return ['es_suplente' => 'boolean'];
    }

    public function departamento(): BelongsTo
    {
        return $this->belongsTo(Departamento::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class)->withTrashed();
    }
}
