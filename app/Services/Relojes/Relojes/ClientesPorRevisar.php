<?php

namespace App\Services\Relojes\Relojes;

use App\Models\Suscripcion;
use App\Services\Relojes\Espera;
use Illuminate\Support\Collection;

/** Cliente nuevo que se activó al pagar y espera revisión (renglón 24; C-26). */
class ClientesPorRevisar extends RelojDePlataforma
{
    public function clave(): string
    {
        return 'plataforma_clientes';
    }

    public function nombre(): string
    {
        return 'Clientes por revisar';
    }

    public function renglonDef(): ?array
    {
        return ['plataforma_clientes', 'Clientes por revisar', 'bi-person-check', route('catalogo-comercial.index', ['pestana' => 'revisar'])];
    }

    public function textosInicio(): ?array
    {
        return ['1 cliente nuevo por revisar', '%d clientes nuevos por revisar', 'Se activaron solos al pagar. Revisa sus datos, su plan y su giro.', 'Revisar'];
    }

    public function esperas(?int $empresaId): Collection
    {
        return Suscripcion::query()->withoutGlobalScopes()->with(['empresa' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'nombre_comercial')])
            ->whereNull('revisado_en')->where('estado', '!=', Suscripcion::CANCELADA)->orderBy('created_at')->limit(500)->get()
            ->map(fn (Suscripcion $s) => new Espera('suscripcion_revision', (int) $s->id, $s->created_at ?? now(), 'Cliente por revisar: '.($s->empresa?->nombre_comercial ?? '—'),
                route('catalogo-comercial.clientes.show', $s->id)))->values();
    }
}
