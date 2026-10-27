<?php

namespace App\Services\Relojes\Relojes;

use App\Models\Candidato;
use App\Services\Relojes\Espera;
use Illuminate\Support\Collection;

/** Solicitud llenada en el kiosco o por internet sin revisar (renglón 3; C-23). */
class SolicitudesSinRevisar extends RelojDeCandidatos
{
    protected function plazo(): string
    {
        return 'solicitud_dias';
    }

    public function clave(): string
    {
        return 'candidatos_solicitudes';
    }

    public function nombre(): string
    {
        return 'Solicitudes de empleo sin revisar';
    }

    public function esperas(?int $empresaId): Collection
    {
        return Candidato::where('autocaptura_pendiente', true)->whereNull('archivada_en')->orderBy('id')->limit(500)
            ->get(['id', 'sede_id', 'nombre_completo', 'autocaptura_en', 'updated_at'])
            ->map(fn (Candidato $c) => new Espera('candidato', (int) $c->id, $c->autocaptura_en ?? $c->updated_at ?? now(), 'Solicitud sin revisar: '.$c->nombre_completo,
                route('candidatos.show', $c->id), $c->sede_id !== null ? (int) $c->sede_id : null))->values();
    }
}
