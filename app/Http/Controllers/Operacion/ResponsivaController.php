<?php

namespace App\Http\Controllers\Operacion;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Equipo;
use App\Models\Responsiva;
use App\Models\Sede;
use App\Models\User;
use App\Services\Equipos\AdministradorEquipos;
use App\Services\Firmas\Firmas;
use App\Services\Responsivas\AdministradorResponsivas;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Responsivas y firmas (réplica de modules/equipos/responsivas_lista.php de
 * SEGCAT, "Control de Resguardos"): pestañas Equipos en Campo / Historial
 * Devueltos por lote, "Nuevo Resguardo (Lote)" con firma del colaborador,
 * "Recibir Lote Completo (OK)", ver la firma, la hoja "RESGUARDO MÚLTIPLE DE
 * ACTIVOS DE SEGURIDAD" y el historial de cada equipo.
 *
 * Permisos: responsivas.ver | crear + firmar (nuevo resguardo: la firma es
 * obligatoria) | editar (recibir el lote) | imprimir (hoja). SEGCAT usaba
 * los de equipos.*.
 */
class ResponsivaController extends Controller
{
    /** Lotes del Historial Devueltos en pantalla (SEGCAT: 300 renglones). */
    public const HISTORIAL = 200;

    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly AdministradorResponsivas $responsivas,
        private readonly AdministradorEquipos $equipos,
        private readonly Firmas $firmas,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('responsivas.ver');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);

        if ($empresaId === null) {
            return view('operacion.responsivas.index', ['sinEmpresa' => true]);
        }

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $empresaId) {
            $enCampo = $this->consulta($actor, 'responsivas.ver')->where('responsivas.estado', Responsiva::EN_CAMPO)
                ->orderByDesc('responsivas.entregado_en')->orderByDesc('responsivas.id')->get();
            $devueltas = $this->consulta($actor, 'responsivas.ver')->where('responsivas.estado', Responsiva::DEVUELTA)
                ->orderByDesc('responsivas.devuelto_en')->orderByDesc('responsivas.id')->limit(self::HISTORIAL)->get();
            $todas = $enCampo->concat($devueltas);

            $puedeCrear = $actor->can('responsivas.crear') && $actor->can('responsivas.firmar');
            $sedesCrear = $puedeCrear ? $this->responsivas->sedesParaCrear($actor) : collect();

            return view('operacion.responsivas.index', [
                'sinEmpresa' => false,
                'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial'),
                'enCampo' => $enCampo,
                'devueltas' => $devueltas,
                'sedesFiltro' => $todas->pluck('sede')->filter()->unique('id')->sortBy('nombre')->values(),
                'sedesCrear' => $sedesCrear,
                'sedeSugerida' => $this->sedeSugerida($actor, $sedesCrear),
                // Equipos DISPONIBLES de las sedes del formulario, para elegir de la lista
                'disponibles' => $sedesCrear->isEmpty() ? collect() : $this->equipos->limitar(Equipo::query(), $actor, 'equipos.ver')
                    ->with('tipo:id,nombre')->where('estado', 'disponible')->whereIn('sede_id', $sedesCrear->pluck('id'))
                    ->orderBy('numero_serie')->get(['id', 'sede_id', 'tipo_equipo_id', 'marca', 'modelo', 'numero_serie']),
                'recibibles' => $this->responsivas->idsEnAlcance($actor, 'responsivas.editar', $enCampo),
                'imprimibles' => $this->responsivas->idsEnAlcance($actor, 'responsivas.imprimir', $todas),
                'colaboradorAnterior' => old('colaborador_id') ? Colaborador::whereKey((int) old('colaborador_id'))->first(['id', 'num_empleado', 'nombre', 'apellido_paterno', 'apellido_materno']) : null,
                'puede' => [
                    'crear' => $puedeCrear && $sedesCrear->isNotEmpty(),
                    'recibir' => $actor->can('responsivas.editar'),
                    'imprimir' => $actor->can('responsivas.imprimir'),
                ],
            ]);
        });
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('responsivas.crear');
        Gate::authorize('responsivas.firmar');
        $empresaId = $this->empresaDeTrabajo($request);

        $responsiva = $this->tenant->conEmpresa($empresaId, fn () => $this->responsivas->crear($request->user(), $request->all()));
        $total = $responsiva->equipos()->count();

        return redirect()->to(route('responsivas.index').'#responsiva-'.$responsiva->id)
            ->with('ok', "Resguardo {$responsiva->folio} guardado con {$total} ".($total === 1 ? 'equipo' : 'equipos').'. Ya puedes imprimir la hoja.');
    }

    /**
     * "Recibir Lote Completo (OK)".
     */
    public function recibir(Request $request, int $responsiva): RedirectResponse
    {
        Gate::authorize('responsivas.editar');
        $empresaId = $this->empresaDeTrabajo($request);

        [$modelo, $regresaron, $total] = $this->tenant->conEmpresa($empresaId, function () use ($request, $responsiva) {
            $modelo = $this->buscarEnAlcance($request->user(), $responsiva, 'responsivas.editar');
            $total = $modelo->equipos()->count();

            return [$modelo, $this->responsivas->recibir($request->user(), $modelo), $total];
        });

        $nota = $regresaron < $total ? ' '.($total - $regresaron).' ya estaba(n) dado(s) de baja y se quedan así.' : '';

        return redirect()->to(route('responsivas.index').'#responsiva-'.$modelo->id)
            ->with('ok', "Lote {$modelo->folio} recibido: {$regresaron} ".($regresaron === 1 ? 'equipo vuelve' : 'equipos vuelven').' a DISPONIBLE.'.$nota);
    }

    /**
     * Imagen de la firma (disco privado). Solo con permiso y dentro de las
     * sedes del usuario; nunca desde una dirección pública.
     */
    public function firma(Request $request, int $responsiva): StreamedResponse
    {
        Gate::authorize('responsivas.ver');
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $responsiva) {
            $modelo = $this->buscarEnAlcance($request->user(), $responsiva, 'responsivas.ver');

            return $this->firmas->respuesta($modelo->firma_ruta);
        });
    }

    /**
     * Hoja "RESGUARDO MÚLTIPLE DE ACTIVOS DE SEGURIDAD" (SEGCAT:
     * ticket_responsiva.php), con folio y firma.
     */
    public function hoja(Request $request, int $responsiva): View
    {
        Gate::authorize('responsivas.imprimir');
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $responsiva, $empresaId) {
            $modelo = $this->buscarEnAlcance($request->user(), $responsiva, 'responsivas.imprimir');
            $modelo->load(['sede:id,nombre', 'colaborador:id,num_empleado,nombre,apellido_paterno,apellido_materno,puesto_id', 'colaborador.puesto:id,nombre',
                'entrego:id,name', 'recibio:id,name', 'equipos.equipo:id,tipo_equipo_id,marca,modelo,numero_serie', 'equipos.equipo.tipo:id,nombre']);

            return view('operacion.responsivas.hoja', [
                'responsiva' => $modelo,
                'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial'),
            ]);
        });
    }

    /**
     * Historial de resguardos de un equipo (SEGCAT: equipo_historial_ajax.php).
     */
    public function historial(Request $request, int $equipo): View
    {
        Gate::authorize('responsivas.ver');
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $equipo) {
            $sedes = $this->responsivas->sedes($request->user(), 'responsivas.ver');
            $modelo = Equipo::with('tipo:id,nombre')->when($sedes !== null, fn ($q) => $q->whereIn('sede_id', $sedes))->find($equipo);
            abort_if($modelo === null, 404);

            return view('operacion.responsivas._historial', ['equipo' => $modelo, 'movimientos' => $this->responsivas->historialDe($modelo, $request->user())]);
        });
    }

    /**
     * @return Builder<Responsiva>
     */
    private function consulta(User $actor, string $permiso): Builder
    {
        return $this->responsivas->limitar(Responsiva::query(), $actor, $permiso)->with([
            'sede:id,nombre', 'colaborador:id,num_empleado,nombre,apellido_paterno,apellido_materno',
            'entrego:id,name', 'recibio:id,name',
            'equipos:id,responsiva_id,equipo_id,modalidad,estado_devolucion,devuelto_en',
            'equipos.equipo:id,tipo_equipo_id,marca,modelo,numero_serie,estado', 'equipos.equipo.tipo:id,nombre',
        ]);
    }

    /**
     * @param  Collection<int, Sede>  $sedes
     */
    private function sedeSugerida(User $actor, Collection $sedes): ?int
    {
        if ($sedes->count() === 1) {
            return (int) $sedes->first()->id;
        }
        $colaboradorId = User::whereKey($actor->id)->value('colaborador_id');
        $propia = $colaboradorId ? Colaborador::whereKey($colaboradorId)->value('sede_id') : null;

        return $propia !== null && $sedes->contains('id', (int) $propia) ? (int) $propia : null;
    }

    /**
     * Un resguardo de otra empresa o fuera del alcance del permiso responde 404.
     */
    private function buscarEnAlcance(User $actor, int $id, string $permiso): Responsiva
    {
        $modelo = $this->responsivas->limitar(Responsiva::query(), $actor, $permiso)->find($id);
        abort_if($modelo === null, 404);

        return $modelo;
    }

    private function empresaDeTrabajo(Request $request): int
    {
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        return $empresaId;
    }
}
