<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Bajas\AntesDeDarDeBaja;
use App\Services\Borrado\RegistroBorrado;
use App\Services\Permisos\Autorizador;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * C-20 (DD-06): «Antes de dar de baja». Una sola pantalla para colaboradores,
 * personas, empresas externas, vehículos, llaves, equipos, sedes, zonas de
 * estacionamiento, departamentos, puestos, turnos y zonas o áreas:
 *
 *  GET  /antes-de-dar-de-baja/{tipo}/{id}            lo vigente que depende del registro
 *  POST /antes-de-dar-de-baja/{tipo}/{id}/no-regreso  llave o equipo en custodia: queda
 *                                                     «No regresó» y abre su baja con voucher
 *
 * La baja de los padrones la sigue haciendo su propia ruta (PATCH …/estado)
 * con revisado=1; esta pantalla solo explica y arma ese envío.
 */
class BajasController extends Controller
{
    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly Autorizador $autorizador,
        private readonly AntesDeDarDeBaja $bajas,
    ) {}

    public function revisar(Request $request, string $tipo, int $id): View
    {
        [$modelo, $empresaId] = $this->resolver($request, $tipo, $id);
        $definicion = AntesDeDarDeBaja::TIPOS[$tipo];

        return $this->tenant->conEmpresa($empresaId, function () use ($tipo, $modelo, $definicion) {
            $revision = $this->bajas->revisar($tipo, $modelo);
            $registro = RegistroBorrado::de($tipo);

            return view('bajas.revisar', [
                'tipo' => $tipo,
                'modelo' => $modelo,
                'nombre' => $registro !== null ? RegistroBorrado::nombre($registro, $modelo) : (string) $modelo->getKey(),
                'articulo' => $registro !== null ? RegistroBorrado::conArticulo($registro) : 'el registro',
                'revision' => $revision,
                'conVoucher' => $definicion[2] === null,
                'urlBaja' => $definicion[2] !== null ? route($definicion[2], $modelo->getKey()) : route('bajas.no-regreso', ['tipo' => $tipo, 'id' => $modelo->getKey()]),
                'volver' => $this->volver($tipo, $modelo),
                'reemplazos' => $revision['reemplazo'] !== []
                    ? User::where('activo', true)->where('es_superadmin', false)
                        ->where(fn ($q) => $q->whereNull('colaborador_id')->orWhere('colaborador_id', '!=', $modelo->getKey()))
                        ->orderBy('name')->get(['id', 'name'])
                    : collect(),
                'reemplazoAnterior' => old('reemplazo_id'),
            ]);
        });
    }

    /**
     * Llave prestada o equipo a cargo de alguien que no regresó (C-14 + DD-06):
     * queda NO REGRESÓ y se abre su baja con voucher a nombre de quien lo tenía.
     */
    public function noRegreso(Request $request, string $tipo, int $id): RedirectResponse
    {
        abort_unless(in_array($tipo, ['llaves', 'equipos'], true), 404);
        [$modelo, $empresaId] = $this->resolver($request, $tipo, $id);
        $this->tenant->conEmpresa($empresaId, function () use ($tipo, $modelo, $request) {
            $revision = $this->bajas->revisar($tipo, $modelo);
            $this->bajas->aplicar($tipo, $modelo, $revision, [], $request->user());
        });
        $ruta = $tipo === 'llaves' ? 'llaves.index' : 'equipos.index';
        $ancla = $tipo === 'llaves' ? 'llave-' : 'equipo-';

        return redirect()->to(route($ruta, ['baja' => $modelo->getKey()]).'#'.$ancla.$modelo->getKey())
            ->with('aviso', 'Quedó como «No regresó». Completa aquí su baja: el voucher ya propone a quien lo tenía.');
    }

    /**
     * @return array{0: Model, 1: int}
     */
    private function resolver(Request $request, string $tipo, int $id): array
    {
        $definicion = AntesDeDarDeBaja::TIPOS[$tipo] ?? null;
        abort_if($definicion === null, 404);
        [$clase, $permiso] = $definicion;
        Gate::authorize($permiso);

        /** @var User $actor */
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        abort_if($empresaId === null, 404);
        $modelo = $this->tenant->conEmpresa($empresaId, fn () => $clase::query()->find($id));
        abort_if($modelo === null, 404);

        $alcance = RegistroBorrado::de($tipo)['alcance'] ?? 'empresa';
        if (! $actor->es_superadmin && $alcance === 'sede') {
            abort_unless($this->tenant->conEmpresa($empresaId, fn () => $this->autorizador->puede($actor, $permiso, $modelo)), 404);
        }

        return [$modelo, $empresaId];
    }

    private function volver(string $tipo, Model $modelo): string
    {
        $lista = AntesDeDarDeBaja::TIPOS[$tipo][3];
        if ($lista === null) {
            $registro = RegistroBorrado::de($tipo);

            return $registro !== null && $registro['volver'] instanceof \Closure ? ($registro['volver'])($modelo) : url()->previous();
        }

        return route($lista);
    }
}
