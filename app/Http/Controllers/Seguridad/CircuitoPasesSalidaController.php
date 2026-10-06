<?php

namespace App\Http\Controllers\Seguridad;

use App\Http\Controllers\Controller;
use App\Models\Departamento;
use App\Models\Rol;
use App\Models\User;
use App\Services\PasesSalida\CircuitoPasesSalida;
use App\Services\Permisos\Autorizador;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Configuración → Pases de salida: el circuito de aprobación de la empresa
 * (pasos en orden, quién firma cada uno, obligatorio u opcional, motivos).
 * Lo consulta quien tiene "pases_salida.configurar"; lo cambia solo quien lo
 * tiene con alcance de toda la empresa (es una regla de toda la empresa,
 * como los Avisos por correo).
 */
class CircuitoPasesSalidaController extends Controller
{
    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly CircuitoPasesSalida $circuito,
        private readonly Autorizador $autorizador,
    ) {}

    public function edit(Request $request): View
    {
        Gate::authorize('pases_salida.configurar');
        $empresaId = $this->empresa->id($request->user());

        if ($empresaId === null) {
            return view('seguridad.pases-salida.circuito', ['sinEmpresa' => true]);
        }

        return $this->tenant->conEmpresa($empresaId, fn () => view('seguridad.pases-salida.circuito', [
            'sinEmpresa' => false,
            'pasos' => $this->circuito->pasos(),
            'configurado' => $this->circuito->configurado(),
            'resumen' => $this->circuito->resumen(),
            'puedeEditar' => $this->editaEmpresa($request->user()),
            'roles' => Rol::where('empresa_id', $empresaId)->where('activo', true)->orderBy('nivel_jerarquia')->get(['id', 'nombre']),
            'departamentos' => Departamento::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'usuarios' => User::where('empresa_id', $empresaId)->where('activo', true)->orderBy('name')->get(['id', 'name', 'username']),
        ]));
    }

    public function update(Request $request): RedirectResponse
    {
        $empresaId = $this->autorizarEdicion($request);

        try {
            $this->tenant->conEmpresa($empresaId, fn () => $this->circuito->guardar($request->user(), $request->all()));
        } catch (ValidationException $e) {
            return redirect()->route('pases-salida.circuito')->withErrors($e->errors())->withInput();
        }

        return redirect()->route('pases-salida.circuito')->with('ok', 'Circuito de aprobación guardado. Los pases nuevos (y los que se reenvíen) usarán estos pasos; los que ya están en curso conservan los suyos.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $empresaId = $this->autorizarEdicion($request);
        $this->tenant->conEmpresa($empresaId, fn () => $this->circuito->restablecer($request->user()));

        return redirect()->route('pases-salida.circuito')->with('ok', 'Se restableció la cadena de SEGCAT: Jefe de Departamento, Contraloría y Gerencia.');
    }

    private function autorizarEdicion(Request $request): int
    {
        Gate::authorize('pases_salida.configurar');
        // El circuito es de toda la empresa: con alcance de sede solo se consulta
        abort_unless($this->editaEmpresa($request->user()), 403, 'El circuito es de toda la empresa: hace falta el permiso «Configurar» de Pases de salida con alcance de empresa.');
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        return $empresaId;
    }

    private function editaEmpresa(User $actor): bool
    {
        return $actor->can('pases_salida.configurar') && $this->autorizador->alcanceDeEmpresa($actor, 'pases_salida.configurar');
    }
}
