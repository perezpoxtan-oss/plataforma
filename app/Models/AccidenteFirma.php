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
        // Ronda 8 (NV-03): firmantes que SEGCAT no tenía
        'testigo' => 'Testigo',
        'supervisor' => 'Supervisor de Seguridad',
    ];

    /**
     * Ronda 8 (NV-03): quién firma depende de quién se accidentó. Mismas
     * claves que SEGCAT (afectado, seguridad, medico, jefe, rh, ejecutivo) con
     * el texto adecuado a cada caso; sin "Tipo de Afectado" se ofrecen todas.
     */
    public const ROLES_POR_TIPO = [
        'HUESPED' => [
            'afectado' => 'Huésped / Afectado',
            'testigo' => 'Testigo',
            'seguridad' => 'Agente de Seguridad (Atiende)',
            'supervisor' => 'Supervisor de Seguridad',
            'medico' => 'Médico / Enfermería (Si aplica)',
            'ejecutivo' => 'Gerente en Turno / Ejecutivo de Guardia',
        ],
        'COLABORADOR' => [
            'afectado' => 'Colaborador Afectado',
            'jefe' => 'Jefe Inmediato (Jefe de Área / Departamento)',
            'testigo' => 'Testigo',
            'seguridad' => 'Agente de Seguridad (Atiende)',
            'supervisor' => 'Supervisor de Seguridad',
            'medico' => 'Servicio Médico (Si aplica)',
            'rh' => 'Recursos Humanos (Si aplica)',
        ],
    ];

    /**
     * Firmantes (rol => texto) según el Tipo de Afectado (HUESPED, COLABORADOR o vacío).
     *
     * @return array<string, string>
     */
    public static function rolesPara(?string $tipo): array
    {
        return self::ROLES_POR_TIPO[$tipo] ?? self::ROLES;
    }

    /**
     * Para el selector de la pantalla: tipo ('' = sin elegir) => [[rol, texto], …] (en orden).
     *
     * @return array<string, list<array{0: string, 1: string}>>
     */
    public static function rolesParaPantalla(): array
    {
        $pares = fn (array $roles) => array_map(fn ($rol, $texto) => [$rol, $texto], array_keys($roles), $roles);

        return ['' => $pares(self::ROLES)] + array_map($pares, self::ROLES_POR_TIPO);
    }

    /** Texto del firmante para ese tipo de afectado (o el general). */
    public static function etiqueta(string $rol, ?string $tipo): string
    {
        return self::rolesPara($tipo)[$rol] ?? self::ROLES[$rol] ?? $rol;
    }

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
