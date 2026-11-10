<?php

namespace App\Services\Novedades\Formatos;

use App\Models\Novedad;
use App\Models\User;

/**
 * Mantenimiento de equipo PC (ADR-0013): el ticket que abre un hallazgo de
 * un Recorrido de Protección Civil cuando la empresa elige esa categoría en
 * Configuración → Bitácora de novedades (por omisión sigue siendo «Siniestro
 * Protección Civil»). No tiene campos propios: muestra los equipos con falla
 * de los recorridos que lo abrieron, sin volver a capturarlos. El seguimiento
 * se hace en el Minuto a Minuto y el cierre con su resolución, como siempre.
 */
class MantenimientoPc extends Formato
{
    public function relaciones(): array
    {
        return ['recorridosPc.revisiones'];
    }

    public function valores(Novedad $novedad): array
    {
        return [];
    }

    public function validar(array $entrada, Novedad $novedad): array
    {
        return [];
    }

    public function guardar(Novedad $novedad, array $datos, User $actor): void
    {
        // Nada que guardar: los hallazgos viven en el recorrido.
    }
}
