<?php

namespace App\Services\Relojes\Relojes;

use App\Models\Reserva;
use App\Models\ReservaOrden;
use App\Models\User;
use App\Services\Recepcion\Destinatarios;
use App\Services\Relojes\Espera;
use App\Services\Relojes\Reloj;
use App\Services\Reservas\ResponsablesReservas;
use App\Support\HoraLocal;
use Illuminate\Support\Collection;

/**
 * Orden de servicio de un evento (DD-17, D-08): un día antes del montaje se
 * le recuerda al departamento que la atiende y, si al empezar el montaje
 * sigue «Por atender», pasa al responsable general de Reservas.
 */
class OrdenesDeServicio extends Reloj
{
    public const HORAS_ANTES = 24;

    public function clave(): string
    {
        return 'ordenes_servicio';
    }

    public function nombre(): string
    {
        return 'Órdenes de servicio antes del montaje';
    }

    public function suplenteOmision(): string
    {
        return 'el responsable general de Reservas';
    }

    public function modulos(): array
    {
        return ['reservas'];
    }

    public function permiso(): ?string
    {
        return 'reservas.configurar';
    }

    public function umbralesPropios($empresa): ?array
    {
        return [1, self::HORAS_ANTES * 60];
    }

    public function dondeSeConfigura(): ?string
    {
        return 'Un día antes del montaje y al empezar el montaje';
    }

    public function esperas(?int $empresaId): Collection
    {
        return ReservaOrden::with('reserva:id,empresa_id,sede_id,folio,titulo,inicio,fin,montaje_minutos,estado', 'departamento:id,nombre')
            ->where('estado', ReservaOrden::PENDIENTE)
            ->whereHas('reserva', fn ($q) => $q->where('estado', Reserva::CONFIRMADA)->where('fin', '>', now()))
            ->orderBy('id')->limit(500)->get()
            ->map(function (ReservaOrden $o) {
                $montaje = $o->reserva->inicio->copy()->subMinutes((int) ($o->reserva->montaje_minutos ?? 0));

                return new Espera('reserva_orden', (int) $o->id, $montaje->copy()->subHours(self::HORAS_ANTES),
                    'Orden de servicio de '.($o->departamento?->nombre ?? 'tu departamento').': '.$o->reserva->titulo,
                    route('reservas.ordenes'), (int) $o->reserva->sede_id, ['departamento' => (int) $o->departamento_id, 'montaje' => $montaje]);
            })->values();
    }

    public function responsables(Espera $e, ?int $empresaId): Collection
    {
        return app(ResponsablesReservas::class)->delDepartamento($e->datos['departamento'], (int) $e->sedeId, (int) $empresaId);
    }

    /** Su renglón de siempre: «Órdenes de servicio por atender» (quien atiende el departamento). */
    public function leToca(User $actor, Espera $e): bool
    {
        return app(ResponsablesReservas::class)->atiendeDepartamento($actor, $e->datos['departamento'], (int) $e->sedeId, (int) $actor->empresa_id);
    }

    /**
     * D-01: la atiende quien puede marcarla «Listo» (la misma regla que la
     * pantalla de órdenes): quien atiende el departamento (o su delegado) o
     * quien configura Reservas en esa sede.
     */
    public function puedeAtender(User $actor, Espera $e): bool
    {
        return $this->leToca($actor, $e) || app(Destinatarios::class)->alcanza($actor, 'reservas.configurar', $e->sedeId);
    }

    public function renglonPropio(): ?string
    {
        return 'reservas_ordenes';
    }

    public function suplentes(Espera $e, ?int $empresaId, Collection $responsables, ?int $rolId): Collection
    {
        if ($rolId === null) {
            $ids = app(ResponsablesReservas::class)->generales((int) $empresaId);
            $generales = User::where('activo', true)->whereIn('id', $ids)->get()->reject(fn ($u) => $responsables->contains('id', $u->id))->values();
            if ($generales->isNotEmpty()) {
                return $generales;
            }
        }

        return parent::suplentes($e, $empresaId, $responsables, $rolId);
    }

    public function textoRecordatorio(Espera $e, int $minutos): array
    {
        return ['Mañana: '.$e->titulo, 'El montaje empieza el '.app(HoraLocal::class)->formatear($e->datos['montaje'], 'd/m \a \l\a\s H:i').' y la orden sigue «Por atender». Márcala lista cuando esté.'];
    }

    public function textoEscalado(Espera $e, int $minutos): array
    {
        return ['Ya empezó el montaje: '.$e->titulo, 'La orden de servicio sigue «Por atender». Llama al departamento o atiéndela.'];
    }
}
