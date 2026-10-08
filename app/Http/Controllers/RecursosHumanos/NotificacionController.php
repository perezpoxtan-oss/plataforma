<?php

namespace App\Http\Controllers\RecursosHumanos;

use App\Http\Controllers\Controller;
use App\Models\Notificacion;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Support\Menu\MisPendientes;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Centro de notificaciones (campana): cada usuario ve SOLO las suyas, de su
 * empresa de trabajo. No exige permiso de módulo (como el latido de sesión):
 * son avisos personales; los botones de acción llevan al formulario del
 * módulo, que sí revisa permiso y alcance.
 */
class NotificacionController extends Controller
{
    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly CentroNotificaciones $centro,
    ) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        $lista = $empresaId === null ? null : $this->tenant->conEmpresa($empresaId, fn () => Notificacion::de($actor)
            ->when($request->query('filtro') === 'no_leidas', fn ($q) => $q->noLeidas())
            ->orderByDesc('id')->paginate(25)->withQueryString());

        return view('rh.notificaciones.index', [
            'lista' => $lista,
            'filtro' => $request->query('filtro') === 'no_leidas' ? 'no_leidas' : 'todas',
            'noLeidas' => $empresaId === null ? 0 : $this->tenant->conEmpresa($empresaId, fn () => $this->centro->noLeidas($actor)),
        ]);
    }

    /** JSON pequeño para la campana (se consulta cada 30 s con la pestaña visible). */
    public function resumen(Request $request): JsonResponse
    {
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        if ($empresaId === null) {
            return response()->json(['no_leidas' => 0, 'lista' => [], 'pendientes' => ['total' => 0, 'items' => []]]);
        }

        // Mis pendientes: viaja en la misma consulta de la campana (sin un segundo reloj)
        $pendientes = app(MisPendientes::class)->para($actor, reciente: false);

        return response()->json([...$this->tenant->conEmpresa($empresaId, fn () => $this->centro->resumen($actor)), 'pendientes' => $pendientes]);
    }

    /** Marca como leída y lleva a la pantalla del asunto. */
    public function abrir(Request $request, int $notificacion): RedirectResponse
    {
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        abort_if($empresaId === null, 404);

        $url = $this->tenant->conEmpresa($empresaId, function () use ($actor, $notificacion) {
            $n = Notificacion::de($actor)->find($notificacion);
            abort_if($n === null, 404);
            $this->centro->marcarLeida($n);

            return $n->url;
        });

        // Solo direcciones de la propia plataforma
        $propia = is_string($url) && str_starts_with($url, url('/'));

        return $propia ? redirect()->to($url) : redirect()->route('notificaciones.index');
    }

    public function leerTodas(Request $request): RedirectResponse|JsonResponse
    {
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        $total = $empresaId === null ? 0 : $this->tenant->conEmpresa($empresaId, fn () => $this->centro->marcarTodas($actor));

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'marcadas' => $total]);
        }

        return back()->with('ok', $total === 0 ? 'No tenías notificaciones sin leer.' : 'Listo: marcaste todas como leídas.');
    }
}
