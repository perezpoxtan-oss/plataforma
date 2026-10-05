<?php

namespace App\Services\Vouchers;

use App\Models\User;
use App\Models\VoucherReposicion;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use Illuminate\Database\Eloquent\Builder;

/**
 * Qué vouchers de reposición puede ver cada quien (SEGCAT: voucher_lista.php
 * y voucher_imprimir.php).
 *
 * Además de "vouchers.ver", el voucher se ve solo si el usuario puede ver el
 * módulo de donde salió (Llaves, Gafetes o Equipos) y solo en las sedes de
 * ambos permisos. Con alcance "propios" en vouchers.ver, solo los que él
 * generó. Corre con la empresa de trabajo fijada en el Tenant.
 */
class ConsultaVouchers
{
    public function __construct(private readonly Autorizador $autorizador) {}

    /**
     * Tipos de origen que el actor puede ver: [origen_tipo => etiqueta].
     *
     * @return array<string, string>
     */
    public function origenesVisibles(User $actor): array
    {
        $visibles = [];
        foreach (VoucherReposicion::ORIGENES as $tipo => [$etiqueta, $modulo]) {
            if ($actor->can($modulo.'.ver')) {
                $visibles[$tipo] = $etiqueta;
            }
        }

        return $visibles;
    }

    /**
     * @return Builder<VoucherReposicion>
     */
    public function consulta(User $actor, string $permiso = 'vouchers.ver'): Builder
    {
        $consulta = VoucherReposicion::query();
        if ($actor->es_superadmin) {
            return $consulta;
        }

        $propio = $actor->activo ? ($this->autorizador->permisosEfectivos($actor)[$permiso] ?? null) : null;
        if ($propio === null) {
            return $consulta->whereRaw('1 = 0');
        }
        $sedesPropias = $this->sedes($actor, $permiso);

        $origenes = array_keys($this->origenesVisibles($actor));
        if ($origenes === []) {
            return $consulta->whereRaw('1 = 0');
        }

        return $consulta
            ->where(function (Builder $q) use ($actor, $origenes, $sedesPropias) {
                foreach ($origenes as $tipo) {
                    $sedes = $this->interseccion($sedesPropias, $this->sedes($actor, VoucherReposicion::ORIGENES[$tipo][1].'.ver'));
                    $q->orWhere(fn (Builder $o) => $o->where('vouchers_reposicion.origen_tipo', $tipo)
                        ->when($sedes !== null, fn ($s) => $s->whereIn('vouchers_reposicion.sede_id', $sedes)));
                }
            })
            ->when($propio->alcance === Alcance::Propios, fn ($q) => $q->where('vouchers_reposicion.creado_por', $actor->id));
    }

    /**
     * @return list<int>|null
     */
    private function sedes(User $actor, string $permiso): ?array
    {
        return $this->autorizador->sedesPermitidas($actor, $permiso);
    }

    /**
     * @param  list<int>|null  $a
     * @param  list<int>|null  $b
     * @return list<int>|null
     */
    private function interseccion(?array $a, ?array $b): ?array
    {
        return match (true) {
            $a === null => $b,
            $b === null => $a,
            default => array_values(array_intersect($a, $b)),
        };
    }
}
