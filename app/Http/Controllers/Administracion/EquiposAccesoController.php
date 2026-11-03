<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\EquipoAcceso;
use App\Models\MarcajeEquipoAcceso;
use App\Services\EquiposAcceso\AdministradorEquipos;
use App\Services\EquiposAcceso\Conectores;
use App\Services\EquiposAcceso\SincronizacionUsuarios;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Autorizador;
use App\Support\AjustesEquiposAcceso;
use App\Support\Tenancy\EmpresaDeTrabajo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Configuración → Caseta y accesos → Equipos de acceso (decisión 10).
 * Ver docs/tecnico/equipos-acceso.md y docs/usuario/equipos-de-acceso.md.
 */
class EquiposAccesoController extends Controller
{
    public function __construct(
        private readonly AdministradorEquipos $equipos,
        private readonly EmpresaDeTrabajo $empresa,
    ) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        abort_unless($this->equipos->puedeVer($actor), 403);
        $empresaId = $this->empresa->id($actor);
        if ($empresaId === null) {
            return view('administracion.equipos-acceso.index', ['sinEmpresa' => true]);
        }
        $visibles = $this->equipos->sedesVisibles($actor);
        $equipos = EquipoAcceso::with('sede:id,nombre,zona_horaria,empresa_id')
            ->withCount(['comandos as pendientes' => fn ($q) => $q->whereIn('estado', ['pendiente', 'enviado'])])
            ->withCount(['comandos as errores' => fn ($q) => $q->where('estado', 'error')])
            ->when($visibles !== null, fn ($q) => $q->whereIn('sede_id', $visibles))
            ->orderByDesc('activo')->orderBy('nombre')->get();
        $marcajes = MarcajeEquipoAcceso::with('colaborador:id,nombre,apellido_paterno,apellido_materno,num_empleado')
            ->whereIn('equipo_acceso_id', $equipos->pluck('id'))->orderByDesc('id')->limit(400)->get()
            ->groupBy('equipo_acceso_id')->map(fn ($g) => $g->take(10));
        $empresa = Empresa::find($empresaId);

