<?php

namespace App\Http\Controllers;

use App\Services\Lector\Lector;
use App\Support\Lector\Etiqueta;
use App\Support\Tenancy\EmpresaDeTrabajo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Lector universal (QR, NFC, RFID, código de barras). Ver
 * docs/tecnico/lector.md y docs/decisiones/ADR-0005.
 */
class LectorController extends Controller
{
    public function __construct(
        private readonly Lector $lector,
        private readonly EmpresaDeTrabajo $empresa,
    ) {}

    /**
     * GET /lector/resolver?entrada=…&tipos=llave,colaborador
     * Lo usan las pantallas: devuelve los registros que corresponden a lo leído.
     */
    public function resolver(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'entrada' => ['required', 'string', 'max:'.Etiqueta::MAXIMO],
            'tipos' => ['nullable', 'string', 'max:200', 'regex:/^[a-z_]+(,[a-z_]+)*$/'],
        ]);
        $tipos = isset($datos['tipos']) ? explode(',', $datos['tipos']) : null;

        $resultados = $this->lector->resolver($request->user(), $this->empresa->id($request->user()), $datos['entrada'], $tipos);

        return response()->json(['resultados' => $resultados]);
    }

    /**
     * GET /e/{codigo}: a donde lleva un QR o una etiqueta NFC con dirección
     * (por ejemplo, el iPhone al acercar una etiqueta, sin app). Abre la
     * pantalla del registro si el usuario puede verlo.
     */
    public function ir(Request $request, string $codigo): RedirectResponse
    {
        $encontrado = $this->lector->resolver($request->user(), $this->empresa->id($request->user()), '/e/'.$codigo, null, 1)->first();
        abort_if($encontrado === null, 404);

        return redirect()->to($encontrado['url']);
    }
}
