<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Paso del circuito de aprobación de pases de salida de una empresa
 * (Configuración → Pases de salida). Se aprueban en orden.
 *
 *  - tipo "permiso":  cualquier usuario con "Aprobar" de Pases de salida en la sede de origen;
 *  - tipo "rol":      los usuarios con ese rol;
 *  - tipo "usuarios": solo los usuarios elegidos.
 * Departamento: "solicitante" = solo quien es del mismo departamento que el
 * solicitante (el "Jefe del departamento"); "especifico" = de ese departamento.
 * motivos = null aplica a todos; si no, solo a esos motivos (p. ej. Gerencia
 * solo en venta y traspaso definitivo).
 */
class PaseSalidaPaso extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'pases_salida_pasos';

    public const TIPOS = [
        'permiso' => 'Cualquier usuario con permiso «Aprobar» en la sede de origen',
        'rol' => 'Los usuarios con un rol',
        'usuarios' => 'Usuarios específicos',
    ];

    public const DEPARTAMENTOS = [
        'cualquiera' => 'De cualquier departamento',
        'solicitante' => 'Del mismo departamento que el solicitante (jefe del departamento)',
        'especifico' => 'De un departamento en particular',
    ];

    protected $attributes = ['tipo' => 'permiso', 'departamento' => 'cualquiera', 'obligatorio' => true, 'activo' => true];

    protected $fillable = ['empresa_id', 'orden', 'nombre', 'tipo', 'rol_id', 'departamento', 'departamento_id', 'obligatorio', 'motivos', 'activo'];

    protected function casts(): array
    {
        return ['obligatorio' => 'boolean', 'activo' => 'boolean', 'motivos' => 'array', 'orden' => 'integer'];
    }

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class);
    }

    public function departamentoFijo(): BelongsTo
    {
        return $this->belongsTo(Departamento::class, 'departamento_id');
    }

    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'pases_salida_paso_usuarios', 'paso_id', 'user_id')
            ->withPivot('empresa_id')->withTimestamps();
    }

    public function aplicaA(string $motivo): bool
    {
        return empty($this->motivos) || in_array($motivo, $this->motivos, true);
    }
}
