<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use App\Models\Concerns\TieneIdentificador;
use App\Models\Concerns\VerificableEnPadron;
use App\Support\Lector\Identificable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vehículo del padrón vehicular (SEGCAT: vehiculos). Lleva un código QR
 * aleatorio para su calcomanía.
 */
class Vehiculo extends Model implements Identificable
{
    use PerteneceAEmpresa, RegistraAutor, TieneIdentificador, VerificableEnPadron;

    /** Se encuentra también tecleando o escaneando las placas. */
    protected string $columnaLegible = 'placas';

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
        'capacidad', 'numero_economico', 'proveedor_id', 'colaborador_id', 'etiqueta_nfc', 'activo',
    ];

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

    public static function tipoLector(): string
    {
        return 'vehiculo';
    }

    public static function permisoLector(): string
    {
        return 'vehiculos.ver';
    }

    public function resumenLector(): array
    {
        return [
            'titulo' => $this->placas,
            'detalle' => trim($this->marca.' '.$this->modelo).' · '.$this->color.' · '.(self::PROPIEDADES[$this->propiedad] ?? $this->propiedad),
            'activo' => (bool) $this->activo,
            'sede_id' => null,
        ];
    }

    public function urlLector(): string
    {
        return route('vehiculos.index').'#vehiculo-'.$this->id;
    }
}
