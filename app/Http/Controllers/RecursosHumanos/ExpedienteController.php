<?php

namespace App\Http\Controllers\RecursosHumanos;

use App\Http\Controllers\Controller;
use App\Models\Acceso;
use App\Models\Candidato;
use App\Models\CandidatoDocumento;
use App\Models\Colaborador;
use App\Models\PaseSalida;
use App\Models\Postulacion;
use App\Models\PrestamoLlave;
use App\Models\Responsiva;
use App\Models\User;
use App\Services\Candidatos\DocumentosCandidato;
use App\Services\Candidatos\DocumentosParaContratar;
use App\Services\Colaboradores\AdministradorColaboradores;
use App\Services\Procedimientos\AdministradorProcedimientos;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Candidatos, fase 3: expediente del colaborador (la ficha de detalle que
 * faltaba). Reúne en una pantalla lo que la plataforma ya sabe de la
 * persona: sus datos de Colaboradores, su paso por Candidatos (postulación
 * contratada y evaluaciones), los documentos que entregó al contratarse
 * (candidato_documentos.colaborador_id) y resúmenes con enlace de sus
 * accesos recientes, llaves en uso, pases de salida, resguardos y
 * procedimientos por leer.
 *
 * Cada bloque se muestra solo si el usuario puede «ver» ese módulo; los
 * documentos son datos personales: solo con colaboradores.datos_personales
 * (dentro de su alcance); la ficha de candidato, con candidatos.ver.
 * Otra empresa u otra sede → 404.
 */
class ExpedienteController extends Controller
{
    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly AdministradorColaboradores $colaboradores,
        private readonly DocumentosCandidato $documentos,
    ) {}

    public function index(Request $request, int $colaborador): View
    {
        Gate::authorize('colaboradores.ver');
        $empresaId = $this->empresaDeTrabajo($request);
        $actor = $request->user();

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $colaborador, $empresaId) {
            $c = $this->buscar($actor, $colaborador, 'colaboradores.ver');
            $c->load(['sede:id,nombre', 'sedesAdicionales:id,nombre', 'departamento:id,nombre', 'puesto:id,nombre', 'usuario:id,colaborador_id,name,username,activo']);
            $personales = $actor->can('colaboradores.datos_personales')
                && $this->colaboradores->limitar(Colaborador::query(), $actor, 'colaboradores.datos_personales')->whereKey($c->id)->exists();

            // Documentos de contratación (datos personales)
            $documentos = $personales
                ? CandidatoDocumento::where('colaborador_id', $c->id)->with('registradoPor:id,name')->orderBy('tipo')->orderBy('id')->get()
                : collect();

            // Su paso por Candidatos: la postulación contratada y sus evaluaciones (solo con candidatos.ver)
            $ficha = null;
            $postulacion = null;
            if ($actor->can('candidatos.ver')) {
                $ficha = Candidato::where('colaborador_id', $c->id)->orderByDesc('id')->first(['id', 'nombre_completo', 'origen', 'llegada_en', 'created_at']);
                $postulacion = Postulacion::where('colaborador_id', $c->id)->with(['evaluaciones.evaluador:id,name', 'vacantePublicada:id,titulo', 'departamento:id,nombre'])
                    ->orderByDesc('id')->first();
            }

            // Resúmenes operativos: cada uno solo si puede ver ese módulo
            $accesos = $actor->can('accesos.ver')
                ? Acceso::where('colaborador_id', $c->id)->with('sede:id,nombre')->orderByDesc('entrada_at')->limit(5)->get()
                : null;
            $llaves = $actor->can('prestamo_llaves.ver')
                ? PrestamoLlave::where('colaborador_id', $c->id)->where('estado', PrestamoLlave::EN_USO)->where('anulado', false)->with(['llave:id,nomenclatura,descripcion', 'sede:id,nombre'])->orderByDesc('id')->get()
                : null;
            $pases = $actor->can('pases_salida.ver')
                ? PaseSalida::where('colaborador_id', $c->id)->with('sede:id,nombre')->orderByDesc('id')->limit(5)->get()
                : null;
            $responsivas = $actor->can('responsivas.ver')
                ? Responsiva::where('colaborador_id', $c->id)->where('estado', Responsiva::EN_CAMPO)->withCount('equipos')->with('sede:id,nombre')->orderByDesc('id')->get()
                : null;
            $procedimientos = null;
            if ($actor->can('procedimientos.ver') && $c->usuario !== null) {
                $usuario = User::find($c->usuario->id);
                $procedimientos = $usuario !== null && $usuario->activo ? app(AdministradorProcedimientos::class)->pendientesDe($usuario) : collect();
            }

            return view('organizacion.colaboradores.expediente', [
                'c' => $c,
                'personales' => $personales,
                'documentos' => $documentos,
                'nombres' => app(DocumentosParaContratar::class)->nombres($empresaId),
                'ficha' => $ficha,
                'postulacion' => $postulacion,
                'accesos' => $accesos,
                'llaves' => $llaves,
                'pases' => $pases,
                'responsivas' => $responsivas,
                'procedimientos' => $procedimientos,
            ]);
        });
    }

    public function documento(Request $request, int $colaborador, int $documento): StreamedResponse
    {
        Gate::authorize('colaboradores.datos_personales');
        $empresaId = $this->empresaDeTrabajo($request);

        return $this->tenant->conEmpresa($empresaId, function () use ($request, $colaborador, $documento) {
            $c = $this->buscar($request->user(), $colaborador, 'colaboradores.datos_personales');
            $doc = CandidatoDocumento::where('colaborador_id', $c->id)->whereNotNull('ruta')->find($documento);
            abort_if($doc === null, 404);

            return $this->documentos->respuesta($doc->ruta, $doc->nombre_original, ! $doc->esImagen());
        });
    }

    private function buscar(User $actor, int $id, string $permiso): Colaborador
    {
        $c = $this->colaboradores->limitar(Colaborador::query(), $actor, $permiso)->find($id);
        abort_if($c === null, 404);

        return $c;
    }

    private function empresaDeTrabajo(Request $request): int
    {
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        return $empresaId;
    }
}
