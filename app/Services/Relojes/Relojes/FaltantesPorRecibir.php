<?php

namespace App\Services\Relojes\Relojes;

use App\Models\EquipoResponsiva;
use App\Services\Relojes\Espera;
use App\Services\Relojes\Reloj;
use App\Services\Responsivas\AdministradorResponsivas;
use Illuminate\Support\Collection;

/**
 * Equipo faltante por recibir (C-24, GC-09): el guardia lo reportó y no
 * puede darlo de baja; espera a quien da de baja equipos en la sede.
 */
class FaltantesPorRecibir extends Reloj
{
    public function clave(): string
    {
        return 'faltantes_responsivas';
    }

    public function nombre(): string
    {
        return 'Equipos faltantes por recibir';
    }

    public function suplenteOmision(): string
    {
        return 'quien da de baja equipos en toda la empresa (o el administrador)';
    }

    public function modulos(): array
    {
        return ['responsivas'];
    }

    public function permiso(): ?string
    {
        return 'equipos.eliminar';
    }

    public function renglonDef(): ?array
    {
        return ['faltantes_responsivas', 'Equipos faltantes por recibir', 'bi-question-octagon', route('responsivas.index')];
    }

    public function textosInicio(): ?array
    {
        return ['1 equipo faltante espera que lo recibas', '%d equipos faltantes esperan que los recibas',
            'La caseta lo reportó faltante y no puede darlo de baja. Recíbelo como faltante (genera su voucher).', 'Recibir'];
    }

    public function esperas(?int $empresaId): Collection
    {
        return EquipoResponsiva::with('responsiva:id,empresa_id,sede_id,folio')->whereNotNull('faltante_reportado_en')->whereNull('devuelto_en')
            ->whereHas('responsiva')->orderBy('faltante_reportado_en')->limit(500)->get()
            ->map(fn (EquipoResponsiva $f) => new Espera(AdministradorResponsivas::REFERENCIA_FALTANTE, (int) $f->id, $f->faltante_reportado_en,
                'Faltante por recibir: responsiva '.$f->responsiva->folio, route('responsivas.index').'#responsiva-'.$f->responsiva_id, (int) $f->responsiva->sede_id))->values();
    }

    public function responsables(Espera $e, ?int $empresaId): Collection
    {
        return $this->conPermiso($empresaId, 'equipos.eliminar', $e->sedeId);
    }
}
