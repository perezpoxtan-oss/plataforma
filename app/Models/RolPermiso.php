<?php

namespace App\Models;

use App\Services\Permisos\Alcance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RolPermiso extends Model
{
    protected $table = 'rol_permisos';

    protected $fillable = ['rol_id', 'modulo_accion_id', 'alcance'];

    protected function casts(): array
    {
        return ['alcance' => Alcance::class];
    }

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class);
    }

    public function moduloAccion(): BelongsTo
    {
        return $this->belongsTo(ModuloAccion::class);
    }
}
