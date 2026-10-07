<?php

namespace App\Http\Controllers\Padrones;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\Sede;
use App\Services\Lector\EtiquetasMasivas;
use App\Services\Permisos\Autorizador;
use App\Support\Entrada;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Ronda 6 (LL-06): Padrones → Etiquetas QR. Impresión masiva de las
 * etiquetas QR de todo lo identificable (EtiquetasMasivas). La etiqueta de
 * un solo registro sigue en su diálogo «Código e identificación».
 *
 *  - GET /etiquetas            lista con filtros (tipo, sede, estado, búsqueda) y casillas
 *  - GET /etiquetas/imprimir   hoja con la cuadrícula de etiquetas (?sel[]=llave-12&tamano=etiqueta)
 *
 * Entrar pide «etiquetas_qr.ver»; cada tipo pide además sus propios permisos.
 */
class EtiquetaController extends Controller
{
    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly EtiquetasMasivas $etiquetas,
        private readonly Autorizador $autorizador,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('etiquetas_qr.ver');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        if ($empresaId === null) {
            return view('padrones.etiquetas.index', ['sinEmpresa' => true]);
        }

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $actor, $empresaId) {
            $tipos = $this->etiquetas->tipos($actor);
            $tipo = Entrada::texto($request->query('tipo'));
            $filtros = [
                'tipo' => isset($tipos[$tipo]) ? $tipo : null,
                'sede' => ctype_digit(Entrada::texto($request->query('sede'))) ? (int) $request->query('sede') : null,
                'estado' => Entrada::texto($request->query('estado')) === 'todos' ? 'todos' : 'activos',
                'q' => mb_substr(trim(Entrada::texto($request->query('q'))), 0, 100),
            ];
            $filas = $this->etiquetas->lista($actor, $filtros);

            // Sedes del filtro: las que alcanza con alguno de los permisos de ver
            $permitidas = null;
            if (! $actor->es_superadmin) {
                $listas = collect($tipos)->map(fn ($t) => $this->autorizador->sedesPermitidas($actor, $t['permisos'][0]));
                $permitidas = $listas->contains(null) ? null : $listas->flatten()->unique()->values()->all();
            }

            return view('padrones.etiquetas.index', [
                'sinEmpresa' => false,
                'tipos' => $tipos,
                'filtros' => $filtros,
                'filas' => $filas,
                'conteo' => $filas->countBy('tipo'),
                'sedes' => Sede::where('activo', true)->when($permitidas !== null, fn ($q) => $q->whereIn('id', $permitidas))->orderBy('nombre')->get(['id', 'nombre']),
                'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial'),
            ]);
        });
    }

    public function imprimir(Request $request): View|RedirectResponse
    {
        Gate::authorize('etiquetas_qr.ver');
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);
        $seleccion = collect((array) $request->query('sel', []))->filter(fn ($v) => is_string($v))->values()->all();
        $tamano = Entrada::texto($request->query('tamano'));
        $tamano = isset(EtiquetasMasivas::TAMANOS[$tamano]) ? $tamano : 'etiqueta';
        if ($seleccion === []) {
            return redirect()->route('etiquetas.index')->with('aviso', 'Marca al menos un registro para imprimir sus etiquetas.');
        }

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $seleccion, $tamano, $empresaId) {
            $etiquetas = $this->etiquetas->paraImprimir($request->user(), $seleccion);
            abort_if($etiquetas->isEmpty(), 404);

            return view('padrones.etiquetas.imprimir', [
                'etiquetas' => $etiquetas,
                'tamano' => $tamano,
                'medidas' => EtiquetasMasivas::TAMANOS[$tamano],
                'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial'),
                'recortadas' => count(array_unique($seleccion)) > EtiquetasMasivas::MAXIMO,
            ]);
        });
    }
}
