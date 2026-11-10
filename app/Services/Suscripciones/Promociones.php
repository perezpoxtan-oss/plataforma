<?php

namespace App\Services\Suscripciones;

use App\Models\Plan;
use App\Models\Promocion;
use Illuminate\Support\Facades\DB;

/**
 * Códigos de promoción: búsqueda por código (sin distinguir mayúsculas ni
 * espacios), validación (vigencia, planes, periodicidad, límite de usos) y
 * conteo de usos. Un uso se cuenta cuando el cliente se ACTIVA (no al enviar
 * la solicitud): la solicitud conserva el descuento que se le cotizó.
 */
class Promociones
{
    /**
     * @return array{0: ?Promocion, 1: ?string} la promoción válida, o el motivo por el que no aplica
     */
    public function porCodigo(?string $codigo, ?Plan $plan, string $periodicidad): array
    {
        $codigo = Promocion::normalizarCodigo($codigo);
        if ($codigo === '') {
            return [null, null];
        }
        $promocion = Promocion::query()->with('planes:id,nombre')->where('codigo', $codigo)->first();
        if ($promocion === null) {
            return [null, 'Ese código de promoción no existe. Revisa que esté bien escrito.'];
        }
        $motivo = $promocion->motivoNoAplica($plan, $periodicidad);

        return $motivo === null ? [$promocion, null] : [null, $motivo];
    }

    /** Cuenta un uso (sin pasar del límite: si ya se agotó, la activación conserva lo cotizado). */
    public function contarUso(Promocion $promocion): void
    {
        DB::table('promociones')->where('id', $promocion->id)->increment('usos');
    }
}
