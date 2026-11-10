<?php

namespace App\Services\Relojes\Relojes;

use App\Models\Vacante;
use App\Services\Relojes\Espera;
use App\Services\Relojes\Reloj;
use App\Services\Vacantes\AdministradorVacantes;
use Illuminate\Support\Collection;

/**
 * Vacante propuesta por un jefe (renglón 7; C-23, E-06): espera que Recursos
 * Humanos la publique, la cierre o la elimine. Quien la pidió se entera del
 * resultado (vacante_resultado, en AdministradorVacantes).
 */
class VacantesPropuestas extends Reloj
{
    public function clave(): string
    {
        return 'vacantes_propuestas';
    }

    public function nombre(): string
    {
        return 'Vacantes propuestas por publicar';
    }

    public function suplenteOmision(): string
    {
        return 'quien publica vacantes en toda la empresa (o el administrador)';
    }

    public function modulos(): array
    {
        return ['vacantes'];
    }

    public function permiso(): ?string
    {
        return 'vacantes.editar';
    }

    public function renglonDef(): ?array
    {
        return ['vacantes_propuestas', 'Vacantes propuestas por publicar', 'bi-megaphone', route('vacantes.index', ['estado' => 'borrador'])];
    }

    public function textosInicio(): ?array
    {
        return ['1 vacante propuesta espera que la publiques', '%d vacantes propuestas esperan que las publiques',
            'Un jefe pidió la vacante. Revísala y publícala, o ciérrala con su motivo: quien la pidió se entera.', 'Revisar'];
    }

    public function esperas(?int $empresaId): Collection
    {
        return Vacante::with('sedes:sedes.id')->where('estado', 'borrador')->whereNotNull('propuesta_en')->orderBy('propuesta_en')->limit(500)->get()
            ->map(fn (Vacante $v) => new Espera(AdministradorVacantes::REFERENCIA, (int) $v->id, $v->propuesta_en, 'Vacante propuesta: «'.$v->titulo.'»',
                route('vacantes.index', ['estado' => 'borrador']), ! $v->todas_las_sedes && $v->sedes->count() === 1 ? (int) $v->sedes->first()->id : null))->values();
    }

    public function responsables(Espera $e, ?int $empresaId): Collection
    {
        return $this->conPermiso($empresaId, 'vacantes.editar', $e->sedeId);
    }
}
