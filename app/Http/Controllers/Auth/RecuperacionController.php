<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\ContrasenaSegura;
use App\Services\Cuenta\RecuperacionContrasena;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * «Olvidé mi contraseña» (docs/criterios-ux.md, regla 6). La respuesta a la
 * solicitud es siempre la misma, exista o no la cuenta: nada de lo que se
 * muestra sirve para adivinar qué usuarios hay.
 */
class RecuperacionController extends Controller
{
    public const MENSAJE = 'Si la cuenta existe y tiene correo, te enviamos un enlace para crear tu contraseña nueva. Revisa tu bandeja de entrada (y la de spam).';

    public function __construct(private readonly RecuperacionContrasena $recuperacion) {}

    public function olvide(): View
    {
        return view('auth.olvide', ['correoListo' => $this->recuperacion->correoConfigurado()]);
    }

    public function solicitar(Request $request): RedirectResponse
    {
        $datos = $request->validate(['cuenta' => ['required', 'string', 'max:150']], [], ['cuenta' => 'usuario o correo']);

        $this->recuperacion->solicitar($datos['cuenta'], $request->ip());

        return redirect()->route('contrasena.olvide')->with('enviado', self::MENSAJE);
    }

    /**
     * Enlace del correo (firmado y con token vigente): formulario de contraseña nueva.
     */
    public function restablecer(Request $request, string $token): View|RedirectResponse
    {
        $enlace = $request->hasValidSignature() ? $this->recuperacion->usuarioDelEnlace($token, $request->query('u'), $request->query('tipo')) : null;
        if ($enlace === null) {
            return redirect()->route('contrasena.olvide')->with('error', 'El enlace ya no sirve (venció o ya se usó). Pide uno nuevo aquí.');
        }
        [$usuario, $tipo] = $enlace;
        // El tipo del enlace (60 min o 24 h) se recuerda en la sesión: el formulario no lo puede cambiar
        $request->session()->put('restablecer', ['token' => $token, 'tipo' => $tipo]);

        return view('auth.restablecer', ['token' => $token, 'correo' => $usuario->email, 'nombre' => $usuario->name, 'usuario' => $usuario->username, 'bienvenida' => $tipo === RecuperacionContrasena::BIENVENIDA]);
    }

    public function guardar(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'token' => ['required', 'string', 'max:200'],
            'email' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers(),
                new ContrasenaSegura(User::where('email', (string) $request->input('email'))->value('username'), (string) $request->input('email'))],
        ], [
            'password.confirmed' => 'La confirmación no coincide con la contraseña nueva.',
        ], ['password' => 'contraseña nueva']);

        $recordado = (array) $request->session()->get('restablecer', []);
        $tipo = ($recordado['token'] ?? null) === $datos['token'] ? (string) ($recordado['tipo'] ?? RecuperacionContrasena::RESTABLECER) : RecuperacionContrasena::RESTABLECER;

        $error = $this->recuperacion->restablecer([
            'email' => $datos['email'], 'token' => $datos['token'], 'password' => $datos['password'], 'password_confirmation' => $datos['password'],
        ], $tipo, $request->ip());
        if ($error !== null) {
            return redirect()->route('contrasena.olvide')->with('error', $error);
        }
        $request->session()->forget('restablecer');

        return redirect()->route('login')->with('acceso', 'contrasena_creada');
    }
}
