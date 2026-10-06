<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\Sede;
use App\Models\User;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use App\Support\ZonasHorarias;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Sedes (réplica de modules/hoteles/hotel_lista.php de SEGCAT). El nombre de la
 * pantalla sale de la terminología del rubro (hoy, en todos los rubros, "Sedes").
 */
class SedeController extends Controller
{
    private const CAMPOS = ['codigo', 'nombre', 'ciudad', 'entidad', 'direccion', 'colonia', 'codigo_postal', 'telefono', 'zona_horaria', 'activo'];

    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly Autorizador $autorizador,
        private readonly AdministradorRoles $auditoria,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('sedes.ver');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);

        if ($empresaId === null) {
            return view('administracion.sedes.index', ['sinEmpresa' => true, 'termino' => $this->termino(null)]);
        }

        $empresa = Empresa::with('rubro')->findOrFail($empresaId);

        $sedes = $this->tenant->conEmpresa($empresaId, fn () => $this->visibles($actor)
            ->leftJoin('users as uc', 'uc.id', '=', 'sedes.creado_por')
            ->leftJoin('users as ua', 'ua.id', '=', 'sedes.actualizado_por')
            ->select('sedes.*', 'uc.name as creado_por_nombre', 'ua.name as actualizado_por_nombre')
            ->orderBy('sedes.nombre')
            ->get());

        return view('administracion.sedes.index', [
            'sinEmpresa' => false,
            'empresa' => $empresa,
            'sedes' => $sedes,
            'termino' => $this->termino($empresa),
            'zonas' => ZonasHorarias::opciones(),
            'puede' => [
                'crear' => $actor->can('sedes.crear'),
                'editar' => $actor->can('sedes.editar'),
                'estado' => $actor->can('sedes.eliminar'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('sedes.crear');
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        $datos = $this->validar($request, $empresaId);
        $sede = $this->tenant->conEmpresa($empresaId, fn () => Sede::create($datos));
        $this->auditoria->auditar($request->user(), 'sedes.creada', $sede, null, $sede->only(self::CAMPOS));

        return redirect()->route('sedes.index')->with('ok', "«{$sede->nombre}» registrada correctamente.");
    }

    public function update(Request $request, int $sede): RedirectResponse
    {
        Gate::authorize('sedes.editar');
        [$modelo, $empresaId] = $this->buscar($request, $sede);

        $datos = $this->validar($request, $empresaId, $modelo);
        $antes = $modelo->only(self::CAMPOS);
        $this->tenant->conEmpresa($empresaId, fn () => $modelo->fill($datos)->save());
        $this->auditoria->auditar($request->user(), 'sedes.actualizada', $modelo, $antes, $modelo->only(self::CAMPOS));

        return redirect()->route('sedes.index')->with('ok', "«{$modelo->nombre}» actualizada correctamente.");
    }

    public function estado(Request $request, int $sede): RedirectResponse
    {
        Gate::authorize('sedes.eliminar');
        [$modelo, $empresaId] = $this->buscar($request, $sede);

        $activo = $request->boolean('activo');
        $this->tenant->conEmpresa($empresaId, fn () => $modelo->forceFill(['activo' => $activo])->save());
        $this->auditoria->auditar($request->user(), $activo ? 'sedes.reactivada' : 'sedes.desactivada', $modelo, ['activo' => ! $activo], ['activo' => $activo]);

        return redirect()->route('sedes.index')->with($activo ? 'ok' : 'aviso', $activo
            ? "«{$modelo->nombre}» reactivada."
            : "«{$modelo->nombre}» desactivada: ya no aparecerá para nuevas capturas. Su historial se conserva.");
    }

    /**
     * Sedes que el actor puede ver: todas, o solo las suyas si su alcance es de sede.
     */
    private function visibles(User $actor): Builder
    {
        $consulta = Sede::query();

        if ($actor->es_superadmin) {
            return $consulta;
        }

        $permiso = $this->autorizador->permisosEfectivos($actor)['sedes.ver'] ?? null;

        return match (true) {
            $permiso === null => $consulta->whereRaw('1 = 0'),
            $permiso->alcance === Alcance::Empresa => $consulta,
            // Seguridad (AZ-04): "Solo los propios" sin sede no es toda la empresa
            $permiso->alcance === Alcance::Propios && $permiso->sedes === null => $consulta->where('sedes.creado_por', $actor->id),
            $permiso->sedes === null => $consulta,
            default => $consulta->whereIn('sedes.id', $permiso->sedes),
        };
    }

    /**
     * @return array{0: Sede, 1: int}
     */
    private function buscar(Request $request, int $id): array
    {
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        $sede = $this->tenant->conEmpresa($empresaId, fn () => $this->visibles($request->user())->whereKey($id)->first());
        abort_if($sede === null, 404);

        return [$sede, $empresaId];
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, int $empresaId, ?Sede $sede = null): array
    {
        $request->merge(['codigo' => mb_strtoupper(trim((string) $request->input('codigo')))]);

        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:150'],
            'codigo' => ['required', 'string', 'max:10', 'regex:/^[A-Z0-9-]+$/', Rule::unique('sedes', 'codigo')->where('empresa_id', $empresaId)->ignore($sede?->id)],
            'ciudad' => ['required', 'string', 'max:100'],
            'entidad' => ['required', 'string', 'max:100'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'colonia' => ['nullable', 'string', 'max:150'],
            'codigo_postal' => ['nullable', 'regex:/^\d{5}$/'],
            'telefono' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+()\s-]+$/'],
            'zona_horaria' => ['nullable', 'timezone:all'],
        ], [
            'codigo.regex' => 'El código solo puede tener letras, números y guiones (sin espacios ni acentos).',
            'codigo.unique' => 'Ya existe otra sede con ese código en esta empresa.',
            'codigo_postal.regex' => 'El código postal debe tener 5 dígitos.',
            'telefono.regex' => 'El teléfono solo puede tener números, espacios, +, guiones y paréntesis.',
        ], [
            'codigo' => 'código',
            'entidad' => 'estado',
            'direccion' => 'calle y número',
            'codigo_postal' => 'código postal',
            'telefono' => 'teléfono',
            'zona_horaria' => 'zona horaria',
        ]);

        return array_map(fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v, $datos);
    }

    /**
     * Cómo se llama la sede en el rubro de la empresa.
     *
     * @return array{singular: string, plural: string, nuevo: string, este: string}
     */
    private function termino(?Empresa $empresa): array
    {
        $t = $empresa?->rubro?->terminologia ?? [];

        $singular = $t['sede'] ?? 'Sede';
        // Hotel es masculino; Sede, Planta, Torre y Privada, femeninos
        $femenino = ($t['sede_genero'] ?? (in_array($singular, ['Hotel'], true) ? 'm' : 'f')) === 'f';

        return [
            'singular' => $singular,
            'plural' => $t['sedes'] ?? 'Sedes',
            'nuevo' => $femenino ? 'Nueva' : 'Nuevo',
            'este' => $femenino ? 'esta' : 'este',
        ];
    }
}
