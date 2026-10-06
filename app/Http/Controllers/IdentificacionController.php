<?php

namespace App\Http\Controllers;

use App\Services\Lector\Identificacion;
use App\Support\Lector\Etiqueta;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Ronda 5: diálogo "Código e identificación" de las fichas con QR
 * (componentes/codigo-identificacion.blade.php). Ver docs/tecnico/lector.md.
 *
 * - GET  /identificacion/{tipo}/{id}/qr        QR en SVG     (permiso <modulo>.ver)
 * - PUT  /identificacion/{tipo}/{id}/etiqueta  NFC / RFID    (permiso <modulo>.editar)
 *
 * Los permisos salen del tipo (config/lector.php): la pantalla no decide.
 */
class IdentificacionController extends Controller
{
    public function __construct(
        private readonly Identificacion $identificacion,
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
    ) {}

    public function qr(Request $request, string $tipo, int $id): Response
    {
        $svg = $this->tenant->conEmpresa($this->empresaDeTrabajo($request), function () use ($request, $tipo, $id) {
            return $this->identificacion->qrSvg($this->identificacion->buscar($request->user(), $tipo, $id, 'ver'));
        });

        return response($svg, 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'private, max-age=3600', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function etiqueta(Request $request, string $tipo, int $id): JsonResponse
    {
        $request->validate(['etiqueta_nfc' => ['nullable', 'string', 'max:'.Etiqueta::MAXIMO]], [
            'etiqueta_nfc.max' => 'Lo leído es demasiado largo para ser una etiqueta NFC/RFID.',
        ]);

        $etiqueta = $this->tenant->conEmpresa($this->empresaDeTrabajo($request), function () use ($request, $tipo, $id) {
            $registro = $this->identificacion->buscar($request->user(), $tipo, $id, 'editar');

            return $this->identificacion->asignarEtiqueta($request->user(), $tipo, $registro, $request->input('etiqueta_nfc'));
        });

        return response()->json([
            'ok' => true,
            'etiqueta' => $etiqueta,
            'mensaje' => $etiqueta === null ? 'Se quitó la etiqueta NFC / RFID.' : "Etiqueta {$etiqueta} asignada. Ya se puede leer con el lector.",
        ]);
    }

    private function empresaDeTrabajo(Request $request): int
    {
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        return $empresaId;
    }
}
