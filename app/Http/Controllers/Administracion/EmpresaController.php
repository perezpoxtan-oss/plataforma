<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Empresa;
use App\Models\Modulo;
use App\Models\Paquete;
use App\Models\Plan;
use App\Models\Rubro;
use App\Models\Suscripcion;
use App\Models\User;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Autorizador;
use App\Services\Plataforma\ProvisionarEmpresa;
use App\Services\Suscripciones\Limites;
use App\Support\Entrada;
use App\Support\ImagenSegura;
use App\Support\ZonasHorarias;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

        // Módulos contratados por empresa (ADR-0016): solo el Super Administrador los cambia
        $contratados = ! $actor->es_superadmin ? collect() : DB::table('empresa_modulos')->where('activo', true)
            ->get(['empresa_id', 'modulo_id'])->groupBy('empresa_id')->map(fn ($f) => $f->pluck('modulo_id')->map(fn ($id) => (int) $id)->all());

        // Suscripciones: el cliente ve «Tu suscripción»; el Super Administrador, el estado de cada una (sin suscripción = sin límites)
        $suscripcion = ! $actor->es_superadmin && $actor->empresa_id !== null ? Suscripcion::with(['plan:id,nombre', 'paquetes:id,nombre'])->where('empresa_id', $actor->empresa_id)->first() : null;

        return view('administracion.empresas.index', [
            'suscripcion' => $suscripcion,
            'limitesSuscripcion' => $suscripcion !== null ? app(Limites::class)->de((int) $actor->empresa_id) : null,
            'puedePedirCambio' => $suscripcion !== null && $this->editaEmpresa($actor),
            'planesSuscripcion' => $suscripcion !== null ? Plan::query()->where('activo', true)->where(fn ($q) => $q->where('publicado', true)->orWhere('id', $suscripcion->plan_id))->orderBy('orden')->get(['id', 'nombre', 'cotizar']) : collect(),
            'paquetesSuscripcion' => $suscripcion !== null ? Paquete::query()->where('activo', true)->where('modalidad', Paquete::SUSCRIPCION)->where(fn ($q) => $q->where('publicado', true)->orWhereIn('id', $suscripcion->paquetes->pluck('id')))->orderBy('orden')->get(['id', 'nombre']) : collect(),
            'suscripciones' => $actor->es_superadmin ? Suscripcion::query()->get()->keyBy('empresa_id') : collect(),
            'catalogoModulos' => $actor->es_superadmin ? $this->catalogoContratable() : collect(),
            'contratados' => $contratados,
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
            : 'Empresa dada de baja: sus usuarios ya no pueden entrar. Puedes reactivarla con el mismo botón.');
    }

    /**
     * Módulos contratados (ADR-0016): el Super Administrador elige qué módulos
     * tiene cada empresa. Los de Dirección (empresa, sedes, usuarios, roles,
     * permisos, configuración, auditoría…) siempre quedan. Quitar un módulo
     * no borra datos ni permisos: solo deja de verse (menú, matriz y manual).
     */
    public function modulos(Request $request, Empresa $empresa): RedirectResponse
    {
        // Seguridad (AZ-03): otra empresa responde 404 (sin revelar que existe)
        abort_unless($request->user()->es_superadmin || $empresa->id === (int) $request->user()->empresa_id, 404);
        abort_unless($request->user()->es_superadmin, 403);

        $elegidos = array_map('intval', (array) $request->validate(['modulos' => ['nullable', 'array'], 'modulos.*' => ['integer']])['modulos'] ?? []);
        $catalogo = Modulo::query()->where('activo', true)->where('tipo', '!=', Modulo::TIPO_PLATAFORMA)->get(['id', 'clave', 'padre_id', 'area_id']);
        $direccion = Area::where('clave', 'direccion')->value('id');

        $antes = DB::table('empresa_modulos')->where('empresa_id', $empresa->id)->where('activo', true)->pluck('modulo_id')->map(fn ($id) => (int) $id)->all();
        $activos = [];
        DB::transaction(function () use ($catalogo, $elegidos, $direccion, $empresa, &$activos) {
            foreach ($catalogo as $modulo) {
                $padreActivo = $modulo->padre_id === null || in_array((int) $modulo->padre_id, $elegidos, true) || (int) $catalogo->firstWhere('id', $modulo->padre_id)?->area_id === (int) $direccion;
                $activo = (int) $modulo->area_id === (int) $direccion || (in_array((int) $modulo->id, $elegidos, true) && $padreActivo);
                DB::table('empresa_modulos')->updateOrInsert(
                    ['empresa_id' => $empresa->id, 'modulo_id' => $modulo->id],
                    ['activo' => $activo, 'updated_at' => now()],
                );
                if ($activo) {
                    $activos[] = (int) $modulo->id;
                }
            }
        });
        app(Autorizador::class)->olvidar();

        $claves = fn (array $ids) => $catalogo->whereIn('id', $ids)->pluck('clave')->sort()->values()->all();
        $this->auditoria->auditar($request->user(), 'empresas.modulos_actualizados', $empresa, ['modulos' => $claves($antes)], ['modulos' => $claves($activos)]);
        $quitados = count(array_diff($antes, $activos));

        return redirect()->to(route('empresas.index').'#empresa-'.$empresa->id)->with('ok', "Módulos de «{$empresa->nombre_comercial}» actualizados: tiene ".count($activos).' módulos'
            .($quitados > 0 ? " ({$quitados} quitados: dejan de verse en el menú, la matriz y el manual; sus datos y permisos se conservan)" : '')
            .'. Revisa sus roles en la Matriz de permisos si hace falta.');
    }

    /**
     * Áreas con sus módulos contratables (padres y, debajo, submódulos).
     *
     * @return Collection<int, array{area: string, siempre: bool, modulos: list<Modulo>}>
     */
    private function catalogoContratable(): Collection
    {
        return Area::query()->where('activo', true)->orderBy('orden')
            ->with(['modulos' => fn ($q) => $q->where('activo', true)->where('tipo', '!=', Modulo::TIPO_PLATAFORMA)->orderBy('orden')])
            ->get()
            ->map(function (Area $area) {
                $ordenados = [];
                foreach ($area->modulos->whereNull('padre_id') as $padre) {
                    $ordenados[] = $padre;
                    foreach ($area->modulos->where('padre_id', $padre->id) as $hijo) {
                        $ordenados[] = $hijo;
                    }
                }

                return ['area' => $area->nombre, 'siempre' => $area->clave === 'direccion', 'modulos' => $ordenados];
            })
            ->filter(fn ($a) => $a['modulos'] !== [])->values();
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
