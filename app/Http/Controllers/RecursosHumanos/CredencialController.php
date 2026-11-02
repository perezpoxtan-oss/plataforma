<?php

namespace App\Http\Controllers\RecursosHumanos;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\MovimientoActivo;
use App\Services\Credenciales\AdministradorCredenciales;
use App\Support\Entrada;
use App\Support\Lector\Etiqueta;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * C-44 (Etapa 8): la credencial del colaborador desde su ficha (pestaña
 * «Código y etiqueta»): Dar salida, Recibir (OK / Dañado / No regresó) y
 * Dar de baja con voucher. Permiso «Colaboradores → Credencial». Ver
 * docs/tecnico/credenciales.md.
 */
class CredencialController extends Controller
{
    public function __construct(
        private readonly AdministradorCredenciales $credenciales,
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
    ) {}

    public function darSalida(Request $request, int $colaborador): RedirectResponse
    {
        $request->validate(['etiqueta_nfc' => ['nullable', 'string', 'max:'.Etiqueta::MAXIMO]]);
        $credencial = $this->enEmpresa($request, function () use ($request, $colaborador) {
            $c = $this->credenciales->colaborador($request->user(), $colaborador);

            return $this->credenciales->darSalida($request->user(), $c, Entrada::texto($request->input('etiqueta_nfc')) ?: null);
        });

        return $this->volver($credencial->colaborador_id)->with('ok', "Credencial {$credencial->folio} entregada.")
            ->with('siguientes', $this->imprimir($request, $credencial->colaborador_id));
    }

    public function recibir(Request $request, int $credencial): RedirectResponse
    {
        $datos = $request->validate(['resultado' => ['required', Rule::in(array_keys(MovimientoActivo::RESULTADOS))]],
            ['resultado.required' => 'Elige cómo regresa: OK, Dañado o No regresó.', 'resultado.in' => 'Elige cómo regresa: OK, Dañado o No regresó.']);
        [$colaboradorId, $mensaje] = $this->enEmpresa($request, function () use ($request, $credencial, $datos) {
            $cred = $this->credenciales->credencial($request->user(), $credencial);

            return [$cred->colaborador_id, $this->credenciales->recibir($request->user(), $cred, $datos['resultado'])];
        });

        return $this->volver($colaboradorId)->with('ok', $mensaje)
            ->with('siguientes', $datos['resultado'] === 'danado' ? $this->imprimir($request, $colaboradorId) : []);
    }

    public function baja(Request $request, int $credencial): RedirectResponse
    {
        [$colaboradorId, $voucher] = $this->enEmpresa($request, function () use ($request, $credencial) {
            $cred = $this->credenciales->credencial($request->user(), $credencial);

            return [$cred->colaborador_id, $this->credenciales->darDeBaja($request->user(), $cred, $request->all())];
        });

        return $this->volver($colaboradorId)->with('ok', "Credencial dada de baja con el voucher {$voucher->folio}. Su código anterior ya no lo identifica: imprime la nueva y dale salida.")
            ->with('siguientes', array_merge($this->imprimir($request, $colaboradorId),
                $request->user()->can('vouchers.imprimir') ? [['Imprimir voucher', route('vouchers.imprimir', $voucher->id), 'bi-receipt']] : []));
    }

    /** Siguiente paso (criterio 5): imprimir la credencial en Etiquetas QR, ya filtrada. */
    private function imprimir(Request $request, int $colaboradorId): array
    {
        $actor = $request->user();
        if (! $actor->can('etiquetas_qr.ver') || ! $actor->can('colaboradores.editar')) {
            return [];
        }
        $num = $this->enEmpresa($request, fn () => Colaborador::whereKey($colaboradorId)->value('num_empleado'));

        return [['Imprimir credencial', route('etiquetas.index', ['tipo' => 'colaborador', 'q' => $num]), 'bi-printer']];
    }

    private function enEmpresa(Request $request, callable $fn): mixed
    {
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        return $this->tenant->conEmpresa($empresaId, $fn);
    }

    private function volver(int $colaboradorId): RedirectResponse
    {
        return redirect()->to(route('colaboradores.expediente', $colaboradorId).'#codigo');
    }
}
