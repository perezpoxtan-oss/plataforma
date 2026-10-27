<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Memoria del motor «Reloj y suplente» (C-22): qué avisó ya de cada asunto
 * que espera (recordatorio y paso al suplente, una sola vez cada uno).
 * empresa_id nulo = asunto de la plataforma (Super Administrador). El motor
 * siempre filtra por empresa de forma explícita (no usa el scope de tenant
 * porque también atiende lo de la plataforma).
 */
class RelojEspera extends Model
{
    protected $table = 'relojes_esperas';

    protected $fillable = ['empresa_id', 'reloj', 'referencia_tipo', 'referencia_id', 'desde', 'recordado_en', 'escalado_en', 'escalado_a'];

    protected function casts(): array
    {
        return ['desde' => 'datetime', 'recordado_en' => 'datetime', 'escalado_en' => 'datetime', 'escalado_a' => 'array'];
    }
}
