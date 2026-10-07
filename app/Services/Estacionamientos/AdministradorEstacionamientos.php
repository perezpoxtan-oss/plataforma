<?php

namespace App\Services\Estacionamientos;

use App\Models\Sede;
use App\Models\User;
use App\Models\ZonaEstacionamiento;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de Estacionamientos y zonas (réplica de estacionamiento_proceso.php
 * de SEGCAT). Cada zona es de una sede: con alcance de sede solo se ven y se
 * administran las zonas de sus sedes.
 *
 * Todo corre con la empresa de trabajo fijada en el Tenant.
 */
class AdministradorEstacionamientos
{
    public function __construct(
        private readonly Autorizador $autorizador,
        private readonly AdministradorRoles $auditoria,
        private readonly OcupacionEstacionamientos $ocupacion,
    ) {}

    /**
     * @param  Builder<ZonaEstacionamiento>  $consulta
     * @return Builder<ZonaEstacionamiento>
     */
    public function limitar(Builder $consulta, User $actor, string $permiso): Builder
    {
        if ($actor->es_superadmin) {
            return $consulta;
        }

        $efectivo = $this->autorizador->permisosEfectivos($actor)[$permiso] ?? null;
        if ($efectivo === null) {
            return $consulta->whereRaw('1 = 0');
        }
        if ($efectivo->alcance === Alcance::Empresa) {
            return $consulta;
        }
        if ($efectivo->sedes !== null) {
            $consulta->whereIn('zonas_estacionamiento.sede_id', $efectivo->sedes);
        }
        if ($efectivo->alcance === Alcance::Propios) {
            $consulta->where('zonas_estacionamiento.creado_por', $actor->id);
        }

        return $consulta;
    }

