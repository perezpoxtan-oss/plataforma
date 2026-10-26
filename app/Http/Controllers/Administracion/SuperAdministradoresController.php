<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Plataforma\SuperAdministradores;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Configuración → Plataforma → Super Administradores (C-36, DD-21, E-14).
 * Solo para quien es Super Administrador (identidad de la plataforma, no un
 * permiso de rol). Cada acción pide su contraseña; las reglas viven en
 * Plataforma\SuperAdministradores.
 */
class SuperAdministradoresController extends Controller
{
    private const VOLVER = 'cfg-superadmins';

    public function __construct(private readonly SuperAdministradores $super) {}

    public function store(Request $request): RedirectResponse
    {
        $this->soloSuperadmin($request);
        [$cuenta, $enviado] = $this->super->crear($request->user(), $request->only(['name', 'username', 'email', 'password', 'contrasena_actual']));

        return $this->volver()->with('ok', $enviado
            ? "Listo: «{$cuenta->name}» ya es Super Administrador. Le enviamos un enlace de bienvenida (vale 24 horas) para crear su contraseña; al entrar activará sus dos pasos."
            : "Listo: «{$cuenta->name}» ya es Super Administrador. Dale en persona la contraseña temporal; al entrar la cambiará y activará sus dos pasos.");
    }

    public function estado(Request $request, int $cuenta): RedirectResponse
    {
        $this->soloSuperadmin($request);
        $activo = $request->boolean('activo');
        $usuario = $this->cuenta($cuenta);
        $this->super->cambiarEstado($request->user(), $usuario, $activo, (string) $request->input('contrasena_actual', ''));

        return $this->volver()->with('ok', $activo ? "«{$usuario->name}» reactivado: ya puede entrar." : "«{$usuario->name}» dado de baja: ya no puede entrar y su sesión se cerró.");
    }

    public function contrasena(Request $request, int $cuenta): RedirectResponse
    {
        $this->soloSuperadmin($request);
        $usuario = $this->cuenta($cuenta);
        $enviado = $this->super->restablecerContrasena($request->user(), $usuario, $request->input('password'), (string) $request->input('contrasena_actual', ''));

        return $this->volver()->with('ok', $enviado
            ? "Le enviamos a «{$usuario->name}» un enlace para crear su contraseña nueva (vale 60 minutos). Su contraseña anterior ya no sirve."
            : "Contraseña de «{$usuario->name}» restablecida: dásela en persona; la cambiará al entrar.");
    }

    public function dosPasos(Request $request, int $cuenta): RedirectResponse
    {
        $this->soloSuperadmin($request);
        $usuario = $this->cuenta($cuenta);
        $this->super->restablecerDosPasos($request->user(), $usuario, (string) $request->input('contrasena_actual', ''));

        return $this->volver()->with('ok', "Quitamos la verificación en dos pasos de «{$usuario->name}» y cerramos sus sesiones. Al entrar la activará con su teléfono nuevo.");
    }

    private function soloSuperadmin(Request $request): void
    {
        abort_unless($request->user()->es_superadmin, 403);
    }

    private function cuenta(int $id): User
    {
        return User::where('es_superadmin', true)->findOrFail($id);
    }

    private function volver(): RedirectResponse
    {
        return redirect()->to(route('configuracion.index').'#'.self::VOLVER);
    }
}
