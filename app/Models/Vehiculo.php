<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Vehículo del padrón vehicular (SEGCAT: vehiculos). Lleva un código QR
 * aleatorio para su calcomanía.
 */
class Vehiculo extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    /** clave => etiqueta (SEGCAT: propiedad_vehiculo) */
    public const PROPIEDADES = [
        'propio_huesped' => 'Propio — Huésped',
        'propio_visitante' => 'Propio — Visitante',
        'propio_familiar' => 'Propio — Familiar',
        'propio_colaborador' => 'Propio — Colaborador',
        'agencia_renta' => 'Agencia de renta',
        'empresa_proveedor' => 'Empresa / Proveedor',
        'taxi_app' => 'Taxi / App',
        'transporte_personal' => 'Transporte de personal',
    ];

    /** Propiedades que llevan número económico (flotillas). */
    public const CON_NUMERO_ECONOMICO = ['agencia_renta', 'empresa_proveedor', 'taxi_app', 'transporte_personal'];

    public const TIPOS = [
        'sedan' => 'Sedán', 'suv' => 'SUV / Camioneta', 'pickup' => 'Pick-up', 'autobus' => 'Autobús',
        'camion_ligero' => 'Camión ligero', 'motocicleta' => 'Motocicleta', 'otro' => 'Otro',
    ];

    protected $attributes = ['activo' => true, 'propiedad' => 'propio_visitante'];

    protected $fillable = [
        'empresa_id', 'placas', 'tipo', 'descripcion_otro', 'marca', 'modelo', 'color', 'propiedad',
        'capacidad', 'numero_economico', 'proveedor_id', 'colaborador_id', 'activo',
    ];

    protected static function booted(): void
    {
        // Código de la calcomanía: aleatorio y no adivinable (en SEGCAT era consecutivo)
        static::creating(function (Vehiculo $v) {
            $v->codigo_qr ??= Str::lower(Str::random(24));
        });
    }

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'capacidad' => 'integer'];
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }

    /**
     * Mayúsculas, sin espacios ni guiones: "abc-12-34" -> "ABC1234".
     */
    public static function normalizarPlacas(?string $placas): string
    {
        return mb_strtoupper((string) preg_replace('/[\s\-.]+/u', '', (string) $placas));
    }
}
