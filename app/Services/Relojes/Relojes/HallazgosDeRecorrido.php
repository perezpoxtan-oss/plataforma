<?php

namespace App\Services\Relojes\Relojes;

use App\Models\Novedad;
use App\Models\RecorridoPc;
use App\Services\Relojes\Espera;
use App\Services\Relojes\Reloj;
use Illuminate\Support\Collection;

/**
 * Hallazgo de un Recorrido de Protección Civil sin dueño (C-24, DD-17): el
 * ticket que abrió la falla y nadie ha tomado. Se recuerda a quien administra
 * los equipos de Protección Civil en la sede; pasa al de toda la empresa.
 */
class HallazgosDeRecorrido extends Reloj
{
    public function clave(): string
    {
        return 'hallazgos_recorrido';
    }

    public function nombre(): string
    {
        return 'Hallazgos de recorrido sin atender';
    }

    public function suplenteOmision(): string
    {
        return 'quien administra los equipos de Protección Civil en toda la empresa (o el administrador)';
    }

    public function modulos(): array
    {
        return ['recorridos_pc'];
    }

    public function permiso(): ?string
    {
        return 'equipos_pc.editar';
    }

    public function renglonDef(): ?array
    {
        return ['hallazgos_recorrido', 'Hallazgos de recorrido sin atender', 'bi-fire', route('novedades.index')];
    }

    public function textosInicio(): ?array
    {
        return ['1 hallazgo de recorrido sin atender', '%d hallazgos de recorrido sin atender', 'Un recorrido de Protección Civil encontró una falla y nadie ha tomado el ticket. Ábrelo y canalízalo o atiéndelo.', 'Atender'];
    }

    public function esperas(?int $empresaId): Collection
    {
        $tickets = RecorridoPc::whereNotNull('novedad_id')->pluck('novedad_id');

        return $tickets->isEmpty() ? collect() : Novedad::whereIn('id', $tickets)->whereNull('asignado_a')->where('estatus', '!=', Novedad::RESUELTO)
            ->orderBy('id')->limit(500)->get()
            ->map(fn (Novedad $n) => new Espera('novedad_hallazgo', (int) $n->id, $n->created_at ?? now(), 'Hallazgo sin atender: ticket '.$n->folio(),
                route('novedades.index', ['abrir' => $n->id]), (int) $n->sede_id))->values();
    }

    public function responsables(Espera $e, ?int $empresaId): Collection
    {
        return $this->conPermiso($empresaId, 'equipos_pc.editar', $e->sedeId);
    }
}
