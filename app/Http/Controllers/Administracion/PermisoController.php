<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Models\Accion;
use App\Models\Area;
use App\Models\Auditoria;
use App\Models\Modulo;
use App\Models\Rol;
use App\Models\RolPermiso;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use App\Services\Permisos\PerfilesDeRol;
use App\Services\Usuarios\AdministradorUsuarios;
use App\Support\Tenancy\EmpresaDeTrabajo;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
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

    /**
     * Acciones que NO implican «Ver» el módulo (candidatos, fase 2): quien
     * entrevista evalúa y elige en su pantalla Entrevistar sin ver la lista ni
     * los CV completos de Candidatos.
     */
    public const SIN_VER = ['candidatos.evaluar'];

    public function __construct(
        private readonly AdministradorRoles $administrador,
        private readonly Autorizador $autorizador,
        private readonly EmpresaDeTrabajo $empresa,
        private readonly PerfilesDeRol $perfiles,
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
        $claves = $rol === null ? [] : array_keys($this->perfiles->clavesDe($rol));

        return view('administracion.permisos.index', [
            // Mejoras estructura (ADR-0016): asistente «¿Qué hace este rol?», vista previa de menús y «Próximamente»
            'perfiles' => $editable ? $this->perfiles->perfiles($empresaId)->reject(fn ($p) => $p['nombre'] === $rol->nombre && $rol->esPlantilla())->values() : collect(),
            'menusRol' => $this->perfiles->menus($claves, $empresaId),
            'mapaMenus' => $this->perfiles->mapaMenus($empresaId),
            'proximamente' => self::sinPantalla(),
            'avanzadoAbierto' => ! $editable || $request->boolean('avanzado'),
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
        // Seguridad (AZ-03): primero la empresa (404), luego el permiso (403)
        abort_unless($rol->empresa_id === $this->empresa->id($request->user()), 404);
        Gate::authorize('permisos.editar');

        $datos = $request->validate([
            'permisos' => ['array'],
            'permisos.*.acciones' => ['array'],
            'permisos.*.acciones.*' => ['string'],
            'permisos.*.alcance' => ['nullable', Rule::enum(Alcance::class)],
            'permisos.*.mixto' => ['nullable', 'boolean'],
            'permisos.*.alcances' => ['nullable', 'array'],
            'permisos.*.alcances.*' => ['nullable', Rule::enum(Alcance::class)],
        ]);

        // Solo módulos y acciones del catálogo visible (nunca nombres inventados en el navegador)
        // Los módulos sin pantalla («Próximamente») no se tocan desde aquí: sus permisos se conservan
        $catalogo = $this->catalogo($rol->empresa_id)
            ->flatMap(fn ($area) => $area->modulosVisibles)
            ->reject(fn ($modulo) => in_array($modulo->clave, self::sinPantalla(), true))
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

            // Cualquier acción implica poder ver el módulo (salvo las de SIN_VER)
            $implicanVer = array_filter($acciones, fn ($a) => ! in_array("{$modulo}.{$a}", self::SIN_VER, true));
            if ($implicanVer !== [] && ! in_array('ver', $acciones, true) && in_array('ver', $catalogo[$modulo], true)) {
                $acciones[] = 'ver';
            }

            $alcance = Alcance::tryFrom($fila['alcance'] ?? '') ?? Alcance::Sede;
            // Alcance distinto por acción (p. ej. «crear: solo los propios» de las plantillas):
            // se conserva mientras no se cambie el alcance del módulo (ADR-0016)
            $porAccion = ! empty($fila['mixto']) && is_array($fila['alcances'] ?? null) ? $fila['alcances'] : [];
            foreach ($acciones as $accion) {
                $nuevos["{$modulo}.{$accion}"] = Alcance::tryFrom((string) ($porAccion[$accion] ?? '')) ?? $alcance;
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

        return $this->avisarSinCorreo(redirect()->route('permisos.index', ['rol' => $rol->id, 'avanzado' => 1])->with('ok', "Permisos de «{$rol->nombre}» actualizados: ya aplican a todas las personas con ese rol. Revisa arriba los menús que verán."), $rol);
    }

    /**
     * Asistente «¿Qué hace este rol?» (ADR-0016): reemplaza los permisos del rol
     * por los de un perfil (plantilla de la plataforma) o los de otro rol de la
     * empresa. Solo se otorga lo que quien lo hace tiene.
     */
    public function perfil(Request $request, Rol $rol): RedirectResponse
    {
        // Seguridad (AZ-03): primero la empresa (404), luego el permiso (403)
        abort_unless($rol->empresa_id === $this->empresa->id($request->user()), 404);
        Gate::authorize('permisos.editar');

        // «Igual que otro rol de tu empresa»: el rol se elige en su propia lista
        if ($request->input('perfil') === 'otro') {
            $request->merge(['perfil' => $request->input('perfil_rol')]);
        }
        $datos = $request->validate([
            'perfil' => ['required', 'string', 'regex:/^(perfil|rol)-\d+$/'],
        ], ['perfil.required' => 'Elige qué hace este rol.', 'perfil.regex' => 'Elige qué hace este rol.']);
        [$tipo, $id] = explode('-', $datos['perfil']);
        $origen = $tipo === 'perfil'
            ? Rol::plantillas()->where('activo', true)->find((int) $id)
            : Rol::query()->when($rol->empresa_id === null, fn ($q) => $q->whereNull('empresa_id'), fn ($q) => $q->where('empresa_id', $rol->empresa_id))->find((int) $id);
        abort_if($origen === null || $origen->id === $rol->id, 422, 'Ese perfil no existe.');

        try {
            $copia = $this->perfiles->copiar($request->user(), $origen, $rol);
        } catch (AuthorizationException $e) {
            return redirect()->route('permisos.index', ['rol' => $rol->id])->with('error', $e->getMessage());
        }

        return $this->avisarSinCorreo(redirect()->route('permisos.index', ['rol' => $rol->id])->with('ok', "«{$rol->nombre}» ahora tiene los permisos de «{$origen->nombre}»"
            .($copia['omitidos'] > 0 ? " (sin {$copia['omitidos']} que tú no tienes)" : '')
            .($copia['conservados'] > 0 ? "; se conservaron {$copia['conservados']} que solo alguien de mayor nivel puede quitar" : '')
            .'. Revisa abajo los menús que verá; si algo cambia, ajústalo en «Avanzado».'), $rol);
    }

    /**
     * C-36 (A-06): si el rol ahora administra algo y tiene cuentas sin correo,
     * se avisa cuáles (sin correo no recuperan su cuenta solas ni reciben avisos).
     */
    private function avisarSinCorreo(RedirectResponse $respuesta, Rol $rol): RedirectResponse
    {
        $sinCorreo = AdministradorUsuarios::administrativasSinCorreo((int) $rol->empresa_id, $rol);
        if ($sinCorreo->isEmpty()) {
            return $respuesta;
        }

        return $respuesta->with('aviso', "«{$rol->nombre}» ahora administra la plataforma y estas cuentas de ese rol no tienen correo: "
            .$sinCorreo->pluck('name')->join(', ').'. Escríbeles un correo en Usuarios para que puedan recuperar su cuenta y recibir avisos.');
    }

    /**
     * Módulos del catálogo que todavía no tienen pantalla (sin ruta): en la
     * matriz salen como «Próximamente» y sus permisos se conservan sin tocarse.
     *
     * @return list<string>
     */
    public static function sinPantalla(): array
    {
        return Modulo::query()->where('activo', true)->get(['clave', 'ruta'])
            ->filter(fn (Modulo $m) => $m->ruta === null || ! Route::has($m->ruta))
            ->pluck('clave')->values()->all();
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
            ->with(['modulos' => fn ($q) => $q->where('activo', true)->where('tipo', '!=', Modulo::TIPO_PLATAFORMA)->orderBy('orden')->with(['acciones' => fn ($a) => $a->orderBy('orden')])])
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
