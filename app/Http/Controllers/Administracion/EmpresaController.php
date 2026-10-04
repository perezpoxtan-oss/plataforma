<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\Rubro;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Plataforma\ProvisionarEmpresa;
use App\Support\ZonasHorarias;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Empresas (réplica de modules/empresas/empresa_lista.php de SEGCAT).
 *
 * Dar de alta, desactivar y cambiar el rubro es exclusivo del Super
 * Administrador; el administrador de un cliente solo ve y corrige los datos
 * de su propia empresa.
 */
class EmpresaController extends Controller
{
    private const CAMPOS = ['nombre_comercial', 'razon_social', 'rfc', 'zona_horaria', 'rubro_id', 'activo'];

    public function __construct(private readonly AdministradorRoles $auditoria) {}

    public function index(Request $request): View
    {
        Gate::authorize('empresas.ver');
        $actor = $request->user();

        $empresas = Empresa::query()
            ->with(['rubro:id,nombre,terminologia', 'sedes' => fn ($q) => $q->withoutGlobalScopes()->orderBy('nombre')->select('id', 'empresa_id', 'nombre', 'activo')])
            ->when(! $actor->es_superadmin, fn ($q) => $q->whereKey($actor->empresa_id))
            ->leftJoin('users as uc', 'uc.id', '=', 'empresas.creado_por')
            ->leftJoin('users as ua', 'ua.id', '=', 'empresas.actualizado_por')
            ->select('empresas.*', 'uc.name as creado_por_nombre', 'ua.name as actualizado_por_nombre')
            ->orderBy('empresas.nombre_comercial')
            ->get();

        return view('administracion.empresas.index', [
            'empresas' => $empresas,
            'rubros' => Rubro::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'zonas' => ZonasHorarias::opciones(),
            'esSuperadmin' => $actor->es_superadmin,
            'puede' => [
                'crear' => $actor->es_superadmin && $actor->can('empresas.crear'),
                'editar' => $actor->can('empresas.editar'),
                'estado' => $actor->es_superadmin,
                'sedes' => $actor->can('sedes.ver'),
            ],
        ]);
    }

    public function store(Request $request, ProvisionarEmpresa $provisionar): RedirectResponse
    {
        Gate::authorize('empresas.crear');
        abort_unless($request->user()->es_superadmin, 403);

        $datos = $this->validar($request);
        $rubro = Rubro::findOrFail($datos['rubro_id']);

        $empresa = $provisionar->crear($rubro, collect($datos)->except('rubro_id')->all());
        $this->auditoria->auditar($request->user(), 'empresas.creada', $empresa, null, $empresa->only(self::CAMPOS));

        return redirect()->route('empresas.index')->with('ok', "Empresa «{$empresa->nombre_comercial}» creada con sus módulos y roles base. Ahora registra sus sedes.");
    }

    public function update(Request $request, Empresa $empresa): RedirectResponse
    {
        Gate::authorize('empresas.editar');
        $actor = $request->user();
        abort_unless($actor->es_superadmin || $empresa->id === (int) $actor->empresa_id, 404);

        $datos = $this->validar($request, $empresa);

        // Solo la plataforma cambia el rubro (define la terminología y los módulos)
        if (! $actor->es_superadmin) {
            unset($datos['rubro_id']);
        }

        $antes = $empresa->only(self::CAMPOS);
        $empresa->fill($datos)->save();
        $this->auditoria->auditar($actor, 'empresas.actualizada', $empresa, $antes, $empresa->only(self::CAMPOS));

        return redirect()->route('empresas.index')->with('ok', 'Empresa actualizada correctamente.');
    }

    public function estado(Request $request, Empresa $empresa): RedirectResponse
    {
        abort_unless($request->user()->es_superadmin, 403);

        $activo = $request->boolean('activo');
        $empresa->forceFill(['activo' => $activo])->save();
        $this->auditoria->auditar($request->user(), $activo ? 'empresas.reactivada' : 'empresas.desactivada', $empresa, ['activo' => ! $activo], ['activo' => $activo]);

        return redirect()->route('empresas.index')->with($activo ? 'ok' : 'aviso', $activo
            ? 'Empresa reactivada correctamente.'
            : 'Empresa desactivada: sus usuarios ya no pueden entrar. Puedes reactivarla con el mismo botón.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, ?Empresa $empresa = null): array
    {
        $request->merge(['rfc' => mb_strtoupper(trim((string) $request->input('rfc')))]);

        $datos = $request->validate([
            'nombre_comercial' => ['required', 'string', 'max:150'],
            'razon_social' => ['required', 'string', 'max:200'],
            'rfc' => ['required', 'string', 'regex:/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/u', Rule::unique('empresas', 'rfc')->ignore($empresa?->id)],
            'rubro_id' => [$empresa === null ? 'required' : 'sometimes', 'integer', Rule::exists('rubros', 'id')],
            'zona_horaria' => ['required', 'timezone:all'],
        ], [
            'rfc.regex' => 'El RFC no tiene un formato válido (12 caracteres para persona moral, 13 para persona física).',
            'rfc.unique' => 'Ya existe una empresa registrada con ese RFC.',
        ], [
            'nombre_comercial' => 'nombre comercial',
            'razon_social' => 'razón social',
            'rubro_id' => 'rubro',
            'zona_horaria' => 'zona horaria',
        ]);

        $datos['nombre_comercial'] = trim($datos['nombre_comercial']);
        $datos['razon_social'] = trim($datos['razon_social']);

        return $datos;
    }
}
