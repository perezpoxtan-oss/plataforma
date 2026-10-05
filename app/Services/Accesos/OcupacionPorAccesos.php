<?php

namespace App\Services\Accesos;

use App\Models\Acceso;
use App\Services\Estacionamientos\OcupacionEstacionamientos;

/**
 * Ocupación de cada zona de estacionamiento: vehículos que siguen "EN SITIO"
 * con esa zona en la Bitácora de accesos (SEGCAT la contaba en vivo y nunca la
 * guardaba aparte, así la salida libera el espacio sin pasos extra).
 *
 * Se registra en App\Providers\AccesosServiceProvider. Una sola consulta para
 * todas las zonas, dentro de la empresa de trabajo (filtro del modelo).
 */
class OcupacionPorAccesos implements OcupacionEstacionamientos
{
    public function ocupados(array $zonaIds): array
    {
        if ($zonaIds === []) {
            return [];
        }

        return Acceso::query()
            ->whereIn('zona_estacionamiento_id', $zonaIds)
            ->where('estado', 'en_sitio')
            ->groupBy('zona_estacionamiento_id')
            ->selectRaw('zona_estacionamiento_id, COUNT(*) as total')
            ->pluck('total', 'zona_estacionamiento_id')
            ->mapWithKeys(fn ($total, $zona) => [(int) $zona => (int) $total])
            ->all();
    }
}