        return view('administracion.equipos-acceso.index', [
            'sinEmpresa' => false,
            'equipos' => $equipos,
            'marcajes' => $marcajes,
            'sedesAlta' => $this->equipos->sedesParaAlta($actor),
            'marcas' => Conectores::nombres(),
            'ajustes' => AjustesEquiposAcceso::de($empresa),
            'editaAjustes' => app(Autorizador::class)->alcanceDeEmpresa($actor, 'configuracion.editar'),
            'puedeEditar' => fn (EquipoAcceso $e) => $this->equipos->puedeEditar($actor, $e),
            'tokenNuevo' => session('token_equipo'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor->can('configuracion.editar') && $this->empresa->id($actor) !== null, 403);
        [$equipo, $token] = $this->equipos->crear($actor, $request->only(['sede_id', 'nombre', 'marca', 'modelo', 'numero_serie', 'puerta', 'sentido', 'ips_permitidas']));

        return redirect()->to(route('equipos-acceso.index').'#equipo-'.$equipo->id)
            ->with('ok', "Equipo «{$equipo->nombre}» guardado. Ahora configúralo con los pasos de su tarjeta.")
            ->with('token_equipo', ['id' => $equipo->id, 'token' => $token]);
    }

    public function update(Request $request, EquipoAcceso $equipo): RedirectResponse
    {
        $this->exigirEditar($request, $equipo);
        $this->equipos->actualizar($request->user(), $equipo, $request->only(['sede_id', 'nombre', 'modelo', 'numero_serie', 'puerta', 'sentido', 'ips_permitidas']));

        return redirect()->to(route('equipos-acceso.index').'#equipo-'.$equipo->id)->with('ok', "Equipo «{$equipo->nombre}» guardado.");
    }

    public function estado(Request $request, EquipoAcceso $equipo): RedirectResponse
    {
        $this->exigirEditar($request, $equipo);
        $activo = $request->boolean('activo');
        $this->equipos->cambiarEstado($request->user(), $equipo, $activo);

        return redirect()->to(route('equipos-acceso.index').'#equipo-'.$equipo->id)->with('ok', $activo
            ? "Equipo «{$equipo->nombre}» reactivado: vuelve a registrar entradas y salidas."
            : "Equipo «{$equipo->nombre}» dado de baja: la plataforma ya no acepta sus llamadas. La caseta registra a mano.");
    }

    public function token(Request $request, EquipoAcceso $equipo): RedirectResponse
    {
        $this->exigirEditar($request, $equipo);
        $token = $this->equipos->nuevoToken($request->user(), $equipo);

        return redirect()->to(route('equipos-acceso.index').'#equipo-'.$equipo->id)
            ->with('ok', 'Token nuevo generado: el anterior ya no sirve. Cópialo en el equipo ahora.')
            ->with('token_equipo', ['id' => $equipo->id, 'token' => $token]);
    }

    public function sincronizar(Request $request, EquipoAcceso $equipo, SincronizacionUsuarios $sincronizacion): RedirectResponse
    {
        $this->exigirEditar($request, $equipo);
        if (! Conectores::para($equipo->marca)->sincronizaUsuarios()) {
            throw ValidationException::withMessages(['sincronizar' => 'Este equipo no recibe altas y bajas desde la plataforma: cárgalas en el equipo.']);
        }
        abort_unless($equipo->activo, 404);
        $r = $sincronizacion->equipo($equipo, $request->user());

        return redirect()->to(route('equipos-acceso.index').'#equipo-'.$equipo->id)->with('ok',
            "Sincronización en cola: {$r['altas']} alta(s) y {$r['bajas']} baja(s). El equipo las recoge en su siguiente llamada (menos de un minuto si está en línea).");
    }

    public function ajustes(Request $request, AdministradorRoles $auditoria): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor->can('configuracion.editar') && app(Autorizador::class)->alcanceDeEmpresa($actor, 'configuracion.editar'), 403,
            'Este ajuste es de toda la empresa: hace falta el permiso «editar» de Configuración con alcance de empresa.');
        $empresaId = $this->empresa->id($actor);
        abort_if($empresaId === null, 404);
        $datos = $request->validate(['sin_conexion_horas' => ['required', 'integer', 'min:'.AjustesEquiposAcceso::MINIMO, 'max:'.AjustesEquiposAcceso::MAXIMO]],
            ['sin_conexion_horas.*' => 'Escribe las horas sin conexión de '.AjustesEquiposAcceso::MINIMO.' a '.AjustesEquiposAcceso::MAXIMO.'.']);
        $empresa = Empresa::findOrFail($empresaId);
        $antes = AjustesEquiposAcceso::de($empresa);
        $preferencias = $empresa->preferencias ?? [];
        $horas = (int) $datos['sin_conexion_horas'];
        if ($horas === AjustesEquiposAcceso::OMISION['sin_conexion_horas']) {
            unset($preferencias[AjustesEquiposAcceso::CLAVE]);
        } else {
            $preferencias[AjustesEquiposAcceso::CLAVE] = ['sin_conexion_horas' => $horas];
        }
        $empresa->forceFill(['preferencias' => $preferencias])->save();
        $auditoria->auditar($actor, 'configuracion.equipos_acceso', $empresa, $antes, AjustesEquiposAcceso::de($empresa->refresh()));

        return redirect()->to(route('equipos-acceso.index').'#ajustes-equipos')->with('ok', "Guardado: se avisa al jefe de seguridad si un equipo pasa {$horas} h sin conexión.");
    }

    private function exigirEditar(Request $request, EquipoAcceso $equipo): void
    {
        $actor = $request->user();
        abort_unless($actor->can('configuracion.editar'), 403);
        // Fuera de su alcance: como si no existiera
        abort_unless($this->equipos->puedeEditar($actor, $equipo), 404);
    }
}
