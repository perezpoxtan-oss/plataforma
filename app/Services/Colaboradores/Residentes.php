<?php

namespace App\Services\Colaboradores;

use App\Models\Colaborador;
use App\Models\User;
use App\Services\Espacios\Lugares;
use App\Services\Permisos\AdministradorRoles;
use App\Support\Entrada;
use Illuminate\Validation\ValidationException;

/**
 * C-10 (DD-14, opción B): en condominio y fraccionamiento, el residente es un
 * tipo de persona del directorio con su vivienda. El alta es corta (regla 8):
 * nombre, apellidos, vivienda y teléfono; la sede sale sola de la vivienda.
 * No pide número de empleado, puesto, turno ni datos legales.
 *
 * Permisos: los del directorio (colaboradores.*), sin reglas en código. El
 * alcance de sede se respeta: solo se ofrecen las viviendas de sus sedes.
 * La baja usa la misma regla del directorio (AdministradorColaboradores).
 */
class Residentes
{
    public function __construct(
        private readonly AdministradorColaboradores $colaboradores,
        private readonly AdministradorRoles $auditoria,
        private readonly Lugares $lugares,
    ) {}

    /**
     * @param  array<string, mixed>  $entrada
     */
    public function crear(User $actor, array $entrada): Colaborador
    {
        $datos = $this->validar($actor, $entrada, 'colaboradores.crear', null);
        $residente = Colaborador::create($datos + ['tipo' => Colaborador::RESIDENTE, 'activo' => true]);
        $this->auditoria->auditar($actor, 'colaboradores.creado', $residente, null, $this->foto($residente));

        return $residente;
    }

    /**
     * @param  array<string, mixed>  $entrada
     */
    public function actualizar(User $actor, Colaborador $residente, array $entrada): Colaborador
    {
        $antes = $this->foto($residente);
        $datos = $this->validar($actor, $entrada, 'colaboradores.editar', $residente);
        $residente->fill($datos)->save();
        $despues = $this->foto($residente);
        if ($antes !== $despues) {
            $this->auditoria->auditar($actor, 'colaboradores.actualizado', $residente, $antes, $despues);
        }

        return $residente;
    }

    /**
     * @param  array<string, mixed>  $entrada
     * @return array<string, mixed>
     */
    private function validar(User $actor, array $entrada, string $permiso, ?Colaborador $actual): array
    {
        $nombre = trim(Entrada::texto($entrada['nombre'] ?? null));
        $paterno = trim(Entrada::texto($entrada['apellido_paterno'] ?? null));
        $materno = trim(Entrada::texto($entrada['apellido_materno'] ?? null));
        $telefono = trim(Entrada::texto($entrada['telefono'] ?? null));
        $errores = [];
        if ($nombre === '' || mb_strlen($nombre) > 100) {
            $errores['nombre'] = $nombre === '' ? 'Escribe el nombre del residente.' : 'El nombre admite máximo 100 caracteres.';
        }
        if ($paterno === '' || mb_strlen($paterno) > 100) {
            $errores['apellido_paterno'] = $paterno === '' ? 'Escribe el primer apellido.' : 'El apellido admite máximo 100 caracteres.';
        }
        if (mb_strlen($materno) > 100) {
            $errores['apellido_materno'] = 'El apellido admite máximo 100 caracteres.';
        }
        if ($telefono !== '' && ! preg_match('/^[0-9 +()\-]{7,20}$/', $telefono)) {
            $errores['telefono'] = 'Escribe el teléfono con números (de 7 a 20).';
        }
        $sedes = $this->colaboradores->sedesPermitidas($actor, $permiso);
        $vivienda = $this->lugares->valido($entrada['espacio_id'] ?? null, $sedes, Lugares::ESPECIFICOS);
        $esLaActual = $actual !== null && (int) ($entrada['espacio_id'] ?? 0) === (int) $actual->espacio_id;
        if ($vivienda === null && ! $esLaActual) {
            $errores['espacio_id'] = 'Elige su vivienda de la lista (Zonas y edificios de tus sedes).';
        }
        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }
        $vivienda ??= $actual?->vivienda;

        // La misma persona en la misma vivienda: no se duplica
        $repetido = Colaborador::residentes()->where('espacio_id', $vivienda?->id)
            ->where('nombre_normalizado', Colaborador::normalizarNombre($nombre, $paterno))
            ->when($actual !== null, fn ($q) => $q->whereKeyNot($actual->id))->first();
        if ($repetido !== null) {
            throw ValidationException::withMessages(['nombre' => "«{$repetido->nombreCompleto()}» ya está registrado en esa vivienda".($repetido->activo ? '.' : ' (dado de baja): reactívalo con el mismo botón.')]);
        }

        return [
            'nombre' => mb_convert_case(mb_strtolower($nombre), MB_CASE_TITLE), 'apellido_paterno' => mb_convert_case(mb_strtolower($paterno), MB_CASE_TITLE),
            'apellido_materno' => $materno === '' ? null : mb_convert_case(mb_strtolower($materno), MB_CASE_TITLE),
            'telefono' => $telefono === '' ? null : $telefono,
            'espacio_id' => $vivienda?->id, 'sede_id' => $vivienda?->sede_id ?? $actual?->sede_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function foto(Colaborador $c): array
    {
        return $c->only(['tipo', 'nombre', 'apellido_paterno', 'apellido_materno', 'sede_id', 'espacio_id', 'activo'])
            + ['telefono' => Colaborador::enmascarar($c->telefono)];
    }
}
