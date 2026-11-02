<?php

namespace App\Services\Relojes\Relojes;

use App\Models\LostFoundArticulo;
use App\Models\LostFoundUmbral;
use App\Models\User;
use App\Services\Relojes\AjustesRelojes;
use App\Services\Relojes\Espera;
use App\Services\Relojes\Reloj;
use Illuminate\Support\Collection;

/**
 * Lost & Found vencido (renglón 21; C-24, D-08): objetos que ya cumplieron
 * sus días de resguardo y esperan una decisión (entregar, donar, desechar).
 * Un aviso por sede, que se repite cada semana mientras haya vencidos, a
 * quien firma Lost & Found en la sede; pasa a quien lo configura.
 */
class LostFoundVencidos extends Reloj
{
    public function clave(): string
    {
        return 'lost_found';
    }

    public function nombre(): string
    {
        return 'Lost & Found vencidos';
    }

    public function suplenteOmision(): string
    {
        return 'quien configura Lost & Found en toda la empresa (o el administrador)';
    }

    public function modulos(): array
    {
        return ['lost_found'];
    }

    public function permiso(): ?string
    {
        return 'lost_found.firmar';
    }

    public function repetirRecordatorioHoras(): ?int
    {
        return 168;
    }

    public function umbralesPropios($empresa): ?array
    {
        return [1, app(AjustesRelojes::class)->generales($empresa)['suplente'] * 60];
    }

    public function renglonDef(): ?array
    {
        return ['lost_found', 'Lost & Found vencidos', 'bi-box-seam', route('lost_found.archivo', ['filtro' => 'urgentes'])];
    }

    public function textosInicio(): ?array
    {
        return ['Lost & Found: 1 sede con objetos vencidos', 'Lost & Found: %d sedes con objetos vencidos', 'Ya cumplieron sus días de resguardo. Decide qué hacer con ellos (entregar, donar o desechar) según tu política.', 'Ver vencidos'];
    }

    public function esperas(?int $empresaId): Collection
    {
        $umbrales = LostFoundUmbral::vigentes();
        $porSede = [];
        foreach (LostFoundArticulo::where('estatus', LostFoundArticulo::EN_RESGUARDO)->orderBy('created_at')->limit(2000)->get(['id', 'sede_id', 'tipo_valor', 'created_at']) as $a) {
            $vence = $a->created_at?->copy()->addDays($umbrales[$a->tipo_valor] ?? LostFoundUmbral::POR_OMISION['OTRO']);
            if ($vence === null || $vence->gt(now())) {
                continue;
            }
            $sede = (int) $a->sede_id;
            $porSede[$sede]['total'] = ($porSede[$sede]['total'] ?? 0) + 1;
            $porSede[$sede]['desde'] = isset($porSede[$sede]['desde']) && $porSede[$sede]['desde']->lt($vence) ? $porSede[$sede]['desde'] : $vence;
        }

        return collect($porSede)->map(fn ($s, $sede) => new Espera('lost_found_vencidos', (int) $sede, $s['desde'],
            'Lost & Found: '.$s['total'].' '.($s['total'] === 1 ? 'objeto vencido' : 'objetos vencidos'), route('lost_found.archivo', ['filtro' => 'urgentes']), (int) $sede,
            ['total' => $s['total']]))->values();
    }

    public function responsables(Espera $e, ?int $empresaId): Collection
    {
        return $this->conPermiso($empresaId, 'lost_found.firmar', $e->sedeId);
    }

    public function suplentes(Espera $e, ?int $empresaId, Collection $responsables, ?int $rolId): Collection
    {
        if ($rolId === null) {
            $config = $this->conPermiso($empresaId, 'lost_found.configurar', null)->reject(fn ($u) => $responsables->contains('id', $u->id))->values();
            if ($config->isNotEmpty()) {
                return $config;
            }
        }

        return parent::suplentes($e, $empresaId, $responsables, $rolId);
    }

    /** El renglón cuenta objetos, no sedes. */
    public function renglon(User $actor, ?int $empresaId): ?array
    {
        $r = parent::renglon($actor, $empresaId);
        if ($r === null) {
            return null;
        }
        $r['total'] = (int) $this->esperasDe($actor, $empresaId)->sum(fn (Espera $e) => $e->datos['total']);

        return $r;
    }
}
