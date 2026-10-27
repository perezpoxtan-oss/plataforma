<?php

namespace App\Services\Relojes\Relojes;

use App\Models\EquipoPc;
use App\Models\RevisionRecorridoPc;
use App\Services\RecorridosPc\AjustesEquiposPc;
use App\Services\Relojes\Espera;
use App\Services\Relojes\MotorRelojes;
use App\Services\Relojes\Reloj;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Frecuencia de revisión de los equipos de Protección Civil (DD-17, D-08):
 * cuando un equipo pasa los días de su tipo sin aparecer en un recorrido (de
 * fábrica, extintores cada 30 días), se avisa a quien administra los equipos
 * en la sede; una semana después pasa a quien los administra en toda la empresa.
 */
class RevisionesDeEquiposPc extends Reloj
{
    public function clave(): string
    {
        return 'revisiones_pc';
    }

    public function nombre(): string
    {
        return 'Equipos de Protección Civil sin revisar a tiempo';
    }

    public function suplenteOmision(): string
    {
        return 'quien administra los equipos de Protección Civil en toda la empresa (o el administrador)';
    }

    public function modulos(): array
    {
        return ['equipos_pc'];
    }

    public function permiso(): ?string
    {
        return 'equipos_pc.editar';
    }

    public function umbralesPropios($empresa): ?array
    {
        return [1, 7 * 1440];
    }

    public function dondeSeConfigura(): ?string
    {
        return 'Configuración → (bitácora): frecuencia de revisión por tipo';
    }

    public function renglonDef(): ?array
    {
        return ['revisiones_pc', 'Equipos de Protección Civil sin revisar a tiempo', 'bi-clipboard-check', route('equipos_pc.index')];
    }

    public function textosInicio(): ?array
    {
        return ['1 equipo de Protección Civil sin revisar a tiempo', '%d equipos de Protección Civil sin revisar a tiempo', 'Pasaron los días de su revisión. Inclúyelos en el siguiente recorrido.', 'Ver equipos'];
    }

    public function esperas(?int $empresaId): Collection
    {
        $frecuencias = array_filter(app(AjustesEquiposPc::class)->frecuencias(app(MotorRelojes::class)->empresa($empresaId)));
        if ($frecuencias === []) {
            return collect();
        }
        $equipos = EquipoPc::where('activo', true)->whereIn('categoria', array_keys($frecuencias))->orderBy('id')->limit(2000)->get();
        $ultimas = RevisionRecorridoPc::whereIn('equipo_pc_id', $equipos->pluck('id'))->groupBy('equipo_pc_id')
            ->selectRaw('equipo_pc_id, MAX(created_at) as ultima')->pluck('ultima', 'equipo_pc_id');
        $lista = collect();
        foreach ($equipos as $e) {
            $ultima = isset($ultimas[$e->id]) ? Carbon::parse($ultimas[$e->id], 'UTC') : $e->created_at;
            $vence = $ultima?->copy()->addDays($frecuencias[$e->categoria]);
            if ($vence !== null && $vence->lte(now())) {
                $lista->push(new Espera('equipo_pc_revision', (int) $e->id, $vence, 'Sin revisar a tiempo: '.$e->etiquetaCategoria().' '.$e->numero_serie,
                    route('equipos_pc.index').'#equipopc-'.$e->id, (int) $e->sede_id));
            }
        }

        return $lista;
    }

    public function responsables(Espera $e, ?int $empresaId): Collection
    {
        return $this->conPermiso($empresaId, 'equipos_pc.editar', $e->sedeId);
    }
}
