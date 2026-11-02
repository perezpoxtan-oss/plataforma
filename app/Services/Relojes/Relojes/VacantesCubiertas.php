<?php

namespace App\Services\Relojes\Relojes;

use App\Models\Vacante;
use App\Services\Relojes\Espera;
use App\Services\Vacantes\AdministradorVacantes;
use Illuminate\Support\Collection;

/** Vacante cubierta por cerrar (renglón 8; C-23, E-06). */
class VacantesCubiertas extends VacantesPropuestas
{
    public function clave(): string
    {
        return 'vacantes_cubiertas';
    }

    public function nombre(): string
    {
        return 'Vacantes cubiertas por cerrar';
    }

    public function renglonDef(): ?array
    {
        return ['vacantes_cubiertas', 'Vacantes cubiertas por cerrar', 'bi-check2-square', route('vacantes.index')];
    }

    public function textosInicio(): ?array
    {
        return ['1 vacante cubierta espera que la cierres', '%d vacantes cubiertas esperan que las cierres',
            'Ya se eligió a quien cubre las plazas. Si ya no buscas a nadie más, ciérrala como cubierta.', 'Revisar'];
    }

    public function esperas(?int $empresaId): Collection
    {
        return Vacante::with('sedes:sedes.id')->whereIn('estado', ['publicada', 'pausada'])->whereNotNull('cubierta_en')->orderBy('cubierta_en')->limit(500)->get()
            ->map(fn (Vacante $v) => new Espera(AdministradorVacantes::REFERENCIA, (int) $v->id, $v->cubierta_en, 'Vacante cubierta por cerrar: «'.$v->titulo.'»',
                route('vacantes.candidatos', $v->id), ! $v->todas_las_sedes && $v->sedes->count() === 1 ? (int) $v->sedes->first()->id : null))->values();
    }
}
