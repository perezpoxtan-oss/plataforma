<?php

namespace App\Services\Relojes\Relojes;

use App\Models\Suscripcion;
use App\Services\Relojes\Espera;
use Illuminate\Support\Collection;

/**
 * Clientes en gracia (renglón 25; C-26): la suscripción venció y el cliente
 * aún puede operar unos días. Al Super Administrador le espera el cobro.
 * Al cliente le llega su propio renglón (SuscripcionDelCliente).
 */
class SuscripcionEnGracia extends RelojDePlataforma
{
    public function clave(): string
    {
        return 'plataforma_gracia';
    }

    public function nombre(): string
    {
        return 'Clientes en gracia';
    }

    public function recuerda(): bool
    {
        return false; // la revisión diaria de suscripciones ya avisa al vencer
    }

    public function renglonDef(): ?array
    {
        return ['plataforma_gracia', 'Clientes en gracia', 'bi-calendar2-x', route('catalogo-comercial.index', ['pestana' => 'clientes'])];
    }

    public function textosInicio(): ?array
    {
        return ['1 cliente en gracia', '%d clientes en gracia', 'Su suscripción venció y siguen operando unos días. Registra su pago o contáctalos antes de que pasen a solo consulta.', 'Ver clientes'];
    }

    public function esperas(?int $empresaId): Collection
    {
        $hoy = Suscripcion::hoy();

        return Suscripcion::query()->withoutGlobalScopes()->with(['empresa' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'nombre_comercial')])
            ->where('estado', '!=', Suscripcion::CANCELADA)->where('vence_en', '<', $hoy)->limit(500)->get()
            ->filter(fn (Suscripcion $s) => $s->estadoEfectivo($hoy) === Suscripcion::EN_GRACIA)
            ->map(fn (Suscripcion $s) => new Espera('suscripcion', (int) $s->id, $s->vence_en->copy()->addDay()->startOfDay(), 'Cliente en gracia: '.($s->empresa?->nombre_comercial ?? '—'),
                route('catalogo-comercial.clientes.show', $s->id)))->values();
    }
}
