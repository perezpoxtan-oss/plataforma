<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Detalle de "Valores a la Vista" (categoría HABITACION de SEGCAT:
 * bitacora_habitaciones_detalles). Edificio y zona ya no se copian como
 * texto: salen del Área General del ticket.
 */
class ValoresVistaDetalle extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    public const CAJA_FUERTE = [
        'cerrada' => 'Cerrada / Segura',
        'abierta_sin_valores' => 'Abierta (Sin Valores en el interior)',
        'abierta_con_valores' => 'Abierta (Con Valores en el interior)',
    ];

    public const CAJA_ACCION = [
        'cerrada_bloqueada' => 'Se dejó cerrada y bloqueada (Valores a resguardo en la caja)',
        'abierta' => 'Se dejó abierta como se encontró (No se manipuló la puerta)',
    ];

    protected $table = 'valores_vista_detalles';

    protected $fillable = [
        'empresa_id', 'novedad_id', 'area_especifica_id', 'num_habitacion', 'quien_reporta', 'depto_reporta', 'puesto_reporta',
        'actividad_reporta', 'quien_atiende', 'depto_atiende', 'puesto_atiende', 'caja_fuerte', 'caja_accion', 'valores_dentro',
        'personal_retiro', 'cliente_llega', 'tomo_fotos', 'observaciones',
    ];

    protected function casts(): array
    {
        return ['cliente_llega' => 'boolean'];
    }

    public function novedad(): BelongsTo
    {
        return $this->belongsTo(Novedad::class);
    }

    public function areaEspecifica(): BelongsTo
    {
        return $this->belongsTo(Espacio::class, 'area_especifica_id');
    }
}
