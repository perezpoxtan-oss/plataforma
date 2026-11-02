<?php

namespace App\Services\Relojes\Relojes;

use App\Models\Suscripcion;
use App\Services\Relojes\Espera;
use App\Services\Relojes\Reloj;
use Illuminate\Support\Collection;

/**
 * Suscripción por vencer o en gracia, del lado del cliente (renglón 25): ya
 * no solo la franja ámbar o roja, también un renglón en Mis pendientes para
 * quien administra la empresa. Su suplente es la plataforma (Clientes en
 * gracia); aquí no se escala para no avisar dos veces.
 */
class SuscripcionDelCliente extends Reloj
{
    public function clave(): string
    {
        return 'suscripcion_cliente';
    }

    public function nombre(): string
    {
        return 'Suscripción por vencer o en gracia';
    }

    public function suplenteOmision(): string
    {
        return 'el Super Administrador (renglón «Clientes en gracia»)';
    }

    public function permiso(): ?string
    {
        return 'empresas.editar';
    }

    public function recuerda(): bool
    {
        return false;
    }

    public function umbralesPropios($empresa): ?array
    {
        return [1, PHP_INT_MAX >> 8]; // ámbar desde que entra en aviso; el suplente lo pone la plataforma
    }

    public function renglonDef(): ?array
    {
        return ['suscripcion', 'Tu suscripción', 'bi-calendar2-check', route('empresas.index').'#titulo-tu-suscripcion'];
    }

    public function textosInicio(): ?array
    {
        return ['Tu suscripción está por vencer o en gracia', 'Tu suscripción está por vencer o en gracia', 'Renuévala desde Empresas → Tu suscripción para no pasar a solo consulta.', 'Renovar'];
    }

    public function esperas(?int $empresaId): Collection
    {
        $s = Suscripcion::where('empresa_id', $empresaId)->where('estado', '!=', Suscripcion::CANCELADA)->orderByDesc('id')->first();
        if ($s === null || ! in_array($estado = $s->estadoEfectivo(), [Suscripcion::POR_VENCER, Suscripcion::EN_GRACIA], true)) {
            return collect();
        }
        $desde = $estado === Suscripcion::EN_GRACIA ? $s->vence_en->copy()->addDay()->startOfDay() : now()->subMinutes(1);

        return collect([new Espera('suscripcion', (int) $s->id, $desde, 'Tu suscripción: '.mb_strtolower($s->estadoTexto($estado)), route('empresas.index').'#titulo-tu-suscripcion')]);
    }

    public function responsables(Espera $e, ?int $empresaId): Collection
    {
        return $this->conPermiso($empresaId, 'empresas.editar', null);
    }

    public function suplentes(Espera $e, ?int $empresaId, Collection $responsables, ?int $rolId): Collection
    {
        return collect();
    }
}
