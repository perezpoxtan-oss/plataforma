<?php

namespace App\Services\Novedades\FichaHechos;

use App\Models\Espacio;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Fuente extra de la Ficha de Hechos (por ejemplo, Préstamo de llaves o la
 * Bitácora de accesos, que viven en otros módulos). Cada módulo registra la
 * suya en su proveedor de servicios:
 *
 *     $this->app->tag([PrestamosEnFichaDeHechos::class], FichaDeHechos::ETIQUETA);
 *
 * La ficha solo la consulta si disponible() es verdadero (por ejemplo, si su
 * tabla ya existe y el usuario tiene permiso de ver ese módulo).
 */
interface FuenteFichaHechos
{
    public function disponible(User $actor): bool;

    /**
     * Hechos de la habitación exacta y de su zona (el piso o edificio que la
     * contiene) entre $desde y $hasta, ya filtrados por empresa y sede.
     *
     * @return array{habitacion: list<array<string, mixed>>, zona: list<array<string, mixed>>}
     *                                                                                         Cada hecho: tipo, icono, color, texto, fecha (Carbon), enlace (?string)
     */
    public function hechos(User $actor, Espacio $habitacion, ?Espacio $zona, CarbonInterface $desde, CarbonInterface $hasta): array;
}
