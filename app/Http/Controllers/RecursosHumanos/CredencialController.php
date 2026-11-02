<?php

namespace App\Http\Controllers\RecursosHumanos;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\MovimientoActivo;
use App\Services\Credenciales\AdministradorCredenciales;
use App\Support\AjustesCredenciales;
use App\Support\Entrada;
use App\Support\Lector\Etiqueta;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * C-44 (Etapa 8): la credencial del colaborador. En su ficha (pestaña
 * «Código y etiqueta»): foto, Emitir, Imprimir, Dar salida, Recibir (OK /
 * Dañado / No regresó), Reponer y Dar de baja con voucher. La lista
 * «Credenciales» (Colaboradores → Credenciales) filtra por vigencia e
 * imprime varias a la vez. Permiso «Colaboradores → Credencial». Ver
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
        abort_unless($request->user()->can(AdministradorCredenciales::PERMISO), 403);
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
        // Primero permiso y alcance (403/404 sin revelar nada), después los datos
        abort_unless($request->user()->can(AdministradorCredenciales::PERMISO), 403);
        $colaboradorId = $this->enEmpresa($request, fn () => $this->credenciales->credencial($request->user(), $credencial)->colaborador_id);
        $datos = $request->validate(['resultado' => ['required', Rule::in(array_keys(MovimientoActivo::RESULTADOS))]],
            ['resultado.required' => 'Elige cómo regresa: OK, Dañado o No regresó.', 'resultado.in' => 'Elige cómo regresa: OK, Dañado o No regresó.']);
        $mensaje = $this->enEmpresa($request, fn () => $this->credenciales->recibir($request->user(),
            $this->credenciales->credencial($request->user(), $credencial), $datos['resultado']));

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

    public function emitir(Request $request, int $colaborador): RedirectResponse
    {
        abort_unless($request->user()->can(AdministradorCredenciales::PERMISO), 403);
        $credencial = $this->enEmpresa($request, fn () => $this->credenciales->emitir($request->user(), $this->credenciales->colaborador($request->user(), $colaborador)));

        return $this->volver($credencial->colaborador_id)
            ->with('ok', "Credencial {$credencial->folio} emitida".($credencial->vence_en ? ' (vence el '.$credencial->vence_en->format('d/m/Y').')' : '').'. Imprímela y dale salida cuando la recoja.')
            ->with('siguientes', [['Imprimir credencial', route('credenciales.imprimir', ['credenciales' => [$credencial->id]]), 'bi-printer']]);
    }

    public function foto(Request $request, int $colaborador): RedirectResponse
    {
        abort_unless($request->user()->can(AdministradorCredenciales::PERMISO), 403);
        $c = $this->enEmpresa($request, function () use ($request, $colaborador) {
            $c = $this->credenciales->colaborador($request->user(), $colaborador);
            $this->credenciales->guardarFoto($request->user(), $c, $request->file('foto'), is_string($request->input('foto_camara')) ? $request->input('foto_camara') : null);

            return $c;
        });

        $vigente = $this->enEmpresa($request, fn () => $this->credenciales->vigenteDe($c));

        return $this->volver($c->id)->with('ok', 'Foto guardada.'.($vigente ? ' Si la credencial ya estaba impresa, repónla con «Cambio de datos o foto».' : ' Ya puedes emitir su credencial.'))
            ->with('siguientes', $vigente ? [] : [['Emitir credencial', route('colaboradores.expediente', $c->id).'#codigo', 'bi-person-vcard']]);
    }

    public function reponer(Request $request, int $credencial): RedirectResponse
    {
        abort_unless($request->user()->can(AdministradorCredenciales::PERMISO), 403);
        [$nueva, $voucher] = $this->enEmpresa($request, fn () => $this->credenciales->reponer($request->user(),
            $this->credenciales->credencial($request->user(), $credencial), $request->all()));

        $siguientes = [['Imprimir credencial', route('credenciales.imprimir', ['credenciales' => [$nueva->id]]), 'bi-printer']];
        if ($voucher !== null && $request->user()->can('vouchers.imprimir')) {
            $siguientes[] = ['Imprimir voucher', route('vouchers.imprimir', $voucher->id), 'bi-receipt'];
        }

        return $this->volver($nueva->colaborador_id)
            ->with('ok', "Credencial repuesta: la anterior ya no es vigente y se emitió {$nueva->folio}".($voucher ? " (voucher {$voucher->folio})" : '').'. Imprímela y dale salida.')
            ->with('siguientes', $siguientes);
    }

    /** Hoja CR80 (frente y reverso) de las credenciales elegidas. */
    public function hoja(Request $request): View
    {
        abort_unless($request->user()->can(AdministradorCredenciales::PERMISO), 403);
        $ids = array_slice(array_values(array_unique(array_map('intval', array_filter((array) $request->query('credenciales', []), 'is_numeric')))), 0, 200);

        return $this->enEmpresa($request, function () use ($request, $ids) {
            $actor = $request->user();
            $credenciales = $this->credenciales->paraImprimir($actor, $ids);
            abort_if($credenciales->isEmpty(), 404);
            foreach ($credenciales as $cr) {
                $this->credenciales->registrarImpresion($actor, $cr);
            }
            $empresa = Empresa::find($this->empresa->id($actor));

            return view('rh.credenciales.imprimir', [
                'credenciales' => $credenciales,
                'empresaNombre' => $empresa?->nombre_comercial ?? '',
                'logo' => $this->credenciales->logo($empresa),
                'ajustes' => AjustesCredenciales::de($empresa),
            ]);
        });
    }

    /** Lista «Credenciales» con filtros (Vigentes, Por vencer, Vencidas, No regresó, De baja, Sin credencial). */
    public function index(Request $request): View
    {
        abort_unless($request->user()->can(AdministradorCredenciales::PERMISO), 403);
        $estado = in_array($request->query('estado'), array_keys(AdministradorCredenciales::FILTROS), true) ? $request->query('estado') : 'todas';
        $texto = mb_substr(trim(Entrada::texto($request->query('q'))), 0, 80);

        return $this->enEmpresa($request, function () use ($request, $estado, $texto) {
            $actor = $request->user();
            $ajustes = AjustesCredenciales::de($this->empresa->id($actor));

            return view('rh.credenciales.index', [
                'filas' => $this->credenciales->lista($actor, $estado, $texto, $ajustes['aviso_dias']),
                'conteos' => $this->credenciales->conteos($actor, $ajustes['aviso_dias']),
                'estado' => $estado,
                'texto' => $texto,
                'ajustes' => $ajustes,
            ]);
        });
    }

    /** «Imprimir seleccionadas»: emite la de quien no tiene vigente y abre la hoja de todas. */
    public function imprimirSeleccion(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can(AdministradorCredenciales::PERMISO), 403);
        $ids = array_slice(array_values(array_unique(array_map('intval', array_filter((array) $request->input('colaboradores', []), 'is_numeric')))), 0, 200);
        if ($ids === []) {
            return back()->withErrors(['colaboradores' => 'Marca al menos un colaborador.']);
        }
        [$credenciales, $omitidos] = $this->enEmpresa($request, function () use ($request, $ids) {
            $actor = $request->user();
            $listas = [];
            $omitidos = 0;
            foreach ($ids as $id) {
                $c = $this->credenciales->colaborador($actor, $id);
                try {
                    $listas[] = ($this->credenciales->vigenteDe($c) ?? $this->credenciales->emitir($actor, $c))->id;
                } catch (ValidationException) {
                    $omitidos++; // provisional o de baja: se salta y se avisa
                }
            }

            return [$listas, $omitidos];
        });
        if ($credenciales === []) {
            return back()->withErrors(['colaboradores' => 'Ninguno de los marcados puede tener credencial (altas provisionales o de baja).']);
        }

        return redirect()->route('credenciales.imprimir', ['credenciales' => $credenciales])
            ->with('aviso', $omitidos > 0 ? "{$omitidos} no se incluyeron: son altas provisionales o están de baja." : null);
    }

    /** Siguiente paso (criterio 5): la hoja CR80 de su credencial vigente. */
    private function imprimir(Request $request, int $colaboradorId): array
    {
        $id = $this->enEmpresa($request, function () use ($colaboradorId) {
            $c = Colaborador::find($colaboradorId);

            return $c === null ? null : $this->credenciales->vigenteDe($c)?->id;
        });

        return $id === null ? [] : [['Imprimir credencial', route('credenciales.imprimir', ['credenciales' => [$id]]), 'bi-printer']];
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
