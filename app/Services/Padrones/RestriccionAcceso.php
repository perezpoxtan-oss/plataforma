<?php

namespace App\Services\Padrones;

use App\Models\Persona;
use App\Models\Proveedor;
use App\Models\User;
use App\Models\Vehiculo;
use App\Services\Permisos\AdministradorRoles;
use App\Support\HoraLocal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * C-13 (DD-07): «Restringir acceso» y «Quitar restricción» en Personas,
 * Vehículos y Empresas externas. La restricción no da de baja: el registro
 * sigue en su padrón, pero la caseta no lo puede registrar (ni a algo que se
 * le parezca sin confirmar que es otro).
 */
class RestriccionAcceso
{
    /** padrón => [modelo, permiso, módulo de auditoría, ruta de la lista, ancla] */
    public const PADRONES = [
        'personas' => [Persona::class, 'visitantes.eliminar', 'visitantes', 'personas.index', 'persona-'],
        'vehiculos' => [Vehiculo::class, 'vehiculos.eliminar', 'vehiculos', 'vehiculos.index', 'vehiculo-'],
        'proveedores' => [Proveedor::class, 'proveedores.eliminar', 'proveedores', 'proveedores.index', 'proveedor-'],
    ];

    public function __construct(private readonly AdministradorRoles $auditoria) {}

    /**
     * @param  array<string, mixed>  $entrada  motivo, hasta (Y-m-d, opcional)
     */
    public function restringir(User $actor, string $padron, Model $registro, array $entrada): void
    {
        $hoy = Carbon::now(app(HoraLocal::class)->zona())->format('Y-m-d');
        $datos = Validator::make($entrada, [
            'motivo' => ['required', 'string', 'max:255'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$hoy],
        ], [
            'motivo.required' => 'Escribe el motivo de la restricción (lo verá la caseta).',
            'motivo.max' => 'El motivo es muy largo (máximo 255 caracteres).',
            'hasta.date_format' => 'La fecha de fin no es válida.',
            'hasta.after_or_equal' => 'La fecha de fin no puede ser antes de hoy.',
        ])->validate();

        $antes = $this->foto($registro);
        $registro->forceFill([
            'restringido_en' => now(),
            'restringido_hasta' => $datos['hasta'] ?? null,
            'restringido_motivo' => trim((string) preg_replace('/\s+/u', ' ', $datos['motivo'])),
            'restringido_por' => $actor->id,
        ])->save();
        $this->auditoria->auditar($actor, self::PADRONES[$padron][2].'.restringido', $registro, $antes, $this->foto($registro));
    }

    public function quitar(User $actor, string $padron, Model $registro): void
    {
        if ($registro->getAttribute('restringido_en') === null) {
            throw ValidationException::withMessages(['motivo' => 'No tiene el acceso restringido.']);
        }
        $antes = $this->foto($registro);
        $registro->forceFill(['restringido_en' => null, 'restringido_hasta' => null, 'restringido_motivo' => null, 'restringido_por' => null])->save();
        $this->auditoria->auditar($actor, self::PADRONES[$padron][2].'.restriccion_quitada', $registro, $antes, $this->foto($registro));
    }

    /** @return array<string, mixed> */
    private function foto(Model $r): array
    {
        return ['restringido' => $r->getAttribute('restringido_en') !== null, 'hasta' => $r->getAttribute('restringido_hasta')?->format('Y-m-d'),
            'motivo' => $r->getAttribute('restringido_motivo')];
    }
}
