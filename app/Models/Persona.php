<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use App\Models\Concerns\VerificableEnPadron;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persona externa del padrón: visitante, personal de un proveedor o de un
 * contratista (SEGCAT: visitantes_proveedores).
 */
class Persona extends Model
{
    use PerteneceAEmpresa, RegistraAutor, VerificableEnPadron;

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
     * Empresa de donde viene: el proveedor registrado o el texto libre.
     */
    public function empresaQueRepresenta(): ?string
    {
        return $this->proveedor?->nombre ?? $this->empresa_procedencia;
    }

    /**
     * Identificación para quien solo consulta: "INE ••••5678".
     */
    public function folioEnmascarado(): ?string
    {
        if ($this->folio_identificacion === null || $this->folio_identificacion === '') {
            return null;
        }

        $tipo = self::IDENTIFICACIONES[$this->tipo_identificacion] ?? 'ID';

        return $tipo.' ••••'.mb_substr($this->folio_identificacion, -4);
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
