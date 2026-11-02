<?php

namespace App\Http\Controllers;

use App\Models\MovimientoActivo;
use App\Services\Activos\HistorialActivos;
use App\Services\Lector\Identificacion;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * C-45 (DD-19): «Entregas y recepciones» de una llave, un gafete o un
 * equipo de seguridad (historial único movimientos_activo). Se abre desde
 * la ficha; los permisos son los de ver ese módulo, con su sede y alcance
 * (los mismos de «Código e identificación»). Ver docs/tecnico/entregar-recibir.md.
 */
class HistorialActivosController extends Controller
{
    public const TIPOS = ['llave', 'gafete', 'equipo'];

    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly HistorialActivos $historial,
    ) {}

    public function show(Request $request, string $tipo, int $id): View
    {
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $tipo, $id) {
            $registro = app(Identificacion::class)->buscar($request->user(), $tipo, $id, 'ver');
            $resumen = $registro->resumenLector();
            [$titulo, $detalle, $ficha] = [$resumen['titulo'], $resumen['detalle'] ?? null, $registro->urlLector()];

            return view('activos.historial', [
                'tipoNombre' => MovimientoActivo::TIPOS[$tipo],
                'titulo' => $titulo,
                'detalle' => $detalle,
                'ficha' => $ficha,
                'movimientos' => $this->historial->de($tipo, $id),
            ]);
        });
    }
}
