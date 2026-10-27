<?php

namespace App\Services\Relojes\Relojes;

use App\Models\EquipoPc;
use App\Services\RecorridosPc\AjustesEquiposPc;
use App\Services\Relojes\Espera;
use App\Services\Relojes\Reloj;
use Illuminate\Support\Collection;

/**
 * Recarga de un equipo de Protección Civil (DD-17, D-08): 15 días antes de
 * la «Próxima recarga» se avisa a quien administra los equipos en la sede;
 * el día que vence, pasa a quien los administra en toda la empresa.
 */
class RecargasDeEquiposPc extends Reloj
{
    public function clave(): string
    {
        return 'recargas_pc';
    }

    public function nombre(): string
    {
        return 'Equipos de Protección Civil por recargar';
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
        return [1, AjustesEquiposPc::RECARGA_AVISO_DIAS * 1440];
    }

    public function dondeSeConfigura(): ?string
    {
        return 'Equipos de Protección Civil: «Próxima recarga» de cada equipo (15 días antes)';
    }

    public function renglonDef(): ?array
    {
        return ['recargas_pc', 'Equipos de Protección Civil por recargar', 'bi-fire', route('equipos_pc.index')];
    }

    public function textosInicio(): ?array
    {
        return ['1 equipo de Protección Civil por recargar', '%d equipos de Protección Civil por recargar', 'Su próxima recarga está cerca o ya pasó. Prográmala y, al recargarlo, cambia la fecha.', 'Ver equipos'];
    }

    public function esperas(?int $empresaId): Collection
    {
        return EquipoPc::where('activo', true)->whereNotNull('proxima_recarga')
            ->where('proxima_recarga', '<=', now()->addDays(AjustesEquiposPc::RECARGA_AVISO_DIAS)->toDateString())
            ->orderBy('proxima_recarga')->limit(500)->get()
            ->map(fn (EquipoPc $e) => new Espera('equipo_pc_recarga', (int) $e->id, $e->proxima_recarga->copy()->subDays(AjustesEquiposPc::RECARGA_AVISO_DIAS)->startOfDay(),
                'Recarga de '.$e->etiquetaCategoria().' '.$e->numero_serie.': '.$e->proxima_recarga->format('d/m/Y'), route('equipos_pc.index').'#equipopc-'.$e->id, (int) $e->sede_id))->values();
    }

    public function responsables(Espera $e, ?int $empresaId): Collection
    {
        return $this->conPermiso($empresaId, 'equipos_pc.editar', $e->sedeId);
    }
}