    /**
     * @return list<int>|null null = todas las que ve
     */
    public function idsEnAlcance(User $actor, string $permiso): ?array
    {
        if (! $actor->can($permiso)) {
            return [];
        }
        if ($actor->es_superadmin) {
            return null;
        }
        $efectivo = $this->autorizador->permisosEfectivos($actor)[$permiso];
        if ($efectivo->alcance === Alcance::Empresa || ($efectivo->sedes === null && $efectivo->alcance !== Alcance::Propios)) {
            return null;
        }

        return $this->limitar(ZonaEstacionamiento::query(), $actor, $permiso)->pluck('zonas_estacionamiento.id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Sedes activas donde el actor puede usar el permiso.
     *
     * @return Collection<int, Sede>
     */
    public function sedesParaElegir(User $actor, string $permiso): Collection
    {
        $permitidas = $this->autorizador->sedesPermitidas($actor, $permiso);

        return Sede::where('activo', true)
            ->when($permitidas !== null, fn ($q) => $q->whereIn('id', $permitidas))
            ->orderBy('nombre')->get(['id', 'nombre']);
    }

    /**
     * Ocupación de cada zona (una sola consulta al proveedor para toda la lista).
     *
     * @param  Collection<int, ZonaEstacionamiento>  $zonas
     * @return array<int, int>
     */
    public function ocupados(Collection $zonas): array
    {
        $ids = $zonas->pluck('id')->map(fn ($id) => (int) $id)->all();
        $conteo = $ids === [] ? [] : $this->ocupacion->ocupados($ids);

        return collect($ids)->mapWithKeys(fn ($id) => [$id => max(0, (int) ($conteo[$id] ?? 0))])->all();
    }

    // ----------------------------------------------------------------- Escritura

    /**
     * @param  array<string, mixed>  $entrada
     */
    public function crear(User $actor, array $entrada): ZonaEstacionamiento
    {
        $zona = ZonaEstacionamiento::create($this->validar($actor, $entrada, null));
        $this->auditoria->auditar($actor, 'estacionamientos.creado', $zona, null, $this->foto($zona));

        return $zona;
    }

    /**
     * @param  array<string, mixed>  $entrada
     */
    public function actualizar(User $actor, ZonaEstacionamiento $zona, array $entrada): ZonaEstacionamiento
    {
        $antes = $this->foto($zona);
        $zona->fill($this->validar($actor, $entrada, $zona))->save();
        $this->auditoria->auditar($actor, 'estacionamientos.actualizado', $zona, $antes, $this->foto($zona));

        return $zona;
    }

    /**
     * Desactivar (ya no se podrá asignar en Accesos) o reactivar.
     */
    public function cambiarEstado(User $actor, ZonaEstacionamiento $zona, bool $activo): void
    {
        $zona->forceFill(['activo' => $activo])->save();
        $this->auditoria->auditar($actor, $activo ? 'estacionamientos.reactivado' : 'estacionamientos.desactivado', $zona, ['activo' => ! $activo], ['activo' => $activo]);
    }

    /**
     * @param  array<string, mixed>  $entrada
     * @return array<string, mixed>
     */
    public function validar(User $actor, array $entrada, ?ZonaEstacionamiento $actual): array
    {
        $entrada = array_intersect_key($entrada, array_flip(['sede_id', 'nombre', 'tipo', 'cupo_total']));
        $entrada['nombre'] = trim((string) preg_replace('/\s+/u', ' ', (string) ($entrada['nombre'] ?? '')));
        $entrada = array_map(fn ($v) => $v === '' ? null : $v, $entrada);

        $datos = Validator::make($entrada, [
            'sede_id' => ['required', 'integer'],
            'nombre' => ['required', 'string', 'max:100'],
            'tipo' => ['required', 'string', Rule::in(array_keys(ZonaEstacionamiento::TIPOS))],
            'cupo_total' => ['nullable', 'required_if:tipo,estacionamiento', 'integer', 'min:1', 'max:9999'],
        ], [
            'sede_id.required' => 'Elige la sede de la zona.',
            'nombre.required' => 'Escribe el nombre de la zona.',
            'nombre.max' => 'El nombre de la zona admite máximo 100 caracteres.',
            'tipo.required' => 'Elige el tipo de zona.',
            'tipo.in' => 'Elige el tipo de zona de la lista.',
            'cupo_total.required_if' => 'Un estacionamiento necesita su cupo total de espacios (1 o más).',
            'cupo_total.integer' => 'El cupo total debe ser un número entero de espacios.',
            'cupo_total.min' => ($entrada['tipo'] ?? null) === 'zona_descarga' ? 'La capacidad debe ser de al menos 1 vehículo (o déjala vacía si no tiene límite).' : 'El cupo total debe ser de al menos 1 espacio.',
            'cupo_total.max' => 'Revisa el cupo total: máximo 9999 espacios.',
        ])->validate();

        $sedeId = (int) $datos['sede_id'];
        if ($actual === null || $sedeId !== (int) $actual->sede_id) {
            $permiso = $actual === null ? 'estacionamientos.crear' : 'estacionamientos.editar';
            if (! $this->sedesParaElegir($actor, $permiso)->contains('id', $sedeId)) {
                throw ValidationException::withMessages(['sede_id' => 'Elige una sede activa de la lista: esa sede no existe, está desactivada o no está a tu cargo.']);
            }
        }

        $repetida = ZonaEstacionamiento::where('sede_id', $sedeId)
            ->whereRaw('LOWER(nombre) = ?', [mb_strtolower($datos['nombre'])])
            ->when($actual !== null, fn ($q) => $q->whereKeyNot($actual->id))->first();
        if ($repetida !== null) {
            throw ValidationException::withMessages(['nombre' => "Ya existe una zona «{$repetida->nombre}» en esa sede"
                .($repetida->activo ? '.' : ' (desactivada): reactívala en lugar de crearla otra vez.')]);
        }

        return [
            'sede_id' => $sedeId,
            'nombre' => $datos['nombre'],
            'tipo' => $datos['tipo'],
            // Ronda 6 (ES-02): en una zona de descarga la capacidad máxima es opcional (SEGCAT no la tenía)
            'cupo_total' => isset($datos['cupo_total']) ? (int) $datos['cupo_total'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function foto(ZonaEstacionamiento $z): array
    {
        return $z->only(['sede_id', 'nombre', 'tipo', 'cupo_total', 'activo']);
    }
}
