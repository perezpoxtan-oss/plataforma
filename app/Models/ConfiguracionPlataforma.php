<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConfiguracionPlataforma extends Model
{
    protected $table = 'configuracion_plataforma';

    protected $fillable = ['clave', 'valor'];

    protected function casts(): array
    {
        return ['valor' => 'array'];
    }
}
