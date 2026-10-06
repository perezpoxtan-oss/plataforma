<?php

namespace App\Http\Controllers\Seguridad;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\EquipoPc;
use App\Models\RecorridoPc;
use App\Models\User;
use App\Services\RecorridosPc\AdministradorRecorridosPc;
use App\Services\RecorridosPc\CatalogoEquiposPc;
use App\Services\RecorridosPc\Ubicaciones;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Catálogo de Equipos de Protección Civil (réplica de pc_equipos_* de
 * SEGCAT): lista por sede, alta y edición, baja y reactivación, código QR y
 * etiqueta para imprimir.
 *
 * Se consulta con «recorridos_pc.ver»; se administra con los permisos de
 * Equipos de seguridad («equipos.crear | editar | eliminar | imprimir»), ver
 * App\Services\RecorridosPc\CatalogoEquiposPc.
 */
class EquipoPcController extends Controller
{
    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly CatalogoEquiposPc $catalogo,
        private readonly Ubicaciones $ubicaciones,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('recorridos_pc.ver');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);

        if ($empresaId === null) {
            return view('seguridad.recorridos-pc.equipos', ['sinEmpresa' => true]);
        }

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $empresaId) {
            $lista = $this->catalogo->limitar(EquipoPc::query(), $actor, 'recorridos_pc.ver')
                ->with('sede:id,nombre')
                ->leftJoin('users as uc', 'uc.id', '=', 'equipos_pc.creado_por')
                ->leftJoin('users as ua', 'ua.id', '=', 'equipos_pc.actualizado_por')
                ->select(['equipos_pc.*', 'uc.name as creado_por_nombre', 'ua.name as actualizado_por_nombre'])
                ->orderBy('equipos_pc.numero_serie')->get();

            $puede = [
                'crear' => $this->catalogo->sedesParaElegir($actor, 'equipos.crear')->isNotEmpty(),
                'editar' => $actor->can('equipos.editar'),
                'baja' => $actor->can('equipos.eliminar'),
                'imprimir' => $actor->can('equipos.imprimir'),
            ];
            $sedesAlta = $puede['crear'] ? $this->catalogo->sedesParaElegir($actor, 'equipos.crear') : collect();
            $sedesEdicion = $puede['editar'] ? $this->catalogo->sedesParaElegir($actor, 'equipos.editar') : collect();
            $sedesFiltro = $lista->pluck('sede')->filter()->unique('id')->sortBy('nombre')->values();
            // Ubicaciones: las de las sedes que se ven o se pueden elegir (una sola consulta)
            $nodos = $this->ubicaciones->nodos($sedesFiltro->pluck('id')->merge($sedesAlta->pluck('id'))->merge($sedesEdicion->pluck('id'))->unique()->values()->all());

            return view('seguridad.recorridos-pc.equipos', [
                'sinEmpresa' => false,
                'equipos' => $lista,
                'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial'),
                'sedesFiltro' => $sedesFiltro,
                'categoriasFiltro' => $lista->pluck('categoria')->unique()->sortBy(fn ($c) => array_search($c, array_keys(EquipoPc::CATEGORIAS), true))->values(),
                'sedesAlta' => $sedesAlta,
                'sedesEdicion' => $sedesEdicion,
                'nodos' => $nodos,
                'editables' => $this->catalogo->idsEnAlcance($actor, 'equipos.editar'),
                'desactivables' => $this->catalogo->idsEnAlcance($actor, 'equipos.eliminar'),
                'siguiente' => session('capturar_siguiente'),
                'puede' => $puede,
            ]);
        });
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('equipos.crear');
        $empresaId = $this->empresaDeTrabajo($request);

        $equipo = $this->tenant->conEmpresa($empresaId, fn () => $this->catalogo->crear($request->user(), $request->all()));

        $respuesta = redirect()->to(route('recorridos_pc.equipos.index').'#equipopc-'.$equipo->id)
            ->with('ok', "Equipo {$equipo->numero_serie} ({$equipo->etiquetaCategoria()}) registrado. Ya puedes imprimir su etiqueta QR.");
        // «Registrar y capturar siguiente»: se vuelve a abrir el alta con la misma sede, tipo y ubicación
        if ($request->boolean('_siguiente')) {
            $respuesta->with('capturar_siguiente', [
                'sede_id' => $equipo->sede_id, 'categoria' => $equipo->categoria,
                'zona_id' => $request->input('zona_id'), 'area_especifica_id' => $request->input('area_especifica_id'),
            ]);
        }

        return $respuesta;
    }

    public function update(Request $request, int $equipo): RedirectResponse
    {
        Gate::authorize('equipos.editar');
        $empresaId = $this->empresaDeTrabajo($request);

        $modelo = $this->tenant->conEmpresa($empresaId, function () use ($request, $equipo) {
            return $this->catalogo->actualizar($request->user(), $this->buscarEnAlcance($request->user(), $equipo, 'equipos.editar'), $request->all());
        });

        return redirect()->to(route('recorridos_pc.equipos.index').'#equipopc-'.$modelo->id)->with('ok', "Equipo {$modelo->numero_serie} actualizado correctamente.");
    }

    public function desactivar(Request $request, int $equipo): RedirectResponse
    {
        Gate::authorize('equipos.eliminar');
        $empresaId = $this->empresaDeTrabajo($request);

        $modelo = $this->tenant->conEmpresa($empresaId, function () use ($request, $equipo) {
            $modelo = $this->buscarEnAlcance($request->user(), $equipo, 'equipos.eliminar');
            $this->catalogo->desactivar($request->user(), $modelo);

            return $modelo;
        });

        return redirect()->to(route('recorridos_pc.equipos.index').'#equipopc-'.$modelo->id)
            ->with('aviso', "Equipo {$modelo->numero_serie} dado de baja: ya no aparece en los recorridos. Puedes reactivarlo con un clic.");
    }

    public function reactivar(Request $request, int $equipo): RedirectResponse
    {
        Gate::authorize('equipos.eliminar');
        $empresaId = $this->empresaDeTrabajo($request);

        $modelo = $this->tenant->conEmpresa($empresaId, function () use ($request, $equipo) {
            $modelo = $this->buscarEnAlcance($request->user(), $equipo, 'equipos.eliminar');
            $this->catalogo->reactivar($request->user(), $modelo);

            return $modelo;
        });

        return redirect()->to(route('recorridos_pc.equipos.index').'#equipopc-'.$modelo->id)
            ->with('ok', "Equipo {$modelo->numero_serie} reactivado: vuelve a aparecer en los recorridos.");
    }

    /**
     * Etiqueta para imprimir (SEGCAT: pc_equipos_ticket.php). El QR se dibuja
     * aquí mismo y solo lleva la dirección /e/{código} (SEGCAT lo pedía a
     * api.qrserver.com con el número de serie).
     */
    public function etiqueta(Request $request, int $equipo): View
    {
        Gate::authorize('equipos.imprimir');
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $equipo) {
            $modelo = $this->buscarEnAlcance($request->user(), $equipo, 'equipos.imprimir');
            $modelo->load(['sede:id,nombre', 'espacio:id,nombre,ruta']);

            return view('seguridad.recorridos-pc.etiqueta', [
                'equipo' => $modelo,
                'empresaNombre' => Empresa::whereKey($modelo->empresa_id)->value('nombre_comercial'),
                'ubicacion' => $modelo->espacio ? $this->ubicaciones->texto($modelo->espacio) : null,
                'qr' => $this->qrSvg($modelo, 200),
                'codigoLegible' => trim(chunk_split($modelo->codigo_qr, 4, ' ')),
            ]);
        });
    }

    /** Imagen del QR para el diálogo «Ver QR» (SVG generado localmente). */
    public function qr(Request $request, int $equipo): Response
    {
        Gate::authorize('recorridos_pc.ver');
        $empresaId = $this->empresaDeTrabajo($request);

        $svg = $this->tenant->conEmpresa($empresaId, fn () => $this->qrSvg($this->buscarEnAlcance($request->user(), $equipo, 'recorridos_pc.ver'), 220, true));

        return response($svg, 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'private, max-age=3600', 'X-Content-Type-Options' => 'nosniff']);
    }

    /**
     * A donde lleva el QR / la etiqueta NFC (lector universal o la cámara del
     * celular): al recorrido En Proceso de su sede, con el equipo listo para
     * inspeccionar (primero uno que inició el propio usuario); si no hay, a su
     * ficha del catálogo.
     */
    public function ir(Request $request, int $equipo, AdministradorRecorridosPc $recorridos): RedirectResponse
    {
        Gate::authorize('recorridos_pc.ver');
        $actor = $request->user();
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $equipo, $recorridos) {
            $modelo = $this->buscarEnAlcance($actor, $equipo, 'recorridos_pc.ver');
            $abierto = $modelo->activo && $actor->can('recorridos_pc.crear')
                ? $recorridos->limitar(RecorridoPc::query(), $actor, 'recorridos_pc.crear')
                    ->where('sede_id', $modelo->sede_id)->where('estatus', RecorridoPc::EN_PROCESO)
                    ->orderByRaw('creado_por = ? desc', [$actor->id])->orderByDesc('updated_at')->first()
                : null;

            return $abierto !== null
                ? redirect()->to(route('recorridos_pc.show', ['recorrido' => $abierto->id, 'equipo' => $modelo->id]).'#punto')
                : redirect()->to(route('recorridos_pc.equipos.index').'#equipopc-'.$modelo->id);
        });
    }

    private function qrSvg(EquipoPc $equipo, int $tamano, bool $conDeclaracion = false): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle($tamano, 1), new SvgImageBackEnd)))->writeString(route('lector.ir', $equipo->codigo_qr));

        // Incrustado en el HTML va sin la declaración XML
        return $conDeclaracion ? $svg : (string) preg_replace('/^<\?xml[^>]*>\s*/', '', $svg);
    }

    private function empresaDeTrabajo(Request $request): int
    {
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        return $empresaId;
    }

    /** Un equipo de otra empresa o fuera del alcance del permiso responde 404. */
    private function buscarEnAlcance(User $actor, int $id, string $permiso): EquipoPc
    {
        $modelo = $this->catalogo->limitar(EquipoPc::query(), $actor, $permiso)->find($id);
        abort_if($modelo === null, 404);

        return $modelo;
    }
}
