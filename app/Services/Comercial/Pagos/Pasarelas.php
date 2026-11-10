<?php

namespace App\Services\Comercial\Pagos;

use App\Contracts\PasarelaPago;

/**
 * Elige la pasarela configurada (config/comercial.php → «pasarela»). Hoy
 * solo hay «manual»; una clave desconocida cae en la Manual (nunca se deja
 * de registrar una solicitud por una configuración equivocada).
 */
class Pasarelas
{
    /** clave => clase que implementa PasarelaPago */
    public const DISPONIBLES = [
        'manual' => PasarelaManual::class,
        // 'mercadopago' => PasarelaMercadoPago::class,  // ver docs/tecnico/catalogo-comercial.md
    ];

    public function activa(): PasarelaPago
    {
        return $this->porClave((string) config('comercial.pasarela', 'manual')) ?? app(PasarelaManual::class);
    }

    public function porClave(string $clave): ?PasarelaPago
    {
        $clase = self::DISPONIBLES[$clave] ?? null;

        return $clase === null ? null : app($clase);
    }
}
