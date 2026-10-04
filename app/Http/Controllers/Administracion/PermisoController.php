<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Models\Accion;
use App\Models\Area;
use App\Models\Auditoria;
use App\Models\Rol;
use App\Models\RolPermiso;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use App\Support\Tenancy\EmpresaDeTrabajo;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Permisos por Rol (réplica de modules/permisos/permisos_lista.php de SEGCAT).
 *
 * Diferencias con SEGCAT: los módulos salen del catálogo (no de una lista en
 * código), hay acciones adicionales (aprobar, firmar, imprimir...) y un
 * alcance por módulo (solo los propios, su sede o toda la empresa).
 */
class PermisoController extends Controller
{
    /** Columnas fijas, como en SEGCAT; el resto va en "Otras acciones". */
    public const BASICAS = ['ver', 'crear', 'editar', 'eliminar'];

    public function __construct(
        private readonly AdministradorRoles $administrador,
        private readonly Autorizador $autorizador,
        private readonly EmpresaDeTrabajo $empresa,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('permisos.ver');

        $usuario = $request->user();
        $empresaId = $this->empresa->id($usuario);

        $roles = Rol::query()
            ->when($empresaId === null, fn ($q) => $q->whereNull('empresa_id'), fn ($q) => $q->where('empresa_id', $empresaId))
            ->orderBy('nivel_jerarquia')
            ->get();

        // Sin rol elegido se abre el primero que el usuario puede modificar
        $nivelPropio = $usuario->nivelJerarquia();
        $rol = $roles->firstWhere('id', (int) $request->query('rol'))
            ?? ($usuario->es_superadmin ? null : $roles->first(fn (Rol $r) => $r->nivel_jerarquia > $nivelPropio))
            ?? $roles->first();

        $actuales = $rol === null ? collect() : RolPermiso::query()
            ->join('modulo_acciones as ma', 'ma.id', '=', 'rol_permisos.modulo_accion_id')
            ->join('acciones as a', 'a.id', '=', 'ma.accion_id')
            ->where('rol_permisos.rol_id', $rol->id)
            ->get(['ma.modulo_id', 'a.clave as accion', 'rol_permisos.alcance'])
            ->groupBy('modulo_id');

        $ultimaModificacion = $rol === null ? null : Auditoria::query()
            ->leftJoin('users', 'users.id', '=', 'auditoria.user_id')
            ->where('auditable_type', Rol::class)
            ->where('auditable_id', $rol->id)
            ->where('evento', 'permisos.rol_actualizado')
            ->latest('creado_en')
            ->first(['auditoria.creado_en', 'users.name']);

        $editable = $rol !== null && $usuario->can('permisos.editar') && $this->puedeAdministrar($request, $rol);

        return view('administracion.permisos.index', [
            'roles' => $roles,
            'rol' => $rol,
            'areas' => $this->catalogo($empresaId),
            'actuales' => $actuales,
            'acciones' => Accion::orderBy('orden')->pluck('nombre', 'clave'),
            'basicas' => self::BASICAS,
            'alcances' => Alcance::cases(),
            'propios' => $usuario->es_superadmin ? null : $this->autorizador->permisosEfectivos($usuario),
            'editable' => $editable,
            'puedeEditar' => $usuario->can('permisos.editar'),
            'ultimaModificacion' => $ultimaModificacion,
            'esPlantillas' => $this->empresa->esPlantillas($usuario),
        ]);
    }

    public function update(Request $request, Rol $rol): RedirectResponse
    {
        Gate::authorize('permisos.editar');
        abort_unless($rol->empresa_id === $this->empresa->id($request->user()), 404);

        $datos = $request->validate([
            'permisos' => ['array'],
            'permisos.*.acciones' => ['array'],
            'permisos.*.acciones.*' => ['string'],
            'permisos.*.alcance' => ['nullable', Rule::enum(Alcance::class)],
        ]);

        // Solo módulos y acciones del catálogo visible (nunca nombres inventados en el navegador)
        $catalogo = $this->catalogo($rol->empresa_id)
            ->flatMap(fn ($area) => $area->modulosVisibles)
            ->mapWithKeys(fn ($modulo) => [$modulo->clave => $modulo->acciones->pluck('clave')->all()]);

        $nuevos = [];
        foreach ($datos['permisos'] ?? [] as $modulo => $fila) {
            if (! $catalogo->has($modulo)) {
                continue;
            }

            $acciones = array_values(array_intersect($fila['acciones'] ?? [], $catalogo[$modulo]));
            if ($acciones === []) {
                continue;
            }

            // Cualquier acción implica poder ver el módulo
            if (! in_array('ver', $acciones, true) && in_array('ver', $catalogo[$modulo], true)) {
                $acciones[] = 'ver';
            }

            $alcance = Alcance::tryFrom($fila['alcance'] ?? '') ?? Alcance::Sede;
            foreach ($acciones as $accion) {
                $nuevos["{$modulo}.{$accion}"] = $alcance;
            }
        }

        // Se conservan los permisos de módulos que no aparecen en la matriz
        // (p. ej. no contratados por la empresa) para no borrarlos sin querer.
        $ocultos = RolPermiso::with('moduloAccion.modulo', 'moduloAccion.accion')
            ->where('rol_id', $rol->id)
            ->get()
            ->reject(fn (RolPermiso $p) => $catalogo->has($p->moduloAccion->modulo->clave))
            ->mapWithKeys(fn (RolPermiso $p) => [$p->moduloAccion->clave() => $p->alcance])
            ->all();

        try {
            $this->administrador->sincronizarPermisos($request->user(), $rol, $nuevos + $ocultos);
        } catch (AuthorizationException $e) {
            return redirect()->route('permisos.index', ['rol' => $rol->id])->with('error', $e->getMessage());
        }

        return redirect()->route('permisos.index', ['rol' => $rol->id])->with('ok', 'Permisos actualizados correctamente.');
    }

    /**
     * Áreas con sus módulos (y submódulos) activos; para una empresa, solo
     * los que tiene contratados.
     *
     * @return Collection<int, Area>
     */
    private function catalogo(?int $empresaId): Collection
    {
        $contratados = $empresaId === null ? null : DB::table('empresa_modulos')
            ->where('empresa_id', $empresaId)
            ->where('activo', true)
            ->pluck('modulo_id')
            ->all();

        $areas = Area::query()
            ->where('activo', true)
            ->orderBy('orden')
            ->with(['modulos' => fn ($q) => $q->where('activo', true)->orderBy('orden')->with(['acciones' => fn ($a) => $a->orderBy('orden')])])
            ->get();

        foreach ($areas as $area) {
            $modulos = $area->modulos->filter(fn ($m) => $contratados === null || in_array($m->id, $contratados, true));

            // Padres primero y cada submódulo debajo de su padre
            $ordenados = collect();
            foreach ($modulos->whereNull('padre_id') as $padre) {
                $ordenados->push($padre);
                foreach ($modulos->where('padre_id', $padre->id) as $hijo) {
                    $ordenados->push($hijo);
                }
            }

            $area->setRelation('modulosVisibles', $ordenados);
        }

        return $areas->filter(fn ($area) => $area->modulosVisibles->isNotEmpty())->values();
    }

    private function puedeAdministrar(Request $request, Rol $rol): bool
    {
        try {
            $this->administrador->exigirPuedeAdministrarRol($request->user(), $rol, 'permisos.editar');

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }
}
