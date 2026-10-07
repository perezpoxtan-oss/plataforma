<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Autenticacion\Autenticador;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SesionController extends Controller
{
    public function mostrar(): View
    {
        return view('auth.login');
    }

    public function iniciar(Request $request, Autenticador $autenticador): RedirectResponse
    {
        $datos = $request->validate([
            'username' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'max:255'],
        ], [], ['username' => 'usuario o correo', 'password' => 'contraseña']);

        $llaveIp = 'acceso-ip:'.$request->ip();
        if (RateLimiter::tooManyAttempts($llaveIp, (int) config('plataforma.sesion.intentos_por_ip'))) {
            return $this->rechazar('equipo', (int) ceil(RateLimiter::availableIn($llaveIp) / 60));
        }
        RateLimiter::hit($llaveIp, 60);

        $resultado = $autenticador->intentar($datos['username'], $datos['password']);

        if (! $resultado->exitoso()) {
            return $this->rechazar($resultado->estado, $resultado->minutos);
        }

        Auth::login($resultado->usuario);
        $request->session()->regenerate();
        $request->session()->put('ultima_actividad', now()->getTimestamp());
        // Ronda 6 (LL-08): marca nueva en cada inicio de sesión; con ella el navegador olvida los filtros guardados antes
        $request->session()->put('marca_filtros', Str::random(16));

        return redirect()->to($this->destinoSeguro($request));
    }

    /**
     * Pantalla que el usuario intentaba abrir, solo si es de este mismo sitio
     * (nunca otro dominio, ni "//otro-sitio").
     */
    private function destinoSeguro(Request $request): string
    {
        $destino = (string) $request->session()->pull('url.intended', '');
        $partes = parse_url($destino);

        $mismoSitio = $destino !== ''
            && $partes !== false
            && ($partes['host'] ?? null) === $request->getHost()
            && in_array($partes['scheme'] ?? null, ['http', 'https'], true);

        return $mismoSitio ? $destino : route('panel');
    }

    public function cerrar(Request $request): RedirectResponse
    {
        $this->terminar($request);

        return redirect()->route('login');
    }

    /**
     * Aviso de inactividad del navegador (POST con token CSRF): cierra y avisa.
     */
    public function expirada(Request $request): RedirectResponse
    {
        $this->terminar($request);

        return redirect()->route('login')->with('acceso', 'expirado');
    }

    /**
     * Abrir el enlace de "sesión expirada" (GET) ya no cierra la sesión: otro
     * sitio podría sacar al usuario con solo un enlace o una imagen. Si la
     * sesión sigue viva vuelve al panel; si ya terminó, muestra el aviso.
     */
    public function avisoExpirada(Request $request): RedirectResponse
    {
        if ($request->user() !== null) {
            return redirect()->route('panel');
        }

        return redirect()->route('login')->with('acceso', 'expirado');
    }

    /**
     * Latido: mantiene viva la sesion mientras haya actividad real en la pantalla.
     */
    public function latido(): JsonResponse
    {
        return response()->json(['ok' => true]);
    }

    private function terminar(Request $request): void
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    private function rechazar(string $motivo, int $minutos = 0): RedirectResponse
    {
        return redirect()->route('login')
            ->withInput(request()->only('username'))
            ->with('acceso', $motivo)
            ->with('minutos', $minutos);
    }
}
