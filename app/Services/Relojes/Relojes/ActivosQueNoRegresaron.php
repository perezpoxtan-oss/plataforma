<?php

namespace App\Services\Relojes\Relojes;

use App\Models\Equipo;
use App\Models\Gafete;
use App\Models\Llave;
use App\Models\User;
use App\Services\Activos\CustodiaActivos;
use App\Services\Relojes\Espera;
use App\Services\Relojes\Reloj;
use Illuminate\Support\Collection;

/**
 * Llave, gafete o equipo que no regresó (renglón 26; C-14, C-15, C-24):
 * espera que alguien lo reciba o lo dé de baja con su voucher. El renglón de
 * Mis pendientes ya existe (Etapa 2); aquí se le pone reloj y suplente.
 */
class ActivosQueNoRegresaron extends Reloj
{
    private const TIPOS = [
        'llave' => [Llave::class, 'llaves.eliminar', 'llaves.index', 'Llave'],
        'gafete' => [Gafete::class, 'gafetes.eliminar', 'gafetes.index', 'Gafete'],
        'equipo' => [Equipo::class, 'equipos.eliminar', 'equipos.index', 'Equipo'],
    ];

    public function clave(): string
    {
        return 'activos_no_regreso';
    }

    public function nombre(): string
    {
        return 'Llaves, gafetes o equipos que no regresaron';
    }

    public function suplenteOmision(): string
    {
        return 'quien da de baja ese activo en toda la empresa (o el administrador)';
    }

    public function permiso(): ?string
    {
        return 'llaves.eliminar';
    }

    public function esperas(?int $empresaId): Collection
    {
        $lista = collect();
        foreach (self::TIPOS as $origen => [$modelo, $permiso, $ruta, $nombre]) {
            foreach ($modelo::where('estado', 'no_regreso')->orderBy('updated_at')->limit(200)->get() as $r) {
                $lista->push(new Espera(CustodiaActivos::TIPO_AVISO.'_'.$origen, (int) $r->id, $r->updated_at ?? now(), $nombre.' '.($r->nomenclatura ?? $r->numero_serie ?? '#'.$r->id).' no regresó',
                    route($ruta, ['baja' => $r->id]), $r->sede_id !== null ? (int) $r->sede_id : null, ['permiso' => $permiso]));
            }
        }

        return $lista;
    }

    public function responsables(Espera $e, ?int $empresaId): Collection
    {
        return $this->conPermiso($empresaId, $this->permisoDe($e), $e->sedeId);
    }

    public function puedeVer(User $actor): bool
    {
        return $actor->can('llaves.eliminar') || $actor->can('gafetes.eliminar') || $actor->can('equipos.eliminar');
    }

    /** D-01: el renglón de Mis pendientes que ya existía y lo cuenta. */
    public function renglonPropio(): ?string
    {
        return 'activos_no_regreso';
    }
}
