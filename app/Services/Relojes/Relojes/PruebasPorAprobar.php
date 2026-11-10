<?php

namespace App\Services\Relojes\Relojes;

use App\Models\SolicitudCompra;
use App\Services\Relojes\Espera;
use Illuminate\Support\Collection;

/** Prueba gratis por aprobar (renglón 24; C-26). */
class PruebasPorAprobar extends RelojDePlataforma
{
    public function clave(): string
    {
        return 'plataforma_pruebas';
    }

    public function nombre(): string
    {
        return 'Pruebas por aprobar';
    }

    public function renglonDef(): ?array
    {
        return ['plataforma_pruebas', 'Pruebas por aprobar', 'bi-hourglass-split', route('catalogo-comercial.index', ['estado' => 'pruebas'])];
    }

    public function textosInicio(): ?array
    {
        return ['1 prueba gratis por aprobar', '%d pruebas gratis por aprobar', 'Un posible cliente pidió probar la plataforma. Apruébala o contáctalo.', 'Revisar'];
    }

    public function esperas(?int $empresaId): Collection
    {
        return SolicitudCompra::query()->withoutGlobalScopes()->where('tipo', 'prueba')->whereNull('activada_en')->whereIn('estado', ['nueva', 'contactada', 'cotizada'])
            ->orderBy('created_at')->limit(500)->get()
            ->map(fn (SolicitudCompra $s) => new Espera('solicitud_compra', (int) $s->id, $s->created_at ?? now(), 'Prueba por aprobar: '.($s->empresa ?: $s->nombre),
                route('catalogo-comercial.solicitudes.show', $s->id)))->values();
    }
}
