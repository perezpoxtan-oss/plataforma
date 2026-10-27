<?php

namespace App\Services\Relojes\Relojes;

use App\Models\Postulacion;
use App\Services\Relojes\Espera;
use Illuminate\Support\Collection;

/** Elegido por contratar: documentos y contrato (renglón 6; C-23). */
class ElegidosPorContratar extends RelojDeCandidatos
{
    protected function plazo(): string
    {
        return 'documentos_dias';
    }

    public function clave(): string
    {
        return 'candidatos_elegidos';
    }

    public function nombre(): string
    {
        return 'Elegidos por contratar';
    }

    public function esperas(?int $empresaId): Collection
    {
        return Postulacion::with('candidato:id,nombre_completo')->where('etapa', 'elegido')->orderBy('id')->limit(500)
            ->get(['id', 'candidato_id', 'sede_id', 'elegido_en', 'updated_at'])
            ->filter(fn (Postulacion $p) => $p->candidato !== null)
            ->map(fn (Postulacion $p) => new Espera('candidato_elegido', (int) $p->candidato_id, $p->elegido_en ?? $p->updated_at ?? now(),
                'Elegido sin contratar: '.$p->candidato->nombre_completo, route('candidatos.show', $p->candidato_id), $p->sede_id !== null ? (int) $p->sede_id : null))
            ->unique(fn (Espera $e) => $e->llave())->values();
    }

    /** D-01: el renglón de Mis pendientes que ya existía y lo cuenta. */
    public function renglonPropio(): ?string
    {
        return 'elegidos';
    }
}
