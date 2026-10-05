<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persona externa del padrón: visitante, personal de un proveedor o de un
 * contratista (SEGCAT: visitantes_proveedores).
 */
class Persona extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    public const TIPOS = ['visitante' => 'Visitante', 'proveedor' => 'Proveedor', 'contratista' => 'Contratista'];

    public const CATEGORIAS = ['general' => 'General', 'prospecto_rrhh' => 'Prospecto de RR. HH.', 'familiar' => 'Familiar'];

    public const IDENTIFICACIONES = [
        'ine' => 'INE', 'pasaporte' => 'Pasaporte', 'licencia' => 'Licencia de conducir', 'curp' => 'CURP',
        'cedula' => 'Cédula profesional', 'id_imss' => 'Credencial IMSS', 'otra' => 'Otra',
    ];

    protected $attributes = ['activo' => true, 'tipo' => 'visitante', 'categoria' => 'general'];

    protected $fillable = [
        'empresa_id', 'tipo', 'categoria', 'proveedor_id', 'nombre_completo', 'empresa_procedencia',
        'tipo_identificacion', 'folio_identificacion', 'telefono', 'motivo_visita', 'activo',
    ];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    /**
     * Mayúsculas, sin espacios, guiones ni puntos: "abc-123 45" -> "ABC12345".
     */
    public static function normalizarFolio(?string $folio): ?string
    {
        $limpio = mb_strtoupper((string) preg_replace('/[\s\-.\/]+/u', '', (string) $folio));

        return $limpio === '' ? null : $limpio;
    }
}
