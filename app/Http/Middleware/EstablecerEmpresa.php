<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fija la empresa activa de la peticion y corta la sesion de usuarios
 * desactivados (no esperan a que expire la sesion).
 */
class EstablecerEmpresa
{
    public function __construct(private readonly Tenant $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if ($usuario === null) {
            return $next($request);
        }

        $usuario->loadMissing('empresa');

        if (! $usuario->activo || ($usuario->empresa_id !== null && ! $usuario->empresa?->activo)) {
            Auth::logout();
            $request->session()->invalidate();

            return redirect('/login')->with('error', 'Tu cuenta no esta activa.');
        }

        $empresaId = $usuario->es_superadmin
            ? $request->session()->get('empresa_activa_id')
            : $usuario->empresa_id;

        $this->tenant->establecer($empresaId === null ? null : (int) $empresaId);

        return $next($request);
    }
}
