<?php

namespace App\Services\Reservas;

use App\Models\Delegacion;
use App\Models\DepartamentoResponsable;
use App\Models\Empresa;
use App\Models\Reserva;
use App\Models\ReservaAprobacion;
use App\Models\ReservaOrden;
use App\Models\User;
use App\Support\RelojVoBo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lo que espera a cada persona en Reservas de áreas (criterio 1: campana y
 * Mis pendientes):
 *  - «Reservas por aprobar»: aprobaciones vigentes donde es responsable del
 *    área o del departamento (o su delegado, o se le avisó), con el color de
 *    la más atrasada.
 *  - «Órdenes de servicio por atender»: de sus departamentos, de reservas
 *    confirmadas que aún no terminan.
 *  - «Reservas con cambio pedido»: las suyas que un aprobador regresó.
 */
class PendientesReservas
{
    public function __construct(
        private readonly ResponsablesReservas $responsables,
    ) {}

    /**
     * Aprobaciones pendientes que le tocan (designado, delegado o avisado).
     * Con $todasLasQuePuede, además las de su alcance con reservas.aprobar.
     *
     * @return Collection<int, ReservaAprobacion>
     */
    public function aprobacionesPara(User $actor, bool $todasLasQuePuede = false): Collection
    {
        return ReservaAprobacion::with(['reserva.area.espacio', 'reserva.area.montajes', 'reserva.sede:id,nombre', 'reserva.solicitante:id,name', 'reserva.departamentoSolicitante:id,nombre', 'departamento:id,nombre'])
            ->where('estado', ReservaAprobacion::PENDIENTE)->where('vigente', true)
            ->whereHas('reserva', fn ($q) => $q->whereIn('estado', [Reserva::POR_APROBAR, Reserva::CAMBIO_PEDIDO]))
            ->orderBy('solicitada_en')->limit(500)->get()
            ->filter(fn (ReservaAprobacion $a) => $this->responsables->leToca($actor, $a, $a->reserva)
                || ($todasLasQuePuede && $actor->can('reservas.aprobar', $a->reserva)))
            ->values();
    }

    /**
     * Órdenes pendientes de los departamentos que atiende.
     *
     * @return Collection<int, ReservaOrden>
     */
    public function ordenesPara(User $actor, bool $todasLasQuePuede = false): Collection
    {
        return ReservaOrden::with(['reserva.area.espacio', 'reserva.montaje', 'reserva.items', 'reserva.sede:id,nombre', 'departamento:id,nombre'])
            ->where('estado', ReservaOrden::PENDIENTE)
            ->whereHas('reserva', fn ($q) => $q->where('estado', Reserva::CONFIRMADA)->where('fin', '>', now()))
            ->get()
            ->filter(fn (ReservaOrden $o) => $this->responsables->atiendeDepartamento($actor, (int) $o->departamento_id, (int) $o->reserva->sede_id, (int) $o->empresa_id)
                || ($todasLasQuePuede && $actor->can('reservas.configurar', $o->reserva)))
            ->sortBy(fn ($o) => $o->reserva->inicio)->values();
    }

