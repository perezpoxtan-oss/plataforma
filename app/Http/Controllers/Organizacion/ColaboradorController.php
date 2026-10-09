<?php

namespace App\Http\Controllers\Organizacion;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Sede;
use App\Models\User;
use App\Services\Colaboradores\AdministradorColaboradores;
use App\Services\Colaboradores\ColaboradorParecido;
use App\Support\Entrada;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Colaboradores (réplica de modules/colaboradores de SEGCAT): directorio de
 * personal con sede física, sedes adicionales, departamento y puesto, más el
 * registro rápido y la búsqueda que usan otros módulos (Usuarios, Pases de
 * salida, Accesos).
 */
class ColaboradorController extends Controller
{
    /** Columnas de la lista: nunca los datos personales. */
    private const COLUMNAS_LISTA = [
        'colaboradores.id', 'colaboradores.empresa_id', 'colaboradores.sede_id', 'colaboradores.departamento_id',
        'colaboradores.puesto_id', 'colaboradores.num_empleado', 'colaboradores.nombre', 'colaboradores.apellido_paterno',
        'colaboradores.apellido_materno', 'colaboradores.telefono', 'colaboradores.activo', 'colaboradores.creado_por',
        'colaboradores.actualizado_por', 'colaboradores.created_at', 'colaboradores.updated_at',
        'colaboradores.provisional', 'colaboradores.validado_en', 'colaboradores.fusionado_en_id',
        // Botón «Código e identificación» (QR y etiqueta NFC/RFID del gafete o tarjeta)
        'colaboradores.codigo_qr', 'colaboradores.etiqueta_nfc',
    ];

    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly AdministradorColaboradores $colaboradores,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('colaboradores.ver');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);

        if ($empresaId === null) {
            return view('organizacion.colaboradores.index', ['sinEmpresa' => true]);
        }

        return $this->tenant->conEmpresa($empresaId, function () use ($actor) {
            $lista = $this->colaboradores->limitar(Colaborador::query(), $actor, 'colaboradores.ver')
                ->with(['sede:id,nombre', 'sedesAdicionales:id,nombre', 'departamento:id,nombre', 'puesto:id,nombre', 'fusionadoEn:id,num_empleado,nombre,apellido_paterno,apellido_materno'])
                ->leftJoin('users as uc', 'uc.id', '=', 'colaboradores.creado_por')
                ->leftJoin('users as ua', 'ua.id', '=', 'colaboradores.actualizado_por')
                ->select([...self::COLUMNAS_LISTA, 'uc.name as creado_por_nombre', 'ua.name as actualizado_por_nombre'])
                ->orderByDesc('colaboradores.id')
                ->get();

            $permitidasVer = $this->colaboradores->sedesPermitidas($actor, 'colaboradores.ver');
            $sedesActivas = Sede::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']);
            $puedeEditar = $actor->can('colaboradores.editar');

            return view('organizacion.colaboradores.index', [
                'sinEmpresa' => false,
                'colaboradores' => $lista,
                'sedesFiltro' => $permitidasVer === null ? $sedesActivas : $sedesActivas->whereIn('id', $permitidasVer)->values(),
                'departamentosFiltro' => Departamento::where(fn ($q) => $q->where('activo', true)
                    ->orWhereIn('id', $lista->pluck('departamento_id')->filter()->unique()->values()))
                    ->orderBy('nombre')->get(['id', 'nombre']),
                'alta' => $actor->can('colaboradores.crear') ? $this->colaboradores->catalogos($actor, 'colaboradores.crear') : null,
                'edicion' => $puedeEditar ? $this->colaboradores->catalogos($actor, 'colaboradores.editar') : null,
                'editables' => $this->colaboradores->idsEnAlcance($actor, 'colaboradores.editar'),
                'desactivables' => $this->colaboradores->idsEnAlcance($actor, 'colaboradores.eliminar'),
                'sedesEditables' => $puedeEditar ? $sedesActivas->when(
                    ($p = $this->colaboradores->sedesPermitidas($actor, 'colaboradores.editar')) !== null,
                    fn ($c) => $c->whereIn('id', $p)->values(),
                ) : collect(),
                // Para "Es duplicado de…": colaboradores ya validados y activos
                'validados' => $actor->can('colaboradores.aprobar')
                    ? $lista->where('provisional', false)->where('activo', true)->sortBy(fn ($c) => $c->nombreCompleto())->values()
                    : collect(),
                'aprobables' => $this->colaboradores->idsEnAlcance($actor, 'colaboradores.aprobar'),
                'validacion' => $actor->can('colaboradores.aprobar') ? $this->colaboradores->catalogos($actor, 'colaboradores.aprobar') : null,
                'puede' => [
                    'crear' => $actor->can('colaboradores.crear'),
                    'provisional' => ! $actor->can('colaboradores.crear') && $actor->can('colaboradores.provisional'),
                    'aprobar' => $actor->can('colaboradores.aprobar'),
                    'editar' => $puedeEditar,
                    'estado' => $actor->can('colaboradores.eliminar'),
                    'datos' => $actor->can('colaboradores.datos_personales'),
                    'sedesAdicionales' => $puedeEditar && $sedesActivas->count() > 1,
                ],
            ]);
        });
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('colaboradores.crear');
        $empresaId = $this->empresaDeTrabajo($request);

        $colaborador = $this->tenant->conEmpresa($empresaId, fn () => $this->colaboradores->crear($request->user(), $empresaId, $request));

        return redirect()->route('colaboradores.index')->with('ok', "Colaborador «{$colaborador->nombreCompleto()}» creado correctamente.");
    }

    public function update(Request $request, int $colaborador): RedirectResponse
    {
        Gate::authorize('colaboradores.editar');
        $empresaId = $this->empresaDeTrabajo($request);

        $modelo = $this->tenant->conEmpresa($empresaId, function () use ($request, $empresaId, $colaborador) {
            $modelo = $this->buscarEnAlcance($request->user(), $colaborador, 'colaboradores.editar');

            return $this->colaboradores->actualizar($request->user(), $empresaId, $modelo, $request);
        });

        return redirect()->route('colaboradores.index')->with('ok', "Colaborador «{$modelo->nombreCompleto()}» actualizado correctamente.");
    }

    /**
     * Baja lógica y reingreso (SEGCAT: "eliminar" = estatus 0, nunca se borra:
     * responsivas, pases y otras bitácoras dependen del colaborador).
     */
    public function estado(Request $request, int $colaborador): RedirectResponse
    {
        Gate::authorize('colaboradores.eliminar');
        $empresaId = $this->empresaDeTrabajo($request);
        $activo = $request->boolean('activo');

        $modelo = $this->tenant->conEmpresa($empresaId, function () use ($request, $colaborador, $activo) {
            $modelo = $this->buscarEnAlcance($request->user(), $colaborador, 'colaboradores.eliminar');
            $this->colaboradores->cambiarEstado($request->user(), $modelo, $activo);

            return $modelo;
        });

        return redirect()->route('colaboradores.index')->with($activo ? 'ok' : 'aviso', $activo
            ? "Colaborador «{$modelo->nombreCompleto()}» reingresado."
            : "Colaborador «{$modelo->nombreCompleto()}» dado de baja. Puedes reactivarlo con el mismo botón cuando quieras.");
    }

    public function sedes(Request $request, int $colaborador): RedirectResponse
    {
        Gate::authorize('colaboradores.editar');
        $empresaId = $this->empresaDeTrabajo($request);
        $request->validate(['sedes' => ['nullable', 'array'], 'sedes.*' => ['integer']]);

        $modelo = $this->tenant->conEmpresa($empresaId, function () use ($request, $colaborador) {
            $modelo = $this->buscarEnAlcance($request->user(), $colaborador, 'colaboradores.editar');
            $this->colaboradores->actualizarSedes($request->user(), $modelo, (array) $request->input('sedes', []));

            return $modelo;
        });

        return redirect()->route('colaboradores.index')->with('ok', "Sedes adicionales de «{$modelo->nombreCompleto()}» actualizadas.");
    }

    /**
     * Datos personales para el diálogo de edición: se piden solo al abrirlo,
     * así la lista nunca lleva CURP, RFC ni NSS en su HTML.
     */
    public function datosPersonales(Request $request, int $colaborador): JsonResponse
    {
        Gate::authorize('colaboradores.datos_personales');
        $empresaId = $this->empresaDeTrabajo($request);

        $modelo = $this->tenant->conEmpresa($empresaId, fn () => $this->buscarEnAlcance($request->user(), $colaborador, 'colaboradores.datos_personales'));

        $datos = $modelo->only(Colaborador::DATOS_PERSONALES);
        $datos['fecha_nacimiento'] = $modelo->fecha_nacimiento?->format('Y-m-d');

        return response()->json($datos)->header('Cache-Control', 'no-store, private');
    }

    /**
     * Registro rápido desde otros módulos (SEGCAT: colaborador_registro_rapido_proceso.php).
     * 201 con el colaborador; 422 con los errores de captura.
     */
    public function rapido(Request $request): JsonResponse
    {
        $actor = $request->user();
        $completo = $actor->can('colaboradores.crear');
        abort_unless($completo || $actor->can('colaboradores.provisional'), 403);

        $empresaId = $this->empresa->id($actor);
        if ($empresaId === null) {
            return response()->json(['ok' => false, 'mensaje' => 'Elige primero la empresa de trabajo.', 'errores' => []], 422);
        }

        try {
            $resumen = $this->tenant->conEmpresa($empresaId, fn () => $this->colaboradores->resumen($completo
                ? $this->colaboradores->crear($actor, $empresaId, $request, rapido: true)
                : $this->colaboradores->crearProvisional($actor, $empresaId, $request)));
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'mensaje' => collect($e->errors())->flatten()->first(), 'errores' => $e->errors()], 422);
        } catch (ColaboradorParecido $e) {
            return response()->json([
                'ok' => false,
                'mensaje' => 'Ya hay alguien registrado con ese nombre. Si es la misma persona, úsala; si no, confirma que es otra.',
                'parecidos' => $e->parecidos,
            ], 409);
        }

        return response()->json(['ok' => true, 'colaborador' => $resumen], 201);
    }

    /**
     * Recursos Humanos valida un alta provisional (le asigna su número de empleado).
     */
    public function validar(Request $request, int $colaborador): RedirectResponse
    {
        Gate::authorize('colaboradores.aprobar');
        $empresaId = $this->empresaDeTrabajo($request);

        $modelo = $this->tenant->conEmpresa($empresaId, function () use ($request, $empresaId, $colaborador) {
            $modelo = $this->buscarEnAlcance($request->user(), $colaborador, 'colaboradores.aprobar');

            return $this->colaboradores->validarProvisional($request->user(), $empresaId, $modelo, $request);
        });

        return redirect()->route('colaboradores.index')->with('ok', "«{$modelo->nombreCompleto()}» validado con el número {$modelo->num_empleado}.");
    }

    /**
     * El alta provisional era alguien que ya existía: se une con su registro.
     */
    public function fusionar(Request $request, int $colaborador): RedirectResponse
    {
        Gate::authorize('colaboradores.aprobar');
        $empresaId = $this->empresaDeTrabajo($request);
        // Seguridad (AZ-03): primero el registro (404 si es ajeno), luego la captura
        $this->tenant->conEmpresa($empresaId, fn () => $this->buscarEnAlcance($request->user(), $colaborador, 'colaboradores.aprobar'));
        $destinoId = (int) $request->validate(['destino_id' => ['required', 'integer']], ['destino_id.required' => 'Elige el colaborador correcto.'])['destino_id'];

        [$provisional, $destino] = $this->tenant->conEmpresa($empresaId, function () use ($request, $colaborador, $destinoId) {
            $provisional = $this->buscarEnAlcance($request->user(), $colaborador, 'colaboradores.aprobar');
            $destino = $this->colaboradores->limitar(Colaborador::query(), $request->user(), 'colaboradores.aprobar')->find($destinoId);
            if ($destino === null) {
                throw ValidationException::withMessages(['destino_id' => 'Elige un colaborador activo y ya validado.']);
            }
            $this->colaboradores->fusionar($request->user(), $provisional, $destino);

            return [$provisional, $destino];
        });

        return redirect()->route('colaboradores.index')->with('ok', "«{$provisional->nombreCompleto()}» se unió con «{$destino->nombreCompleto()}» (#{$destino->num_empleado}).");
    }

    /**
     * Aviso en vivo del Alta de Colaborador: ¿ya hay alguien activo con el
     * mismo nombre (sin importar mayúsculas ni acentos)? Es solo un aviso:
     * Recursos Humanos decide si es otra persona.
     * ?nombre=Daniela&apellido_paterno=Canul&apellido_materno=May
     */
    public function homonimos(Request $request): JsonResponse
    {
        Gate::authorize('colaboradores.crear');
        $empresaId = $this->empresa->id($request->user());
        $nombre = trim(Entrada::texto($request->query('nombre')));
        $paterno = trim(Entrada::texto($request->query('apellido_paterno')));
        $materno = trim(Entrada::texto($request->query('apellido_materno')));
        if ($empresaId === null || $nombre === '' || $paterno === '' || mb_strlen($nombre.$paterno.$materno) > 180) {
            return response()->json(['parecidos' => [], 'mensaje' => null]);
        }

        $todos = $this->tenant->conEmpresa($empresaId, fn () => $this->colaboradores->parecidos($nombre, $paterno, $materno === '' ? null : $materno));
        // Con alcance de sede, los de otras sedes solo se cuentan (sin sus datos)
        $permitidas = $this->colaboradores->sedesPermitidas($request->user(), 'colaboradores.crear');
        $parecidos = array_values(array_filter($todos, fn (array $c) => $permitidas === null || in_array((int) $c['sede_id'], $permitidas, true)));
        $otros = count($todos) - count($parecidos);

        return response()->json([
            'parecidos' => $parecidos,
            'otros' => $otros,
            'mensaje' => $todos === [] ? null : (count($todos) === 1
                ? 'Ya existe un colaborador con ese nombre'.($otros > 0 ? ' en una sede que no tienes a cargo' : '').'. ¿Es la misma persona? Si lo es, no lo des de alta otra vez: búscalo en la lista.'
                : 'Ya existen colaboradores con ese nombre'.($otros > 0 ? " ({$otros} en sedes que no tienes a cargo)" : '').'. ¿Es alguno de ellos? Si lo es, no lo des de alta otra vez: búscalo en la lista.'),
        ]);
    }

    /**
     * Búsqueda para autocompletar (SEGCAT: usuarios/colaborador_buscar_ajax.php):
     * por número de empleado o nombre, mínimo 2 letras, máximo 15 resultados,
     * solo activos, de la empresa y de las sedes del usuario.
     */
    public function buscar(Request $request): JsonResponse
    {
        $actor = $request->user();
        $permisos = array_values(array_filter(['colaboradores.ver', 'colaboradores.provisional', 'usuarios.crear', 'usuarios.editar'], fn ($p) => $actor->can($p)));
        abort_if($permisos === [], 403);

        $empresaId = $this->empresa->id($actor);
        if ($empresaId === null) {
            return response()->json(['resultados' => [], 'todas_ya_tienen_usuario' => false]);
        }

        // La sede más amplia entre los permisos que dan acceso
        $sedes = [];
        foreach ($permisos as $permiso) {
            $permitidas = $this->colaboradores->sedesPermitidas($actor, $permiso);
            if ($permitidas === null) {
                $sedes = null;
                break;
            }
            $sedes = array_values(array_unique([...$sedes, ...$permitidas]));
        }

        $resultado = $this->tenant->conEmpresa($empresaId, fn () => $this->colaboradores->buscar(
            Entrada::texto($request->query('q', '')),
            $sedes,
            $request->boolean('sin_usuario'),
            $request->filled('usuario') ? (int) $request->query('usuario') : null,
        ));

        return response()->json($resultado);
    }

    private function empresaDeTrabajo(Request $request): int
    {
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        return $empresaId;
    }

    /**
     * Un colaborador de otra empresa o fuera del alcance del permiso responde 404.
     */
    private function buscarEnAlcance(User $actor, int $id, string $permiso): Colaborador
    {
        $modelo = $this->colaboradores->limitar(Colaborador::query(), $actor, $permiso)->find($id);
        abort_if($modelo === null, 404);

        return $modelo;
    }
}
