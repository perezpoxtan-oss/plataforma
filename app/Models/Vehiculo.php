<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use App\Models\Concerns\RestringibleEnAcceso;
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
    use PerteneceAEmpresa, RegistraAutor, RestringibleEnAcceso, TieneIdentificador, VerificableEnPadron;

    /** Se encuentra también tecleando o escaneando las placas. */
    protected string $columnaLegible = 'placas';

    /**
     * clave => etiqueta. Decisión del dueño (mapa de procesos): las ocho
     * categorías de SEGCAT (propiedad_vehiculo) se funden en dos.
     *  - particular: propio de un colaborador, huésped, visitante, familiar…
     *    Puede ligarse al colaborador dueño (opcional); no lleva empresa.
     *  - empresa: flotilla, agencia de renta, taxi, transporte de personal…
     *    Lleva empresa propietaria y número económico.
     */
    public const PROPIEDADES = [
        'particular' => 'Particular',
        'empresa' => 'De una empresa',
    ];

    /** Propiedades que llevan número económico (flotillas). */
    public const CON_NUMERO_ECONOMICO = ['empresa'];

    /**
     * Categorías anteriores (antes de fundirlas) => la nueva. Sirve para la
     * conversión de datos y para aceptar una clave vieja que todavía llegue
     * de una pantalla abierta antes de la actualización.
     */
    public const CATEGORIAS_ANTERIORES = [
        'propio_huesped' => 'particular',
        'propio_visitante' => 'particular',
        'propio_familiar' => 'particular',
        'propio_colaborador' => 'particular',
        'agencia_renta' => 'empresa',
        'empresa_proveedor' => 'empresa',
        'taxi_app' => 'empresa',
        'transporte_personal' => 'empresa',
    ];

    public const TIPOS = [
        'sedan' => 'Sedán', 'suv' => 'SUV / Camioneta', 'pickup' => 'Pick-up', 'autobus' => 'Autobús',
        'camion_ligero' => 'Camión ligero', 'motocicleta' => 'Motocicleta', 'otro' => 'Otro',
    ];

    protected $attributes = ['activo' => true, 'propiedad' => 'particular'];

    protected $fillable = [
        'empresa_id', 'placas', 'tipo', 'descripcion_otro', 'marca', 'modelo', 'color', 'propiedad',
        'capacidad', 'numero_economico', 'proveedor_id', 'colaborador_id', 'etiqueta_nfc', 'activo',
    ];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'capacidad' => 'integer'];
    }

    protected static function booted(): void
    {
        // La categoría anterior (de las ocho originales) solo se conserva mientras siga
        // correspondiendo: si se cambia a la otra categoría, ya no hay a dónde regresar.
        static::saving(function (Vehiculo $v) {
            $anterior = $v->getAttribute('categoria_anterior');
            if ($anterior !== null && $v->isDirty('propiedad') && (self::CATEGORIAS_ANTERIORES[$anterior] ?? null) !== $v->propiedad) {
                $v->setAttribute('categoria_anterior', null);
            }
        });
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
     * Clave vigente de una categoría (convierte las ocho anteriores).
     */
    public static function categoria(?string $propiedad): ?string
    {
        return self::CATEGORIAS_ANTERIORES[$propiedad] ?? $propiedad;
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
