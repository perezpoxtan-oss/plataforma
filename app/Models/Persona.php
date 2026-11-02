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
 * Persona externa del padrón: visitante, personal de un proveedor o de un
 * contratista (SEGCAT: visitantes_proveedores).
 *
 * C-44: se identifica con el lector universal (QR de su pase o tarjeta
 * NFC/RFID). El folio de su identificación NO se busca con el lector: es un
 * dato personal y solo lo ve completo quien edita el padrón.
 */
class Persona extends Model implements Identificable
{
    use PerteneceAEmpresa, RegistraAutor, RestringibleEnAcceso, TieneIdentificador, VerificableEnPadron;

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

    public static function tipoLector(): string
    {
        return 'persona';
    }

    public static function permisoLector(): string
    {
        return 'visitantes.ver';
    }

    public function resumenLector(): array
    {
        $empresa = $this->empresaQueRepresenta();

        return [
            'titulo' => $this->nombre_completo,
            'detalle' => trim((self::TIPOS[$this->tipo] ?? 'Persona externa').($empresa ? ' · '.$empresa : '')),
            'activo' => (bool) $this->activo,
            'sede_id' => null,
        ];
    }

    public function urlLector(): string
    {
        return route('personas.index').'#persona-'.$this->id;
    }
}
