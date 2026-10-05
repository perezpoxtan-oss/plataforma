<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;

/**
 * Días de resguardo por tipo de valor (semáforo de Lost & Found). Cada
 * empresa tiene los suyos; mientras no los configure se usan los de SEGCAT.
 * La pantalla "Días de Resguardo" llega con el archivo de Lost & Found.
 */
class LostFoundUmbral extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    /** SEGCAT lost_found_umbrales */
    public const POR_OMISION = ['ALTO_VALOR' => 180, 'ELECTRONICO' => 180, 'OTRO' => 90, 'PERECEDERO' => 2, 'ROPA' => 30];

    protected $table = 'lost_found_umbrales';

    protected $fillable = ['empresa_id', 'tipo_valor', 'dias'];

    protected function casts(): array
    {
        return ['dias' => 'integer'];
    }

    /**
     * Días por tipo de valor de la empresa activa (con los de omisión para lo que falte).
     *
     * @return array<string, int>
     */
    public static function vigentes(): array
    {
        return array_replace(self::POR_OMISION, static::query()->pluck('dias', 'tipo_valor')->map(fn ($d) => (int) $d)->all());
    }
}
