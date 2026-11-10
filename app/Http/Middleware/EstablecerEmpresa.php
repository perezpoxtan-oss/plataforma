<?php

namespace App\Http\Middleware;

use App\Models\Suscripcion;
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

        // Suscripciones: en la misma consulta, si la empresa tiene suscripción (las que no, no gastan otra consulta)
        $usuario->loadMissing(['empresa' => fn ($q) => $q->select('empresas.*')
            ->addSelect(['suscripcion_id' => Suscripcion::query()->select('id')->whereColumn('suscripciones.empresa_id', 'empresas.id')->limit(1)])]);

        if (! $usuario->activo || ($usuario->empresa_id !== null && ! $usuario->empresa?->activo)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('acceso', 'inactiva');
        }

        $empresaId = $usuario->es_superadmin
            ? $request->session()->get('empresa_activa_id')
            : $usuario->empresa_id;

        $this->tenant->establecer($empresaId === null ? null : (int) $empresaId);

        return $next($request);
    }
}
