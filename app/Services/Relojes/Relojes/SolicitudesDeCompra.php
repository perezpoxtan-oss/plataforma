<?php

namespace App\Services\Relojes\Relojes;

use App\Models\SolicitudCompra;
use App\Services\Relojes\Espera;
use Illuminate\Support\Collection;

/** Solicitud de compra nueva desde la página de planes (renglón 24; C-26). */
class SolicitudesDeCompra extends RelojDePlataforma
{
    public function clave(): string
    {
        return 'plataforma_solicitudes';
    }

    public function nombre(): string
    {
        return 'Solicitudes por atender';
    }

    public function renglonDef(): ?array
    {
        return ['plataforma_solicitudes', 'Solicitudes por atender', 'bi-cart-check', route('catalogo-comercial.index', ['estado' => 'nueva'])];
    }

    public function textosInicio(): ?array
    {
        return ['1 solicitud de compra por atender', '%d solicitudes de compra por atender', 'Llegaron desde la página de planes. Contacta al cliente, cotiza o actívalo.', 'Atender'];
    }

    public function esperas(?int $empresaId): Collection
    {
        return SolicitudCompra::query()->withoutGlobalScopes()->where('estado', 'nueva')->where(fn ($q) => $q->whereNull('tipo')->orWhere('tipo', '!=', 'prueba'))
            ->orderBy('created_at')->limit(500)->get()
            ->map(fn (SolicitudCompra $s) => new Espera('solicitud_compra', (int) $s->id, $s->created_at ?? now(), 'Solicitud '.$s->folio.' de '.($s->empresa ?: $s->nombre),
                route('catalogo-comercial.solicitudes.show', $s->id)))->values();
    }
}
