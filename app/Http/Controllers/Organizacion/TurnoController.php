<?php

namespace App\Http\Controllers\Organizacion;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Organizacion\Concerns\CatalogoDeEmpresa;
use App\Models\Empresa;
use App\Models\Sede;
use App\Models\Turno;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Autorizador;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Turnos (réplica de modules/turnos de SEGCAT): horarios corporativos
 * predefinidos y las sedes que usan cada uno.
 *
 * El catálogo (alta, edición, desactivar) es de toda la empresa. La
 * asignación de sedes ("Sedes que usan este turno") la hace cualquiera con
 * turnos.editar, pero quien tiene alcance de sede solo marca o desmarca sus
 * propias sedes: las demás se conservan tal como estaban.
 */
class TurnoController extends Controller
{
    use CatalogoDeEmpresa;

    private const HORA = '/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/';

    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly Autorizador $autorizadorPermisos,
        private readonly AdministradorRoles $auditoria,
    ) {}

    protected function autorizador(): Autorizador
    {
        return $this->autorizadorPermisos;
    }

    public function index(Request $request): View
    {
        Gate::authorize('turnos.ver');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);

        if ($empresaId === null) {
            return view('organizacion.turnos.index', ['sinEmpresa' => true]);
        }

        $permitidas = $this->autorizador()->sedesPermitidas($actor, 'turnos.ver');
        $editables = $actor->can('turnos.editar') ? $this->autorizador()->sedesPermitidas($actor, 'turnos.editar') : [];
        $puede = $this->permisosCatalogo($actor, 'turnos') + [
            'sedes' => $editables !== [],
            'todasLasSedes' => $editables === null,
        ];

        return $this->tenant->conEmpresa($empresaId, function () use ($empresaId, $permitidas, $editables, $puede) {
            $sedes = Sede::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']);
            // Con alcance de sede se ven los turnos que usa su sede; quien puede
            // asignar ve todos, para poder activar en su sede uno que aún no usa.
            $filtrar = $permitidas !== null && $editables === [];

            return view('organizacion.turnos.index', [
                'sinEmpresa' => false,
                'turnos' => Turno::with(['sedes' => fn ($q) => $q->select('sedes.id', 'sedes.nombre', 'sedes.activo')->orderBy('sedes.nombre')])
                    ->when($filtrar, fn ($q) => $q->aplicanEn($permitidas))
                    ->leftJoin('users as uc', 'uc.id', '=', 'turnos.creado_por')
                    ->leftJoin('users as ua', 'ua.id', '=', 'turnos.actualizado_por')
                    ->select('turnos.*', 'uc.name as creado_por_nombre', 'ua.name as actualizado_por_nombre')
                    ->orderBy('turnos.hora_inicio')->orderBy('turnos.nombre')
                    ->get(),
                'sedes' => $sedes,
                // Sedes que este usuario puede (des)asignar en el diálogo de sedes
                'sedesAsignables' => $editables === null ? $sedes : $sedes->whereIn('id', $editables ?? [])->values(),
                'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial'),
                'puede' => $puede,
            ]);
        });
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('turnos.crear');
        $this->exigirAlcanceDeEmpresa($request->user(), 'turnos.crear');
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        [$turno, $foto] = $this->tenant->conEmpresa($empresaId, function () use ($request, $empresaId) {
            $datos = $this->validar($request, $empresaId);
            [$todas, $sedes] = $this->validarSedes($request, true);
            $turno = DB::transaction(function () use ($datos, $todas, $sedes) {
                $turno = Turno::create($datos + ['todas_las_sedes' => $todas]);
                $turno->sedes()->sync($sedes);

                return $turno;
            });

            return [$turno, $this->foto($turno)];
        });

        $this->auditoria->auditar($request->user(), 'turnos.creado', $turno, null, $foto);

        return redirect()->route('turnos.index')->with('ok', "Turno «{$turno->nombre}» creado correctamente.");
    }

    public function update(Request $request, int $turno): RedirectResponse
    {
        Gate::authorize('turnos.editar');
        $this->exigirAlcanceDeEmpresa($request->user(), 'turnos.editar');
        [$modelo, $empresaId] = $this->buscar($request, $turno);

        [$antes, $despues] = $this->tenant->conEmpresa($empresaId, function () use ($request, $empresaId, $modelo) {
            $antes = $this->foto($modelo);
            $modelo->fill($this->validar($request, $empresaId, $modelo))->save();

            return [$antes, $this->foto($modelo)];
        });

        $this->auditoria->auditar($request->user(), 'turnos.actualizado', $modelo, $antes, $despues);

        return redirect()->route('turnos.index')->with('ok', "Turno «{$modelo->nombre}» actualizado correctamente.");
    }

    /**
     * "Sedes que usan este turno". Con alcance de empresa se elige libremente;
     * con alcance de sede solo cambia la casilla de sus propias sedes.
     */
    public function sedes(Request $request, int $turno): RedirectResponse
    {
        Gate::authorize('turnos.editar');
        $editables = $this->autorizador()->sedesPermitidas($request->user(), 'turnos.editar');
        abort_if($editables === [], 403);
        [$modelo, $empresaId] = $this->buscar($request, $turno);

        [$antes, $despues] = $this->tenant->conEmpresa($empresaId, function () use ($request, $modelo, $editables) {
            $antes = $this->foto($modelo);
            [$todas, $sedes] = $editables === null
                ? $this->validarSedes($request, false, $modelo)
                : $this->soloMisSedes($request, $modelo, $editables);

            DB::transaction(function () use ($modelo, $todas, $sedes) {
                $modelo->forceFill(['todas_las_sedes' => $todas])->save();
                $modelo->sedes()->sync($sedes);
            });

            return [$antes, $this->foto($modelo)];
        });

        if ($antes !== $despues) {
            $this->auditoria->auditar($request->user(), 'turnos.sedes_actualizadas', $modelo, $antes, $despues);
        }

        return redirect()->route('turnos.index')->with('ok', "Sedes del turno «{$modelo->nombre}» actualizadas.");
    }

    public function estado(Request $request, int $turno): RedirectResponse
    {
        Gate::authorize('turnos.eliminar');
        $this->exigirAlcanceDeEmpresa($request->user(), 'turnos.eliminar');
        [$modelo, $empresaId] = $this->buscar($request, $turno);

        $activo = $request->boolean('activo');
        $this->tenant->conEmpresa($empresaId, fn () => $modelo->forceFill(['activo' => $activo])->save());
        $this->auditoria->auditar($request->user(), $activo ? 'turnos.reactivado' : 'turnos.desactivado', $modelo, ['activo' => ! $activo], ['activo' => $activo]);

        return redirect()->route('turnos.index')->with($activo ? 'ok' : 'aviso', $activo
            ? "Turno «{$modelo->nombre}» reactivado."
            : "Turno «{$modelo->nombre}» desactivado. Puedes reactivarlo con el mismo botón cuando quieras.");
    }

    /**
     * @return array{0: Turno, 1: int}
     */
    private function buscar(Request $request, int $id): array
    {
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        $modelo = $this->tenant->conEmpresa($empresaId, fn () => Turno::find($id));
        abort_if($modelo === null, 404);

        return [$modelo, $empresaId];
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, int $empresaId, ?Turno $turno = null): array
    {
        $request->merge(['nombre' => $this->normalizarNombre($request->input('nombre'))]);

        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:50', $this->nombreLibre('turnos', $empresaId, $turno?->id, 'Ya existe un turno con ese nombre en esta empresa.')],
            'hora_inicio' => ['required', 'string', 'regex:'.self::HORA],
            'hora_fin' => ['required', 'string', 'regex:'.self::HORA],
        ], [
            'hora_inicio.regex' => 'Escribe la hora de inicio como HH:MM (24 horas).',
            'hora_fin.regex' => 'Escribe la hora de fin como HH:MM (24 horas).',
        ], ['hora_inicio' => 'hora de inicio', 'hora_fin' => 'hora de fin']);

        $inicio = substr($datos['hora_inicio'], 0, 5);
        $fin = substr($datos['hora_fin'], 0, 5);
        if ($inicio === $fin) {
            throw ValidationException::withMessages(['hora_fin' => 'La hora de fin debe ser distinta a la de inicio.']);
        }

        return ['nombre' => $datos['nombre'], 'hora_inicio' => $inicio.':00', 'hora_fin' => $fin.':00'];
    }

    /**
     * Alcance de empresa: "todas las sedes" o la lista elegida (solo sedes
     * activas de esta empresa). En el alta se exige al menos una; en el
     * diálogo de sedes se permite dejar el turno sin sedes, como en SEGCAT.
     * Las sedes desactivadas no salen en la lista: se conservan sus ligas.
     *
     * @return array{0: bool, 1: list<int>}
     */
    private function validarSedes(Request $request, bool $exigirUna, ?Turno $turno = null): array
    {
        $todas = $request->boolean('todas_las_sedes', $exigirUna);
        $datos = $request->validate([
            'todas_las_sedes' => ['boolean'],
            'sedes' => ['nullable', 'array'],
            'sedes.*' => ['integer'],
        ]);

        if ($todas) {
            return [true, []];
        }

        $sedes = Sede::where('activo', true)->whereIn('id', array_map('intval', $datos['sedes'] ?? []))->pluck('id')->all();
        if ($exigirUna && $sedes === []) {
            throw ValidationException::withMessages(['sedes' => 'Marca al menos una sede o elige «Todas las sedes».']);
        }
        if ($turno !== null) {
            $sedes = [...$sedes, ...$turno->sedes()->where('sedes.activo', false)->pluck('sedes.id')->all()];
        }

        return [false, array_values(array_unique($sedes))];
    }

    /**
     * Alcance de sede: solo cambia la casilla de sus propias sedes; las de
     * las demás sedes quedan como estaban. Si el turno era de "todas las
     * sedes" y quita la suya, pasa a lista explícita con el resto.
     *
     * @param  list<int>  $mias
     * @return array{0: bool, 1: list<int>}
     */
    private function soloMisSedes(Request $request, Turno $turno, array $mias): array
    {
        $request->validate(['sedes' => ['nullable', 'array'], 'sedes.*' => ['integer']]);
        $mias = Sede::where('activo', true)->whereIn('id', $mias)->pluck('id')->all();
        $marcadas = array_values(array_intersect($mias, array_map('intval', (array) $request->input('sedes', []))));
        $desmarcadas = array_values(array_diff($mias, $marcadas));

        if ($turno->todas_las_sedes) {
            if ($desmarcadas === []) {
                return [true, []];
            }
            $base = Sede::pluck('id')->all();
        } else {
            $base = [...$turno->sedes()->pluck('sedes.id')->all(), ...$marcadas];
        }

        return [false, array_values(array_unique(array_diff($base, $desmarcadas)))];
    }

    /**
     * @return array<string, mixed>
     */
    private function foto(Turno $t): array
    {
        return $t->only(['nombre', 'hora_inicio', 'hora_fin', 'todas_las_sedes', 'activo'])
            + ['sedes' => $t->sedes()->orderBy('sedes.id')->pluck('sedes.id')->all()];
    }
}
