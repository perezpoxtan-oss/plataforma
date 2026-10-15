<?php

namespace App\Http\Controllers\Publico;

use App\Http\Controllers\Controller;
use App\Services\Comercial\Pagos\Pasarelas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ruta RESERVADA para los avisos de pago de una pasarela en línea
 * (POST /pagos/webhook/{pasarela}). Hoy está APAGADA: responde 404 mientras
 * config('comercial.webhooks_habilitados') sea falso o la pasarela no exista.
 * Ver docs/tecnico/catalogo-comercial.md, «Pagos en línea: cómo conectarlos».
 */
class PagoWebhookController extends Controller
{
    public function __invoke(Request $request, string $pasarela, Pasarelas $pasarelas): JsonResponse
    {
        abort_unless((bool) config('comercial.webhooks_habilitados'), 404);
        $implementacion = $pasarelas->porClave($pasarela);
        abort_if($implementacion === null || $pasarela === 'manual', 404);

        // La pasarela valida la firma y consulta el pago antes de marcar la solicitud como pagada
        $solicitud = $implementacion->confirmar($request);

        return response()->json(['ok' => $solicitud !== null], $solicitud !== null ? 200 : 400);
    }
}
