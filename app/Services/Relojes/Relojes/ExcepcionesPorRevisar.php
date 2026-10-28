<?php

namespace App\Services\Relojes\Relojes;

use App\Models\Acceso;
use App\Services\Relojes\Espera;
use App\Services\Relojes\Reloj;
use Illuminate\Support\Collection;

/**
 * Lo que el agente aprobó por excepción (C-25, DD-13) queda marcado para
 * que el supervisor lo revise («Atender»). Si nadie lo revisa, pasa al jefe.
 */
class ExcepcionesPorRevisar extends Reloj
{
    public const REFERENCIA = 'acceso_excepcion';

    public function clave(): string
    {
        return 'excepciones_caseta';
    }

    public function nombre(): string
    {
        return 'Excepciones de la caseta por revisar';
    }

    public function suplenteOmision(): string
    {
        return 'quien autoriza accesos en toda la empresa (o el administrador)';
    }

    public function modulos(): array
    {
        return ['accesos'];
    }

    public function permiso(): ?string
    {
        return 'accesos.aprobar';
    }

    public function renglonDef(): ?array
    {
        return ['excepciones_caseta', 'Excepciones de la caseta por revisar', 'bi-shield-exclamation', route('accesos.index', ['excepciones' => 1])];
    }

    public function textosInicio(): ?array
    {
        return ['1 aprobación por excepción del agente por revisar', '%d aprobaciones por excepción del agente por revisar',
            'Nadie respondió a tiempo y el agente dejó pasar con motivo y foto. Revisa el caso y toca «Atender».', 'Revisar'];
    }

    public function esperas(?int $empresaId): Collection
    {
        return Acceso::where('excepcion_por_revisar', true)->whereNull('excepcion_revisada_en')->orderBy('autorizado_at')->limit(500)
            ->get(['id', 'sede_id', 'nombre', 'autorizado_at', 'entrada_at'])
            ->map(fn (Acceso $a) => new Espera(self::REFERENCIA, (int) $a->id, $a->autorizado_at ?? $a->entrada_at ?? now(), 'Excepción por revisar: '.$a->nombre,
                route('accesos.index', ['excepciones' => 1]).'#acceso-'.$a->id, (int) $a->sede_id))->values();
    }

    public function responsables(Espera $e, ?int $empresaId): Collection
    {
        return $this->conPermiso($empresaId, 'accesos.aprobar', $e->sedeId);
    }
}
