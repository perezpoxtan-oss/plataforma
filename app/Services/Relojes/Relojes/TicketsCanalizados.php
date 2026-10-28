<?php

namespace App\Services\Relojes\Relojes;

use App\Models\Novedad;
use App\Models\User;
use App\Services\Novedades\AjustesNovedades;
use App\Services\Recepcion\Destinatarios;
use App\Services\Relojes\Espera;
use App\Services\Relojes\MotorRelojes;
use App\Services\Relojes\Reloj;
use Illuminate\Support\Collection;

/**
 * Ticket canalizado a una persona (renglón 11; C-22, C-24 D-04). Reloj por
 * categoría (Configuración de la bitácora: Accidente 2 h, Robo 4 h, las
 * demás 24 h): a esas horas se recuerda a la persona canalizada y al triple
 * pasa al jefe de seguridad de la sede (quien puede reabrir casos). Los
 * «Pendiente de turno» tienen su propio reloj (TurnosNovedades).
 */
class TicketsCanalizados extends Reloj
{
    public function clave(): string
    {
        return 'novedades_canalizadas';
    }

    public function nombre(): string
    {
        return 'Tickets canalizados';
    }

    public function suplenteOmision(): string
    {
        return 'el jefe de seguridad de la sede (quien puede reabrir casos)';
    }

    public function modulos(): array
    {
        return ['novedades'];
    }

    public function permiso(): ?string
    {
        return 'novedades.reabrir';
    }

    public function dondeSeConfigura(): ?string
    {
        return 'Configuración → Bitácora: reloj por categoría';
    }

    public function umbralesPropios($empresa): ?array
    {
        $horas = app(AjustesNovedades::class)->relojHoras($empresa)['otras'];

        return [$horas * 60, $horas * 180];
    }

    public function umbralesDe(Espera $e, array $base): array
    {
        if (! isset($e->datos['horas'])) {
            return $base;
        }

        return [$e->datos['horas'] * 60, $e->datos['horas'] * 180];
    }

    public function esperas(?int $empresaId): Collection
    {
        $horas = app(AjustesNovedades::class)->relojHoras(app(MotorRelojes::class)->empresa($empresaId));

        return Novedad::whereNotNull('asignado_a')->whereNotIn('estatus', [Novedad::RESUELTO, Novedad::PENDIENTE_TURNO])
            ->orderBy('id')->limit(500)->get()
            ->map(fn (Novedad $n) => new Espera('novedad_canalizada', (int) $n->id, $n->canalizado_en ?? $n->updated_at ?? now(),
                'Ticket '.$n->folio().' · '.$n->etiquetaCategoria(), route('novedades.index', ['abrir' => $n->id]), (int) $n->sede_id,
                ['asignado' => (int) $n->asignado_a, 'horas' => $horas[$n->categoria] ?? $horas['otras']]))->values();
    }

    public function responsables(Espera $e, ?int $empresaId): Collection
    {
        return User::where('activo', true)->whereKey($e->datos['asignado'])->get();
    }

    /** El jefe de la sede (o quien reabre en toda la empresa). */
    public function suplentes(Espera $e, ?int $empresaId, Collection $responsables, ?int $rolId): Collection
    {
        if ($rolId === null) {
            $jefes = $this->conPermiso($empresaId, 'novedades.reabrir', $e->sedeId)->reject(fn (User $u) => (int) $u->id === $e->datos['asignado'])->values();
            if ($jefes->isNotEmpty()) {
                return $jefes;
            }
        }

        return parent::suplentes($e, $empresaId, $responsables, $rolId);
    }

    /** A la persona canalizada (Mis pendientes «Tickets canalizados a mí»). */
    public function leToca(User $actor, Espera $e): bool
    {
        return (int) $actor->id === $e->datos['asignado'];
    }

    public function puedeVer(User $actor): bool
    {
        return $actor->can('novedades.editar');
    }

    /** D-01: el renglón de Mis pendientes que ya existía y lo cuenta. */
    public function renglonPropio(): ?string
    {
        return 'novedades_mias';
    }

    /**
     * D-01: lo atiende quien está canalizado o quien puede reasignar el ticket
     * en su sede (editar la novedad: canalizarlo a otra persona o cerrarlo).
     */
    public function puedeAtender(User $actor, Espera $e): bool
    {
        return (int) $actor->id === $e->datos['asignado'] || app(Destinatarios::class)->alcanza($actor, 'novedades.editar', $e->sedeId);
    }
}
