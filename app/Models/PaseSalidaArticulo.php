<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Artículo que sale con un pase (SEGCAT: pases_salida_articulos). Si se
 * escaneó del padrón de Equipos de seguridad, queda ligado con equipo_id.
 */
class PaseSalidaArticulo extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'pases_salida_articulos';

    protected $fillable = ['empresa_id', 'pase_salida_id', 'equipo_id', 'cantidad', 'equipo', 'marca', 'modelo', 'serie', 'descripcion'];

    protected function casts(): array
    {
        return ['cantidad' => 'integer'];
    }

    public function pase(): BelongsTo
    {
        return $this->belongsTo(PaseSalida::class, 'pase_salida_id');
    }

    /** "1x Laptop LENOVO L14 · Serie: ABC123" */
    public function resumen(): string
    {
        $marcaModelo = trim(($this->marca ?? '').' '.($this->modelo ?? ''));

        return trim($this->cantidad.'x '.$this->equipo.($marcaModelo !== '' ? ' '.$marcaModelo : '').($this->serie ? ' · Serie: '.$this->serie : ''));
    }
}
