<?php

namespace App\Http\Controllers\Seguridad;

use App\Http\Controllers\Controller;
use App\Models\Novedad;
use App\Models\User;
use App\Services\Novedades\CanalizarDepartamento;
use App\Services\Relojes\MotorRelojes;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * C-28 (DD-15): «Tickets de mi departamento». Quien responde por un
 * departamento en la sede (la cobertura de la Etapa 3, o su delegado) ve
 * SOLO los tickets canalizados a ese departamento: los lee, agrega notas al
 * Minuto a Minuto y «Atender» marca su parte. No necesita permisos de la
 * Bitácora de novedades (la regla es de cobertura, no de rol).
 */
class NovedadDepartamentoController extends Controller
{
    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly CanalizarDepartamento $departamentos,
    ) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $empresaId = $this->empresaDe($actor);

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $empresaId) {
            abort_if($this->departamentos->pares($actor) === [] && ! $actor->es_superadmin, 403);
            $base = $actor->es_superadmin ? Novedad::query()->whereNotNull('departamento_id') : $this->departamentos->limitar(Novedad::query(), $actor);
            $tickets = $base->with(['sede:id,nombre', 'departamento:id,nombre', 'departamentoAtendidoPor:id,name'])
                ->orderByRaw('CASE WHEN departamento_atendido_en IS NULL AND estatus != ? THEN 0 ELSE 1 END', [Novedad::RESUELTO])
                ->orderByDesc('departamento_en')->limit(200)->get();
            $motor = app(MotorRelojes::class);

            return view('seguridad.novedades.departamento', [
                'tickets' => $tickets,
                'niveles' => $tickets->mapWithKeys(fn (Novedad $n) => [$n->id => $n->departamento_atendido_en === null && ! $n->resuelto()
                    ? $motor->nivel('novedades_departamento', $n->departamento_en, $empresaId) : null])->all(),
            ]);
        });
    }

    public function show(Request $request, int $novedad): View
    {
        $actor = $request->user();
        $empresaId = $this->empresaDe($actor);

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $novedad) {
            $n = $this->buscar($actor, $novedad);
            $n->load(['sede:id,nombre,zona_horaria', 'departamento:id,nombre', 'departamentoAtendidoPor:id,name', 'creador:id,name', 'asignado:id,name', 'notas']);

            return view('seguridad.novedades.departamento-ticket', ['n' => $n, 'puedeAtender' => ! $n->resuelto() && $n->departamento_atendido_en === null]);
        });
    }

    public function nota(Request $request, int $novedad): RedirectResponse
    {
        $datos = $request->validate(['nota' => ['required', 'string', 'max:2000']], ['nota.required' => 'Escribe la nota.']);
        $empresaId = $this->empresaDe($request->user());
        $this->tenant->conEmpresa($empresaId, fn () => $this->departamentos->anotar($request->user(), $this->buscar($request->user(), $novedad), $datos['nota']));

        return redirect()->route('novedades.departamento.show', $novedad)->with('ok', 'Nota guardada en el Minuto a Minuto. Seguridad ya la ve.');
    }

    public function atender(Request $request, int $novedad): RedirectResponse
    {
        $datos = $request->validate(['nota' => ['nullable', 'string', 'max:2000']]);
        $empresaId = $this->empresaDe($request->user());
        $folio = $this->tenant->conEmpresa($empresaId, function () use ($request, $novedad, $datos) {
            $n = $this->buscar($request->user(), $novedad);
            $this->departamentos->atender($request->user(), $n, $datos['nota'] ?? null);

            return $n->folio();
        });

        return redirect()->route('novedades.departamento.index')->with('ok', "Listo: atendiste tu parte del ticket {$folio}. Se avisó a Seguridad. ¿Otro? Están en esta lista.");
    }

    private function buscar(User $actor, int $id): Novedad
    {
        $n = $actor->es_superadmin ? Novedad::whereNotNull('departamento_id')->find($id) : $this->departamentos->limitar(Novedad::query(), $actor)->find($id);
        abort_if($n === null, 404);

        return $n;
    }

    private function empresaDe(User $actor): int
    {
        $empresaId = $this->empresa->id($actor);
        abort_if($empresaId === null, 404);

        return $empresaId;
    }
}
