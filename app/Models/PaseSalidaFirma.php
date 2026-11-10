<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Firma de un pase de salida (SEGCAT: pases_salida_firmas): de un aprobador,
 * de Seguridad o de la persona que entrega o se lleva el equipo. La imagen
 * vive en el disco privado (App\Services\Firmas\Firmas) y solo se entrega por
 * PaseSalidaController::firma, con permiso y alcance. No se edita.
 */
class PaseSalidaFirma extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'pases_salida_firmas';

    protected $fillable = ['empresa_id', 'pase_salida_id', 'bitacora_id', 'grupo', 'rol', 'nombre_firma', 'user_id', 'cargo', 'firma_ruta'];

    protected static function booted(): void
    {
        // Inmutable: una firma no se corrige; si hubo error, se rechaza o se firma de nuevo
        static::updating(fn () => false);
    }

    public function pase(): BelongsTo
    {
        return $this->belongsTo(PaseSalida::class, 'pase_salida_id');
    }

    public function capturo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function etiquetaRol(): string
    {
        return $this->cargo ?: PaseSalida::etiquetaRol($this->rol);
    }
}
