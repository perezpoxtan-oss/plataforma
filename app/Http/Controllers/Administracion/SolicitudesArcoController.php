<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\SolicitudArco;
use App\Models\User;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Autorizador;
use App\Services\Privacidad\ConservacionDatos;
use App\Services\Privacidad\DatosDePersona;
use App\Services\Privacidad\SolicitudesArco;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\EmpresaScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bandeja de solicitudes ARCO (docs/tecnico/privacidad.md). La declara la
 * acción «configuracion.datos_personales» con alcance de empresa (el
 * Administrador). El Super Administrador ve además las que llegaron desde la
 * página de la plataforma y las canaliza.
 */
class SolicitudesArcoController extends Controller
{
    public function __construct(
        private readonly SolicitudesArco $solicitudes,
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Autorizador $autorizador,
    ) {}

    public function index(Request $request): View
    {
        $actor = $this->exigirPermiso($request);
        $empresaId = $this->empresa->id($actor);
        $plataforma = $actor->es_superadmin && $request->boolean('plataforma');
        $filtro = in_array($request->query('estado'), ['abiertas', 'atendidas', 'todas'], true) ? $request->query('estado') : 'abiertas';
        $consulta = $this->solicitudes->consulta($actor, $empresaId, $plataforma)
            ->when($filtro === 'abiertas', fn ($q) => $q->whereIn('estado', [SolicitudArco::POR_VERIFICAR, SolicitudArco::PENDIENTE]))
            ->when($filtro === 'atendidas', fn ($q) => $q->whereIn('estado', [SolicitudArco::ATENDIDA, SolicitudArco::RECHAZADA]));
        $empresa = $empresaId ? Empresa::find($empresaId) : null;

        return view('administracion.arco.index', [
            'solicitudes' => $consulta->orderByRaw("CASE estado WHEN 'pendiente' THEN 0 WHEN 'por_verificar' THEN 1 ELSE 2 END")->orderBy('vence_en')->orderByDesc('id')->paginate(25)->withQueryString(),
            'filtro' => $filtro,
            'plataforma' => $plataforma,
            'esSuperadmin' => $actor->es_superadmin,
            'deLaPlataforma' => $actor->es_superadmin ? ConservacionDatos::arcoDePlataforma() : 0,
            'recordar' => $empresa ? app(ConservacionDatos::class)->ajustes($empresa)['arco_recordar_dias'] : ConservacionDatos::RECORDAR_ARCO,
            'empresaNombre' => $empresa?->nombre_comercial,
        ]);
    }

    public function show(Request $request, int $solicitud): View
    {
        $actor = $this->exigirPermiso($request);
        $s = $this->buscar($actor, $solicitud);
        $criterios = [
            'nombre' => (string) $request->query('nombre', $s->nombre),
            'correo' => (string) $request->query('correo', $s->correo),
            'telefono' => (string) $request->query('telefono', (string) $s->telefono),
            'folio' => (string) $request->query('folio', ''),
        ];
        $empresa = $s->empresa_id ? Empresa::find($s->empresa_id) : null;

        return view('administracion.arco.show', [
            's' => $s,
            'criterios' => $criterios,
            'coincidencias' => $s->estado === SolicitudArco::PENDIENTE ? $this->solicitudes->buscarDatos($s, $criterios) : null,
            'tipos' => DatosDePersona::TIPOS,
            'recordar' => $empresa ? app(ConservacionDatos::class)->ajustes($empresa)['arco_recordar_dias'] : ConservacionDatos::RECORDAR_ARCO,
            'empresaNombre' => $empresa?->nombre_comercial,
            'empresas' => $actor->es_superadmin && $s->empresa_id === null ? Empresa::where('activo', true)->orderBy('nombre_comercial')->get(['id', 'nombre_comercial']) : collect(),
            'esSuperadmin' => $actor->es_superadmin,
        ]);
    }

    public function verificar(Request $request, int $solicitud): RedirectResponse
    {
        $actor = $this->exigirPermiso($request);
        $s = $this->buscar($actor, $solicitud);
        $this->solicitudes->aprobarVerificacion($actor, $s);

        return redirect()->route('arco.show', $s->id)->with('ok', 'Solicitud verificada: el plazo de '.SolicitudesArco::DIAS_HABILES.' días hábiles empezó hoy. Ahora busca los datos de la persona aquí abajo.');
    }

    public function exportar(Request $request, int $solicitud): RedirectResponse
    {
        $actor = $this->exigirPermiso($request);
        $s = $this->buscar($actor, $solicitud);
        $seleccion = (array) $request->input('seleccion', []);
        $this->solicitudes->exportar($actor, $s, $seleccion);

        // Regresa con la misma búsqueda y lo marcado: el botón «Descargar el archivo» queda a la vista
        return redirect()->route('arco.show', [$s->id] + $this->criteriosDe($request))
            ->with('ok', 'Listo: el archivo con los datos está listo. Descárgalo y entrégalo a la persona; después escribe tu respuesta.')
            ->with('arco_marcados', $seleccion);
    }

