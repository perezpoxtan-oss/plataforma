<?php

namespace App\Services\Relojes\Relojes;

use App\Models\Novedad;
use App\Models\User;
use App\Services\Autorizaciones\Autorizaciones;
use App\Services\Novedades\AjustesNovedades;
use App\Services\Novedades\CanalizarDepartamento;
use App\Services\Relojes\Espera;
use App\Services\Relojes\MotorRelojes;
use App\Services\Relojes\Reloj;
use Illuminate\Support\Collection;

/**
 * C-28 (DD-15): ticket canalizado a un departamento que todavía no atiende su
 * parte. Usa las horas por categoría de los tickets canalizados (Accidente
 * 2 h, Robo 4 h, las demás 24 h): a esas horas se recuerda a quien responde
 * por el departamento en la sede (o su delegado) y al triple pasa al jefe de
 * seguridad de la sede (quien puede reabrir casos).
 */
class TicketsDeDepartamento extends Reloj
{
    /** @var array<int, list<array{0: int, 1: ?int}>> departamentos que atiende cada persona (una consulta por persona) */
    private array $pares = [];

    public function clave(): string
    {
        return 'novedades_departamento';
    }

    public function nombre(): string
    {
        return 'Tickets canalizados a un departamento';
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
        return 'autorizaciones.responder';
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
        return isset($e->datos['horas']) ? [$e->datos['horas'] * 60, $e->datos['horas'] * 180] : $base;
    }

    public function renglonDef(): ?array
    {
        return ['novedades_departamento', 'Tickets de tu departamento', 'bi-diagram-2', route('novedades.departamento.index')];
    }

    public function textosInicio(): ?array
    {
        return ['1 ticket canalizado a tu departamento', '%d tickets canalizados a tu departamento', 'Seguridad te canalizó un incidente. Ábrelo, agrega tus notas y toca Atender cuando hagas tu parte.', 'Atender'];
    }

    public function esperas(?int $empresaId): Collection
    {
        $horas = app(AjustesNovedades::class)->relojHoras(app(MotorRelojes::class)->empresa($empresaId));

        return Novedad::whereNotNull('departamento_id')->whereNull('departamento_atendido_en')->where('estatus', '!=', Novedad::RESUELTO)
            ->orderBy('id')->limit(500)->get()
            ->map(fn (Novedad $n) => new Espera('novedad_departamento', (int) $n->id, $n->departamento_en ?? $n->updated_at ?? now(),
                'Ticket '.$n->folio().' · '.$n->etiquetaCategoria(), route('novedades.departamento.show', $n->id), (int) $n->sede_id,
                ['departamento' => (int) $n->departamento_id, 'horas' => $horas[$n->categoria] ?? $horas['otras']]))->values();
    }

    public function responsables(Espera $e, ?int $empresaId): Collection
    {
        return app(Autorizaciones::class)->aQuienAvisar((int) $e->datos['departamento'], (int) $e->sedeId);
    }

    /** El jefe de la sede (o quien reabre en toda la empresa). */
    public function suplentes(Espera $e, ?int $empresaId, Collection $responsables, ?int $rolId): Collection
    {
        if ($rolId === null) {
            $ya = $responsables->pluck('id')->map(fn ($id) => (int) $id)->all();
            $jefes = $this->conPermiso($empresaId, 'novedades.reabrir', $e->sedeId)->reject(fn (User $u) => in_array((int) $u->id, $ya, true))->values();
            if ($jefes->isNotEmpty()) {
                return $jefes;
            }
        }

        return parent::suplentes($e, $empresaId, $responsables, $rolId);
    }

    /** A quien responde por el departamento en esa sede (o su delegado). */
    public function leToca(User $actor, Espera $e): bool
    {
        $this->pares[(int) $actor->id] ??= app(CanalizarDepartamento::class)->pares($actor);

        return collect($this->pares[(int) $actor->id])
            ->contains(fn ($p) => (int) $p[0] === (int) $e->datos['departamento'] && ($p[1] === null || (int) $p[1] === (int) $e->sedeId));
    }
}
