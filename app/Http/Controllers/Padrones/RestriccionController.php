<?php

namespace App\Http\Controllers\Padrones;

use App\Http\Controllers\Controller;
use App\Services\Padrones\RestriccionAcceso;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * C-13: «Restringir acceso» / «Quitar restricción» (Personas, Vehículos y
 * Empresas externas). PATCH /restriccion/{padron}/{id} con accion=restringir
 * (motivo, hasta) o accion=quitar. Mismo permiso que dar de baja en ese padrón.
 */
class RestriccionController extends Controller
{
    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly RestriccionAcceso $restricciones,
    ) {}

    public function guardar(Request $request, string $padron, int $id): RedirectResponse
    {
        $definicion = RestriccionAcceso::PADRONES[$padron] ?? null;
        abort_if($definicion === null, 404);
        [$clase, $permiso, , $lista, $ancla] = $definicion;
        Gate::authorize($permiso);
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);
        $quitar = $request->input('accion') === 'quitar';

        $modelo = $this->tenant->conEmpresa($empresaId, function () use ($request, $clase, $id, $padron, $quitar) {
            $modelo = $clase::query()->find($id);
            abort_if($modelo === null, 404);
            $quitar
                ? $this->restricciones->quitar($request->user(), $padron, $modelo)
                : $this->restricciones->restringir($request->user(), $padron, $modelo, $request->only(['motivo', 'hasta']));

            return $modelo;
        });

        return redirect()->to(route($lista).'#'.$ancla.$modelo->getKey())->with($quitar ? 'ok' : 'aviso', $quitar
            ? 'Se quitó la restricción: la caseta ya lo puede registrar.'
            : 'Acceso restringido: la caseta no lo podrá registrar (tampoco con un nombre parecido sin confirmar que es otro). Lo quitas con «Quitar restricción».');
    }
}
