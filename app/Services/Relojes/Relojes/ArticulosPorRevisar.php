<?php

namespace App\Services\Relojes\Relojes;

use App\Models\Acceso;
use App\Services\Relojes\Espera;
use App\Services\Relojes\Reloj;
use Illuminate\Support\Collection;

/**
 * C-19 (DD-18): el proveedor salió con algo distinto de lo que trajo y la
 * caseta lo canalizó. El supervisor de la sede lo revisa y toca «Atender»;
 * si nadie lo revisa, pasa a quien autoriza accesos en toda la empresa.
 */
class ArticulosPorRevisar extends Reloj
{
    public const REFERENCIA = 'acceso_articulos';

    public function clave(): string
    {
        return 'articulos_caseta';
    }

    public function nombre(): string
    {
        return 'Salidas con algo distinto por revisar';
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
        return ['articulos_caseta', 'Salidas con algo distinto por revisar', 'bi-tools', route('accesos.index', ['pestana' => 'historial', 'articulos' => 1])];
    }

    public function textosInicio(): ?array
    {
        return ['1 salida con algo distinto por revisar', '%d salidas con algo distinto por revisar',
            'Un proveedor no salió con lo mismo que trajo y la caseta te lo canalizó. Revisa el caso y toca «Atender».', 'Revisar'];
    }

    public function esperas(?int $empresaId): Collection
    {
        return Acceso::where('articulos_por_revisar', true)->whereNull('articulos_revisado_en')->orderBy('salida_at')->limit(500)
            ->get(['id', 'sede_id', 'nombre', 'salida_at', 'entrada_at'])
            ->map(fn (Acceso $a) => new Espera(self::REFERENCIA, (int) $a->id, $a->salida_at ?? $a->entrada_at ?? now(), 'Salió con algo distinto: '.$a->nombre,
                route('accesos.index', ['pestana' => 'historial', 'articulos' => 1]).'#acceso-'.$a->id, (int) $a->sede_id))->values();
    }

    public function responsables(Espera $e, ?int $empresaId): Collection
    {
        return $this->conPermiso($empresaId, 'accesos.aprobar', $e->sedeId);
    }
}
