<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Artículo que sale con un pase (SEGCAT: pases_salida_articulos). Si se
 * escaneó del padrón de Equipos de seguridad, queda ligado con equipo_id.
 * La caseta marca su salida (verificado_salida_en, con lector o a mano) y
 * lo que va regresando (cantidad_regresada; admite regresos parciales).
 */
class PaseSalidaArticulo extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'pases_salida_articulos';

    protected $attributes = ['cantidad_regresada' => 0, 'verificado_con_lector' => false];

    protected $fillable = ['empresa_id', 'pase_salida_id', 'equipo_id', 'cantidad', 'equipo', 'marca', 'modelo', 'serie', 'descripcion'];

    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
            'cantidad_regresada' => 'integer',
            'verificado_con_lector' => 'boolean',
            'verificado_salida_en' => 'datetime',
        ];
    }

    public function pase(): BelongsTo
    {
        return $this->belongsTo(PaseSalida::class, 'pase_salida_id');
    }

    /** Cuántos faltan por regresar. */
    public function pendientes(): int
    {
        return max(0, $this->cantidad - $this->cantidad_regresada);
    }

    /** "1x Laptop LENOVO L14 · Serie: ABC123" */
    public function resumen(): string
    {
        $marcaModelo = trim(($this->marca ?? '').' '.($this->modelo ?? ''));

        return trim($this->cantidad.'x '.$this->equipo.($marcaModelo !== '' ? ' '.$marcaModelo : '').($this->serie ? ' · Serie: '.$this->serie : ''));
    }
}
