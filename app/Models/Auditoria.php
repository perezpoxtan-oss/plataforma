<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Auditoria extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = 'creado_en';

    protected $table = 'auditoria';

    protected $fillable = [
        'empresa_id', 'user_id', 'evento', 'auditable_type', 'auditable_id', 'antes', 'despues', 'ip',
    ];

    protected function casts(): array
    {
        return ['antes' => 'array', 'despues' => 'array', 'creado_en' => 'datetime'];
    }
}