    /**
     * Renglones de Mis pendientes (solo los que tienen algo). Se pinta en cada
     * pantalla: pocas consultas y sin cargar relaciones (una por cada lista,
     * los responsables de los departamentos involucrados en una sola, y las
     * delegaciones hacia el usuario en otra).
     *
     * @return list<array{clave: string, titulo: string, icono: string, total: int, url: string, nivel: ?string}>
     */
    public function para(User $actor, int $empresaId): array
    {
        // Empresa que nunca marcó un área como reservable: ninguna consulta (la empresa ya viene cargada con el usuario)
        $empresa = $actor->relationLoaded('empresa') && (int) $actor->empresa?->id === $empresaId ? $actor->empresa : Empresa::find($empresaId);
        if (empty($empresa?->preferencias[CatalogosReservas::EN_USO])) {
            return [];
        }
        $aprobaciones = DB::table('reserva_aprobaciones as a')->join('reservas as r', 'r.id', '=', 'a.reserva_id')
            ->join('reserva_areas as ra', 'ra.id', '=', 'r.reserva_area_id')
            ->where('a.empresa_id', $empresaId)->where('a.estado', ReservaAprobacion::PENDIENTE)->where('a.vigente', true)
            ->whereIn('r.estado', [Reserva::POR_APROBAR, Reserva::CAMBIO_PEDIDO])
            ->get(['a.id', 'a.tipo', 'a.departamento_id', 'a.avisados', 'a.escalado_a', 'a.solicitada_en', 'r.sede_id', 'ra.responsable_id', 'ra.suplente_id']);
        $ordenes = DB::table('reserva_ordenes as o')->join('reservas as r', 'r.id', '=', 'o.reserva_id')
            ->where('o.empresa_id', $empresaId)->where('o.estado', ReservaOrden::PENDIENTE)
            ->where('r.estado', Reserva::CONFIRMADA)->where('r.fin', '>', now())
            ->get(['o.id', 'o.departamento_id', 'r.sede_id']);
        $items = [];

        if ($aprobaciones->isNotEmpty() || $ordenes->isNotEmpty()) {
            $ajustes = app(AjustesReservas::class)->de($empresa);
            $generales = array_values(array_unique(array_filter([$ajustes['responsable_id'], $ajustes['suplente_id']])));
            $deptos = $aprobaciones->pluck('departamento_id')->merge($ordenes->pluck('departamento_id'))->filter()->unique()->values();
            $responsables = $deptos->isEmpty() ? collect() : DepartamentoResponsable::whereIn('departamento_id', $deptos)
                ->whereHas('usuario', fn ($q) => $q->where('activo', true))->get(['departamento_id', 'user_id', 'sede_id']);
            // Quienes le delegaron a este usuario («No molestar»): solo se consulta si hace falta
            $meDelegaron = null;
            $delegaron = function () use (&$meDelegaron, $actor): array {
                return $meDelegaron ??= Delegacion::activas()->where('delegado_id', $actor->id)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
            };
            $yo = (int) $actor->id;
            $deDepto = function (int $depto, int $sede) use ($responsables, $generales): array {
                $ids = $responsables->where('departamento_id', $depto)->filter(fn ($r) => $r->sede_id === null || (int) $r->sede_id === $sede)
                    ->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->values()->all();

                return $ids !== [] ? $ids : $generales;
            };
            $mios = fn (array $designados) => in_array($yo, $designados, true) || ($designados !== [] && array_intersect($designados, $delegaron()) !== []);

            $leTocan = $aprobaciones->filter(function ($a) use ($deDepto, $mios, $ajustes, $yo) {
                $avisados = array_map('intval', array_merge((array) json_decode((string) $a->avisados, true), (array) json_decode((string) $a->escalado_a, true)));
                if (in_array($yo, $avisados, true)) {
                    return true;
                }
                if ($a->tipo === 'area') {
                    $titular = $a->responsable_id ?? $ajustes['responsable_id'];
                    $suplente = $a->suplente_id ?? ($a->responsable_id !== null ? $ajustes['responsable_id'] : $ajustes['suplente_id']);

                    return $mios(array_map('intval', array_values(array_filter([$titular, $suplente]))));
                }

                return $mios($deDepto((int) $a->departamento_id, (int) $a->sede_id));
            });
            if ($leTocan->isNotEmpty()) {
                $umbrales = [$ajustes['recordar_horas'], $ajustes['escalar_horas']];
                $niveles = $leTocan->map(fn ($a) => RelojVoBo::de(CarbonImmutable::parse($a->solicitada_en, 'UTC'), null, $umbrales)['nivel'] ?? 'normal');
                $items[] = ['clave' => 'reservas_aprobar', 'titulo' => 'Reservas por aprobar', 'icono' => 'bi-calendar-check', 'total' => $leTocan->count(),
                    'url' => route('reservas.aprobaciones'), 'nivel' => $niveles->contains('roja') ? 'roja' : ($niveles->contains('ambar') ? 'ambar' : null)];
            }
            $atiende = $ordenes->filter(fn ($o) => $mios($deDepto((int) $o->departamento_id, (int) $o->sede_id)))->count();
            if ($atiende > 0) {
                $items[] = ['clave' => 'reservas_ordenes', 'titulo' => 'Órdenes de servicio por atender', 'icono' => 'bi-clipboard-check', 'total' => $atiende,
                    'url' => route('reservas.ordenes'), 'nivel' => null];
            }
        }

        $cambios = DB::table('reservas')->where('empresa_id', $empresaId)->where('solicitante_id', $actor->id)->where('estado', Reserva::CAMBIO_PEDIDO)->count();
        if ($cambios > 0) {
            $items[] = ['clave' => 'reservas_cambio', 'titulo' => 'Reservas con cambio pedido', 'icono' => 'bi-arrow-repeat', 'total' => $cambios,
                'url' => route('reservas.mias', ['estado' => Reserva::CAMBIO_PEDIDO]), 'nivel' => 'ambar'];
        }

        return $items;
    }
}
