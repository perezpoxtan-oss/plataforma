<?php

namespace App\Http\Controllers\Cuenta;

use App\Http\Controllers\Controller;
use App\Models\Sede;
use App\Models\UsuarioRol;
use App\Rules\ContrasenaSegura;
use App\Services\Cuenta\CuentaPropia;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Mi cuenta: datos de la propia cuenta y «Cambiar mi contraseña». Sin permiso
 * propio: cualquiera que inició sesión. Rol, sedes y correo solo se consultan
 * (los administra Usuarios).
 */
class MiCuentaController extends Controller
{
    public function __construct(
        private readonly CuentaPropia $cuenta,
        private readonly Tenant $tenant,
    ) {}

    public function index(Request $request): View
    {
        $usuario = $request->user()->fresh() ?? $request->user();
        $asignacion = UsuarioRol::where('user_id', $usuario->id)->first(['rol_id', 'sede_id']);
        $sede = $asignacion?->sede_id === null ? null
            : $this->tenant->conEmpresa((int) $usuario->empresa_id, fn () => Sede::whereKey($asignacion->sede_id)->value('nombre'));
        $sedes = $usuario->empresa_id === null ? 0
            : $this->tenant->conEmpresa((int) $usuario->empresa_id, fn () => Sede::where('activo', true)->count());

        return view('cuenta.mi-cuenta', [
            'cuenta' => $usuario,
            'rolNombreCuenta' => $usuario->nombreRolPrincipal(),
            'sedeNombre' => $sede,
            'todasLasSedes' => $asignacion !== null && $asignacion->sede_id === null,
            'sedesEmpresa' => $sedes,
            'empresaNombre' => $usuario->empresa?->nombre_comercial,
            'debeCambiar' => (bool) $usuario->debe_cambiar_contrasena,
        ]);
    }

    public function contrasena(Request $request): RedirectResponse
    {
        $usuario = $request->user();
        $datos = $request->validate([
            'contrasena_actual' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers(),
                new ContrasenaSegura((string) $usuario->username, (string) $usuario->email)],
        ], [
            'password.confirmed' => 'La confirmación no coincide con la contraseña nueva.',
        ], [
            'contrasena_actual' => 'contraseña actual',
            'password' => 'contraseña nueva',
        ]);

        $this->cuenta->cambiarContrasena($usuario, $datos['contrasena_actual'], $datos['password'], $request->session()->getId(), $request->ip());

        return redirect()->route('mi-cuenta.index')->with('ok', 'Tu contraseña cambió y cerramos tus otras sesiones. Ya puedes seguir trabajando: toca «Inicio» para volver al panel.');
    }
}
