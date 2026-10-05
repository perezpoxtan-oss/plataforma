<?php

namespace App\Http\Controllers\Seguridad;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Equipo;
use App\Models\TipoEquipo;
use App\Models\User;
use App\Services\Equipos\AdministradorEquipos;
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
 * Equipos de seguridad (réplica de modules/equipos de SEGCAT, sin
 * Responsivas): radios, lámparas, detectores y demás equipo prestable de la
 * guardia, por sede, con su etiqueta QR / NFC y la baja con voucher.
 */
class EquipoController extends Controller
{
    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly AdministradorEquipos $equipos,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('equipos.ver');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);

        if ($empresaId === null) {
            return view('seguridad.equipos.index', ['sinEmpresa' => true]);
        }

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $empresaId) {
            $lista = $this->equipos->limitar(Equipo::query(), $actor, 'equipos.ver')
                ->with(['tipo:id,nombre', 'sede:id,nombre'])
                ->leftJoin('users as uc', 'uc.id', '=', 'equipos.creado_por')
                ->leftJoin('users as ua', 'ua.id', '=', 'equipos.actualizado_por')
                ->select(['equipos.*', 'uc.name as creado_por_nombre', 'ua.name as actualizado_por_nombre'])
                ->orderBy('equipos.numero_serie')
                ->get()
                // SEGCAT: ordenado por tipo y número de serie
                ->sortBy(fn (Equipo $e) => mb_strtolower($e->tipo->nombre ?? ''), SORT_STRING)->values();

            $puede = [
                'crear' => $actor->can('equipos.crear'),
                'editar' => $actor->can('equipos.editar'),
                'baja' => $actor->can('equipos.eliminar'),
                'imprimir' => $actor->can('equipos.imprimir'),
            ];
            $conFormulario = $puede['crear'] || $puede['editar'];

            return view('seguridad.equipos.index', [
                'sinEmpresa' => false,
                'equipos' => $lista,
                'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial'),
                // Filtro de sede: solo las sedes que tienen equipos visibles
                'sedesFiltro' => $lista->pluck('sede')->filter()->unique('id')->sortBy('nombre')->values(),
                'tiposFiltro' => $lista->pluck('tipo')->filter()->unique('id')->sortBy('nombre')->values(),
                'sedesAlta' => $puede['crear'] ? $this->equipos->sedesParaElegir($actor, 'equipos.crear') : collect(),
                'sedesEdicion' => $puede['editar'] ? $this->equipos->sedesParaElegir($actor, 'equipos.editar') : collect(),
                // Activos para elegir; los inactivos solo para conservar el valor de una edición
                'tipos' => $conFormulario ? TipoEquipo::orderBy('nombre')->get(['id', 'nombre', 'activo']) : collect(),
                'sugerencias' => $conFormulario ? $this->equipos->sugerencias() : ['marcas' => [], 'modelos' => [], 'costos' => []],
                // Monto sugerido del voucher de cada equipo (una sola consulta)
                'costosVoucher' => $puede['baja'] ? $this->equipos->costosDeVouchers() : [],
                // Tras un error en la baja, el responsable que ya se había elegido
                'bajaResponsable' => $this->responsableAnterior(),
                'editables' => $this->equipos->idsEnAlcance($actor, 'equipos.editar'),
                'desactivables' => $this->equipos->idsEnAlcance($actor, 'equipos.eliminar'),
                'puede' => $puede,
            ]);
        });
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('equipos.crear');
        $empresaId = $this->empresaDeTrabajo($request);

        $equipo = $this->tenant->conEmpresa($empresaId, fn () => $this->equipos->crear($request->user(), $request->all()));

        return redirect()->to(route('equipos.index').'#equipo-'.$equipo->id)
            ->with('ok', "Equipo {$equipo->numero_serie} registrado correctamente. Ya puedes imprimir su etiqueta QR.");
    }

    public function update(Request $request, int $equipo): RedirectResponse
    {
        Gate::authorize('equipos.editar');
        $empresaId = $this->empresaDeTrabajo($request);

        $modelo = $this->tenant->conEmpresa($empresaId, function () use ($request, $equipo) {
            $modelo = $this->buscarEnAlcance($request->user(), $equipo, 'equipos.editar');

            return $this->equipos->actualizar($request->user(), $modelo, $request->all());
        });

        return redirect()->to(route('equipos.index').'#equipo-'.$modelo->id)->with('ok', "Equipo {$modelo->numero_serie} actualizado correctamente.");
    }

    /**
     * Baja con voucher (Extraviado | Dañado | Robado).
     */
    public function baja(Request $request, int $equipo): RedirectResponse
    {
        Gate::authorize('equipos.eliminar');
        $empresaId = $this->empresaDeTrabajo($request);

        [$modelo, $voucher] = $this->tenant->conEmpresa($empresaId, function () use ($request, $equipo) {
            $modelo = $this->buscarEnAlcance($request->user(), $equipo, 'equipos.eliminar');

            return [$modelo, $this->equipos->darDeBaja($request->user(), $modelo, $request->all())];
        });

        return redirect()->to(route('equipos.index').'#equipo-'.$modelo->id)
            ->with('aviso', "Equipo {$modelo->numero_serie} dado de baja con el voucher {$voucher->folio}"
                .($voucher->aplica_cobro ? ' (con cobro de $'.number_format((float) $voucher->monto, 2).')' : '')
                .'. Puedes reactivarlo con un clic si aparece.');
    }

    /**
     * Reactivar (se encontró / se recuperó): vuelve a DISPONIBLE; el voucher se conserva.
     */
    public function reactivar(Request $request, int $equipo): RedirectResponse
    {
        Gate::authorize('equipos.eliminar');
        $empresaId = $this->empresaDeTrabajo($request);

        $modelo = $this->tenant->conEmpresa($empresaId, function () use ($request, $equipo) {
            $modelo = $this->buscarEnAlcance($request->user(), $equipo, 'equipos.eliminar');
            $this->equipos->reactivar($request->user(), $modelo);

            return $modelo;
        });

        return redirect()->to(route('equipos.index').'#equipo-'.$modelo->id)
            ->with('ok', "Equipo {$modelo->numero_serie} reactivado: ya vuelve a estar DISPONIBLE.");
    }

    /**
     * Etiqueta para imprimir (SEGCAT: equipo_ticket.php). El QR se dibuja aquí
     * mismo y solo lleva la dirección /e/{código}: SEGCAT lo pedía a
     * api.qrserver.com (un tercero) y sin internet salía sin QR.
     */
    public function etiqueta(Request $request, int $equipo): View
    {
        Gate::authorize('equipos.imprimir');
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $equipo) {
            $modelo = $this->equipos->limitar(Equipo::query(), $request->user(), 'equipos.imprimir')
                ->with(['tipo:id,nombre', 'sede:id,nombre'])->find($equipo);
            abort_if($modelo === null, 404);

            return view('seguridad.equipos.etiqueta', [
                'equipo' => $modelo,
                'empresaNombre' => Empresa::whereKey($modelo->empresa_id)->value('nombre_comercial'),
                'qr' => $this->qrSvg($modelo, 200),
                'url' => route('lector.ir', $modelo->codigo_qr),
                'codigoLegible' => trim(chunk_split($modelo->codigo_qr, 4, ' ')),
            ]);
        });
    }

    /**
     * Imagen del QR para el diálogo "Ver QR" (SVG generado localmente).
     */
    public function qr(Request $request, int $equipo): Response
    {
        Gate::authorize('equipos.ver');
        $empresaId = $this->empresaDeTrabajo($request);

        $svg = $this->tenant->conEmpresa($empresaId, function () use ($request, $equipo) {
            $modelo = $this->equipos->limitar(Equipo::query(), $request->user(), 'equipos.ver')->find($equipo);
            abort_if($modelo === null, 404);

            return $this->qrSvg($modelo, 220, true);
        });

        return response($svg, 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'private, max-age=3600', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function qrSvg(Equipo $equipo, int $tamano, bool $conDeclaracion = false): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle($tamano, 1), new SvgImageBackEnd)))->writeString(route('lector.ir', $equipo->codigo_qr));

        // Incrustado en el HTML va sin la declaración XML
        return $conDeclaracion ? $svg : (string) preg_replace('/^<\?xml[^>]*>\s*/', '', $svg);
    }

    /**
     * Nombre del responsable capturado antes de un error de validación en la baja.
     */
    private function responsableAnterior(): ?string
    {
        $dialogo = old('_dialogo');
        $id = old('colaborador_id');
        if (! is_string($dialogo) || ! str_starts_with($dialogo, 'baja-') || ! is_numeric($id)) {
            return null;
        }

        return Colaborador::find((int) $id)?->nombreCompleto();
    }

    private function empresaDeTrabajo(Request $request): int
    {
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        return $empresaId;
    }

    /**
     * Un equipo de otra empresa o fuera del alcance del permiso responde 404.
     */
    private function buscarEnAlcance(User $actor, int $id, string $permiso): Equipo
    {
        $modelo = $this->equipos->limitar(Equipo::query(), $actor, $permiso)->find($id);
        abort_if($modelo === null, 404);

        return $modelo;
    }
}
