<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\Rubro;
use App\Models\User;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Autorizador;
use App\Services\Plataforma\ProvisionarEmpresa;
use App\Support\Entrada;
use App\Support\ImagenSegura;
use App\Support\ZonasHorarias;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
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
                'editar' => $this->editaEmpresa($actor),
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

        $empresa = $provisionar->crear($rubro, collect($datos)->except(['rubro_id', 'logo', 'quitar_logo'])->all());
        $this->guardarLogo($request, $empresa);
        $this->auditoria->auditar($request->user(), 'empresas.creada', $empresa, null, $empresa->only(self::CAMPOS));

        return redirect()->route('empresas.index')->with('ok', "Empresa «{$empresa->nombre_comercial}» creada con sus módulos y roles base. Ahora registra sus sedes.");
    }

    public function update(Request $request, Empresa $empresa): RedirectResponse
    {
        $actor = $request->user();
        // Seguridad (AZ-03): otra empresa responde 404 aunque no se tenga el permiso (sin revelar que existe)
        abort_unless($actor->es_superadmin || $empresa->id === (int) $actor->empresa_id, 404);
        Gate::authorize('empresas.editar');
        // Seguridad (AZ-02): los datos de la empresa solo los cambia quien tiene alcance de empresa
        abort_unless($this->editaEmpresa($actor), 403, 'Los datos de la empresa solo los cambia quien tiene el permiso «editar» de Empresas con alcance de empresa.');

        $datos = $this->validar($request, $empresa);

        // Solo la plataforma cambia el rubro (define la terminología y los módulos)
        if (! $actor->es_superadmin) {
            unset($datos['rubro_id']);
        }

        $antes = $empresa->only(self::CAMPOS);
        $empresa->fill(collect($datos)->except(['logo', 'quitar_logo'])->all())->save();
        $this->guardarLogo($request, $empresa);
        $this->auditoria->auditar($actor, 'empresas.actualizada', $empresa, $antes, $empresa->only(self::CAMPOS));

        return redirect()->route('empresas.index')->with('ok', 'Empresa actualizada correctamente.');
    }

    public function estado(Request $request, Empresa $empresa): RedirectResponse
    {
        // Seguridad (AZ-03): otra empresa responde 404 (sin revelar que existe)
        abort_unless($request->user()->es_superadmin || $empresa->id === (int) $request->user()->empresa_id, 404);
        abort_unless($request->user()->es_superadmin, 403);

        $activo = $request->boolean('activo');
        $empresa->forceFill(['activo' => $activo])->save();
        $this->auditoria->auditar($request->user(), $activo ? 'empresas.reactivada' : 'empresas.desactivada', $empresa, ['activo' => ! $activo], ['activo' => $activo]);

        return redirect()->route('empresas.index')->with($activo ? 'ok' : 'aviso', $activo
            ? 'Empresa reactivada correctamente.'
            : 'Empresa desactivada: sus usuarios ya no pueden entrar. Puedes reactivarla con el mismo botón.');
    }

    private function editaEmpresa(User $actor): bool
    {
        return $actor->can('empresas.editar') && app(Autorizador::class)->alcanceDeEmpresa($actor, 'empresas.editar');
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, ?Empresa $empresa = null): array
    {
        $request->merge(['rfc' => mb_strtoupper(trim(Entrada::texto($request->input('rfc'))))]);

        $datos = $request->validate([
            'nombre_comercial' => ['required', 'string', 'max:150'],
            'razon_social' => ['required', 'string', 'max:200'],
            'rfc' => ['required', 'string', 'regex:/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/u', Rule::unique('empresas', 'rfc')->ignore($empresa?->id)],
            'rubro_id' => [$empresa === null ? 'required' : 'sometimes', 'integer', Rule::exists('rubros', 'id')],
            'zona_horaria' => ['required', 'timezone:all'],
            // Como en SEGCAT: PNG o JPG (y WEBP); sin SVG por seguridad
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:512', 'dimensions:max_width=2048,max_height=2048'],
            'quitar_logo' => ['nullable', 'boolean'],
        ], [
            'logo.image' => 'El logo debe ser una imagen PNG, JPG o WEBP.',
            'logo.mimes' => 'El logo debe ser PNG, JPG o WEBP (SVG no está permitido por seguridad).',
            'logo.max' => 'El logo no debe pesar más de 512 KB.',
            'logo.dimensions' => 'El logo no debe medir más de 2048 × 2048 píxeles.',
            'rfc.regex' => 'El RFC no tiene un formato válido (12 caracteres para persona moral, 13 para persona física).',
            'rfc.unique' => 'Ya existe una empresa registrada con ese RFC.',
        ], [
            'nombre_comercial' => 'nombre comercial',
            'razon_social' => 'razón social',
            'rubro_id' => 'rubro',
            'zona_horaria' => 'zona horaria',
            'logo' => 'logo',
        ]);

        $datos['nombre_comercial'] = trim($datos['nombre_comercial']);
        $datos['razon_social'] = trim($datos['razon_social']);

        return $datos;
    }

    /**
     * Logo de la empresa (sale en gafetes, vouchers e impresiones). Se guarda
     * en storage/app/public/empresas/logos y reemplaza al anterior.
     */
    private function guardarLogo(Request $request, Empresa $empresa): void
    {
        $archivo = $request->file('logo');
        $anterior = $empresa->logo_ruta;

        if ($archivo instanceof UploadedFile) {
            // Seguridad: se guarda una copia re-dibujada (sin código ni metadatos escondidos)
            $nueva = 'storage/'.ImagenSegura::guardar($archivo, 'empresas/logos', 'logo');
        } elseif ($request->boolean('quitar_logo')) {
            $nueva = null;
        } else {
            return;
        }

        $empresa->forceFill(['logo_ruta' => $nueva])->save();
        if ($anterior !== null && str_starts_with($anterior, 'storage/empresas/logos/')) {
            Storage::disk('public')->delete(substr($anterior, strlen('storage/')));
        }
        $this->auditoria->auditar($request->user(), 'empresas.logo_actualizado', $empresa, ['logo_ruta' => $anterior], ['logo_ruta' => $nueva]);
    }
}
