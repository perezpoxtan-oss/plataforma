<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una de las 6 firmas del expediente de Accidente. La imagen vive en el
 * disco privado (App\Services\Firmas\Firmas); aquí solo la ruta.
 * SEGCAT: accidente_firmas (6 columnas base64 en la base de datos).
 */
class AccidenteFirma extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    /** rol => texto del selector "Seleccione quién va a firmar en este momento" (SEGCAT, sin cambios). */
    public const ROLES = [
        'afectado' => 'Reportante / Afectado / Testigo',
        'seguridad' => 'Agente de Seguridad (Atiende)',
        'medico' => 'Servicio Médico (Si aplica)',
        'jefe' => 'Jefe de Área / Departamento',
        'rh' => 'Recursos Humanos (Si aplica)',
        'ejecutivo' => 'Ejecutivo de Guardia / Gerencia',
    ];

    protected $table = 'accidente_firmas';

    protected $fillable = ['empresa_id', 'novedad_id', 'rol', 'ruta'];

    public function novedad(): BelongsTo
    {
        return $this->belongsTo(Novedad::class);
    }

    public function firmante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizado_por');
    }
}
