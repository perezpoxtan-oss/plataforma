<?php

namespace App\Services\Reservas;

use App\Models\Delegacion;
use App\Models\Reserva;
use App\Models\ReservaAprobacion;
use App\Models\ReservaArea;
use App\Models\User;
use App\Services\Autorizaciones\Autorizaciones;
use App\Services\Recepcion\Destinatarios;
use Illuminate\Support\Collection;

/**
 * Quién aprueba y quién atiende en Reservas de áreas (sin permisos en código:
 * la designación la hace quien configura):
 *
 *  - Área: su responsable y su suplente; si el área no tiene, el responsable
 *    general de reservas de la empresa y su suplente (Configuración).
 *  - Departamento: sus responsables y suplentes de siempre (Responsables por
 *    departamento), los mismos que autorizan visitas.
 *  - «No molestar»: si alguno delegó, el aviso le llega a su delegado, que
 *    también puede responder (Delegaciones existentes).
 *  - Sin nadie designado: quien tiene reservas.aprobar en la sede.
 *
 * Además, quien tiene reservas.aprobar con alcance en la sede puede responder
 * cualquier aprobación (por ejemplo, el gerente cuando el responsable falta).
 */
class ResponsablesReservas
{
    /** @var array<string, mixed> */
    private array $memoria = [];

    public function __construct(
        private readonly AjustesReservas $ajustes,
        private readonly Autorizaciones $autorizaciones,
        private readonly Destinatarios $destinatarios,
    ) {}

    /**
     * Titular y suplente del área (los del área o los generales).
     *
     * @return array{0: ?int, 1: ?int}
     */
    public function delArea(ReservaArea $area): array
    {
        $general = $this->memoria['ajustes:'.$area->empresa_id] ??= $this->ajustes->de((int) $area->empresa_id);
        $titular = $area->responsable_id ?? $general['responsable_id'];
        $suplente = $area->suplente_id ?? ($area->responsable_id !== null ? $general['responsable_id'] : $general['suplente_id']);

        return [$titular === null ? null : (int) $titular, $suplente === null || (int) $suplente === (int) $titular ? null : (int) $suplente];
    }

    /**
     * Responsable general de reservas y su suplente.
     *
     * @return list<int>
     */
    public function generales(int $empresaId): array
    {
        $general = $this->memoria['ajustes:'.$empresaId] ??= $this->ajustes->de($empresaId);

        return array_values(array_unique(array_filter([$general['responsable_id'], $general['suplente_id']])));
    }

    /**
     * Ids designados para responder (sin delegaciones).
     *
     * @return list<int>
     */
    public function designados(ReservaAprobacion $a, Reserva $reserva): array
    {
        if ($a->tipo === 'area') {
            return array_values(array_filter($this->delArea($reserva->area)));
        }

        $ids = $this->deDepartamento((int) $a->departamento_id, (int) $reserva->sede_id);

        // Departamento sin responsables: lo atiende el responsable general de reservas
        return $ids !== [] ? array_values(array_unique($ids)) : $this->generales((int) $reserva->empresa_id);
    }

    /**
     * ¿Puede responder esta aprobación? Designado (o su delegado), avisado
     * por el recordatorio o el escalamiento, o con reservas.aprobar en la sede.
     */
    public function puedeResponder(User $usuario, ReservaAprobacion $a, Reserva $reserva): bool
    {
        if (! $a->pendiente() || ! $a->vigente) {
            return false;
        }
        if ($usuario->can('reservas.aprobar', $reserva)) {
            return true;
        }

        return $this->leToca($usuario, $a, $reserva);
    }

    /** Designado, delegado de un designado, o a quien se le avisó (recordatorio o escalamiento). */
    public function leToca(User $usuario, ReservaAprobacion $a, Reserva $reserva): bool
    {
        $id = (int) $usuario->id;
        $designados = $this->designados($a, $reserva);
        if (in_array($id, $designados, true)) {
            return true;
        }
        $avisados = array_map('intval', array_merge((array) ($a->avisados ?? []), (array) ($a->escalado_a ?? [])));
        if (in_array($id, $avisados, true)) {
            return true;
        }

        return in_array($id, $this->delegadosDe($designados), true);
    }

