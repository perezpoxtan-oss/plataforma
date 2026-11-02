<?php

namespace App\Services\Relojes\Relojes;

use App\Models\Postulacion;
use App\Services\Candidatos\PlazosCandidatos;
use App\Services\Relojes\Espera;
use Illuminate\Support\Collection;

/** Candidato «Por atender» o «Con RR. HH.» de cualquier día (renglón 4; C-23, E-07). */
class CandidatosSinAtender extends RelojDeCandidatos
{
    protected function plazo(): string
    {
        return 'por_atender_dias';
    }

    public function clave(): string
    {
        return 'candidatos_sin_atender';
    }

    public function nombre(): string
    {
        return 'Candidatos sin atender';
    }

    public function esperas(?int $empresaId): Collection
    {
        // La solicitud del kiosco sin revisar ya tiene su reloj (SolicitudesSinRevisar): no se avisa dos veces
        return Postulacion::with('candidato:id,nombre_completo')->whereIn('etapa', PlazosCandidatos::ETAPAS_RH)->whereNull('archivada_en')
            ->whereHas('candidato', fn ($c) => $c->where('autocaptura_pendiente', false))
            ->orderBy('id')->limit(500)->get(['id', 'candidato_id', 'sede_id', 'etapa', 'updated_at'])
            ->filter(fn (Postulacion $p) => $p->candidato !== null)
            ->map(fn (Postulacion $p) => new Espera('candidato', (int) $p->candidato_id, $p->updated_at ?? now(),
                ($p->etapa === 'registrado' ? 'Sin atender: ' : 'Sin canalizar: ').$p->candidato->nombre_completo,
                route('candidatos.show', $p->candidato_id), $p->sede_id !== null ? (int) $p->sede_id : null))->unique(fn (Espera $e) => $e->llave())->values();
    }

    /** D-01: el renglón de Mis pendientes que ya existía y lo cuenta. */
    public function renglonPropio(): ?string
    {
        return 'sin_atender';
    }
}