    public function descargar(Request $request, int $solicitud): StreamedResponse
    {
        $actor = $this->exigirPermiso($request);
        $s = $this->buscar($actor, $solicitud);
        abort_if($s->archivo === null || ! Storage::disk('local')->exists($s->archivo), 404);
        app(AdministradorRoles::class)->auditar($actor, 'privacidad.arco_descargado', $s, null, ['folio' => $s->folio]);

        return Storage::disk('local')->download($s->archivo, $s->folio.'.zip', ['Content-Type' => 'application/zip', 'Cache-Control' => 'no-store, private']);
    }

    public function eliminar(Request $request, int $solicitud): RedirectResponse
    {
        $actor = $this->exigirPermiso($request);
        $s = $this->buscar($actor, $solicitud);
        $request->validate(['confirmar' => ['accepted']], ['confirmar.accepted' => 'Marca «Entiendo que no se puede deshacer» para eliminar los datos.']);
        $h = $this->solicitudes->eliminar($actor, $s, (array) $request->input('seleccion', []));

        return redirect()->route('arco.show', [$s->id] + $this->criteriosDe($request))->with('ok', sprintf('Datos eliminados: %d del padrón, %d registros de la bitácora y %d candidato(s)%s. Ahora escribe tu respuesta a la persona.',
            $h['persona'], $h['acceso'], $h['candidato'], $h['omitidos'] > 0 ? ' ('.$h['omitidos'].' contratado(s) no se eliminaron: ya son colaboradores)' : ''));
    }

    public function responder(Request $request, int $solicitud): RedirectResponse
    {
        $actor = $this->exigirPermiso($request);
        $s = $this->buscar($actor, $solicitud);
        $this->solicitudes->responder($actor, $s, (string) $request->input('respuesta', ''), $request->input('resultado') !== 'rechazada');

        return redirect()->route('arco.index')->with('ok', 'Solicitud '.$s->folio.' respondida: le enviamos tu respuesta a la persona por correo. Revisa las demás solicitudes abiertas.');
    }

    public function canalizar(Request $request, int $solicitud): RedirectResponse
    {
        $actor = $this->exigirPermiso($request);
        $s = $this->buscar($actor, $solicitud);
        abort_unless($actor->es_superadmin, 403);
        $empresaId = (int) $request->validate(['empresa_id' => ['required', 'integer']], [], ['empresa_id' => 'empresa'])['empresa_id'];
        $empresa = Empresa::where('activo', true)->findOrFail($empresaId);
        $this->solicitudes->canalizar($actor, $s, $empresa);

        return redirect()->route('arco.index', ['plataforma' => 1])->with('ok', "Solicitud {$s->folio} canalizada a {$empresa->nombre_comercial}: ya está en su bandeja y le avisamos a quien la atiende.");
    }

    // -------------------------------------------------------------------------

    private function exigirPermiso(Request $request): User
    {
        $actor = $request->user();
        Gate::authorize(SolicitudesArco::PERMISO);
        abort_unless($this->autorizador->alcanceDeEmpresa($actor, SolicitudesArco::PERMISO), 403, 'Las solicitudes ARCO son de toda la empresa: hace falta el permiso «Datos personales» de Configuración con alcance de empresa.');

        return $actor;
    }

    /** La solicitud solo si es de la empresa de trabajo (o, para el Super Administrador, cualquiera). 404 si no. */
    /**
     * Criterios de búsqueda que viajan ocultos en el formulario de selección.
     *
     * @return array<string, string>
     */
    private function criteriosDe(Request $request): array
    {
        $criterios = [];
        foreach (['nombre' => 150, 'correo' => 150, 'telefono' => 20, 'folio' => 50] as $campo => $largo) {
            $valor = $request->input('criterios.'.$campo);
            if ($request->has('criterios.'.$campo) && (is_string($valor) || $valor === null)) {
                $criterios[$campo] = mb_substr((string) $valor, 0, $largo); // vacío = lo dejó en blanco
            }
        }

        return $criterios;
    }

    private function buscar(User $actor, int $id): SolicitudArco
    {
        $q = SolicitudArco::query()->withoutGlobalScope(EmpresaScope::class);
        $empresaId = $this->empresa->id($actor);
        if (! $actor->es_superadmin) {
            abort_if($empresaId === null, 404);
            $q->where('empresa_id', $empresaId);
        } elseif ($empresaId !== null) {
            // Super Administrador con empresa elegida: las de esa empresa y las de la plataforma
            $q->where(fn ($w) => $w->where('empresa_id', $empresaId)->orWhereNull('empresa_id'));
        }

        return $q->findOrFail($id);
    }
}