    /**
     * A quién avisar de una aprobación nueva.
     *
     * @return Collection<int, User>
     */
    public function aQuienAvisar(ReservaAprobacion $a, Reserva $reserva): Collection
    {
        if ($a->tipo === 'area') {
            [$titular, $suplente] = $this->delArea($reserva->area);
            $ids = array_values(array_filter([$titular ?? $suplente]));
        } else {
            $ids = $this->designados($a, $reserva);
        }
        $usuarios = $this->conDelegados($ids);

        return $usuarios->isNotEmpty() ? $usuarios
            : $this->destinatarios->conPermiso((int) $reserva->empresa_id, 'reservas.aprobar', (int) $reserva->sede_id);
    }

    /**
     * Recordatorio: los mismos más el suplente del área.
     *
     * @return Collection<int, User>
     */
    public function aQuienRecordar(ReservaAprobacion $a, Reserva $reserva): Collection
    {
        $ids = array_map('intval', (array) ($a->avisados ?? []));
        if ($a->tipo === 'area') {
            $ids = array_merge($ids, array_values(array_filter($this->delArea($reserva->area))));
        } else {
            $ids = array_merge($ids, $this->designados($a, $reserva));
        }

        return $this->conDelegados(array_values(array_unique($ids)));
    }

    /**
     * Escalamiento: responsable general y su suplente; si ya estaban
     * avisados, quien configura las reservas en la sede.
     *
     * @return Collection<int, User>
     */
    public function aQuienEscalar(ReservaAprobacion $a, Reserva $reserva): Collection
    {
        $ya = array_map('intval', array_merge((array) ($a->avisados ?? []), (array) ($a->escalado_a ?? [])));
        $usuarios = $this->conDelegados($this->generales((int) $reserva->empresa_id))->reject(fn (User $u) => in_array((int) $u->id, $ya, true))->values();
        if ($usuarios->isEmpty()) {
            $usuarios = $this->destinatarios->conPermiso((int) $reserva->empresa_id, 'reservas.configurar', (int) $reserva->sede_id)
                ->reject(fn (User $u) => in_array((int) $u->id, $ya, true))->values();
        }

        return $usuarios;
    }

    /**
     * ¿Atiende este departamento en esta sede? (responsable, suplente o su delegado)
     */
    public function atiendeDepartamento(User $usuario, int $departamentoId, int $sedeId, int $empresaId): bool
    {
        $ids = $this->deDepartamento($departamentoId, $sedeId);
        if ($ids === []) {
            $ids = $this->generales($empresaId);
        }

        return in_array((int) $usuario->id, $ids, true) || in_array((int) $usuario->id, $this->delegadosDe($ids), true);
    }

    /**
     * Quién recibe la orden de servicio del departamento.
     *
     * @return Collection<int, User>
     */
    public function delDepartamento(int $departamentoId, int $sedeId, int $empresaId): Collection
    {
        $usuarios = $this->autorizaciones->aQuienAvisar($departamentoId, $sedeId);

        return $usuarios->isNotEmpty() ? $usuarios : $this->conDelegados($this->generales($empresaId));
    }

    /**
     * Usuarios activos; quien tiene una delegación activa se cambia por su delegado.
     *
     * @param  list<int>  $ids
     * @return Collection<int, User>
     */
    public function conDelegados(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }
        $usuarios = User::whereIn('id', $ids)->where('activo', true)->get();
        $delegaciones = Delegacion::activas()->with('delegado:id,name,email,activo')->whereIn('user_id', $usuarios->pluck('id'))
            ->orderByDesc('id')->get()->unique('user_id')->keyBy('user_id');

        return $usuarios->map(fn (User $u) => ($d = $delegaciones[$u->id] ?? null) && $d->delegado?->activo ? $d->delegado : $u)
            ->unique('id')->values();
    }

    /**
     * Responsables (titular y suplentes) del departamento en la sede.
     *
     * @return list<int>
     */
    private function deDepartamento(int $departamentoId, int $sedeId): array
    {
        return $this->memoria["depto:{$departamentoId}:{$sedeId}"] ??= $this->autorizaciones->responsablesDe($departamentoId, $sedeId)
            ->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function delegadosDe(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        sort($ids);

        return $this->memoria['delegados:'.implode(',', $ids)] ??= Delegacion::activas()->whereIn('user_id', $ids)->pluck('delegado_id')->map(fn ($id) => (int) $id)->all();
    }
}
