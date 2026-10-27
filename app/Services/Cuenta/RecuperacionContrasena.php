<?php

namespace App\Services\Cuenta;

use App\Mail\BienvenidaUsuario;
use App\Mail\RestablecerContrasena;
use App\Models\User;
use App\Services\Permisos\AdministradorRoles;
use App\Support\CorreoPlataforma;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;

use function Illuminate\Support\defer;

/**
 * Recuperar y crear la contraseña sin depender de nadie (criterio 6 de
 * docs/criterios-ux.md). Usa el password broker de Laravel (tabla
 * password_reset_tokens) y enlaces firmados:
 *  - «Olvidé mi contraseña» y «Restablecer» desde Usuarios: broker `users`, 60 min;
 *  - «Enlace de bienvenida» de un usuario nuevo: broker `bienvenida`, 24 h.
 * El correo sale por el SMTP de la plataforma (CorreoPlataforma); si no está
 * configurado, la pantalla lo dice antes de pedir nada (eso no revela cuentas).
 */
class RecuperacionContrasena
{
    public const RESTABLECER = 'users';

    public const BIENVENIDA = 'bienvenida';

    /** Solicitudes de «Olvidé mi contraseña» por cuenta y por hora. */
    public const POR_CUENTA_HORA = 3;

    public function __construct(
        private readonly CorreoPlataforma $correo,
        private readonly AdministradorRoles $auditoria,
        private readonly AvisosCuenta $avisos,
    ) {}

    public function correoConfigurado(): bool
    {
        return $this->correo->configurado();
    }

    /**
     * «Olvidé mi contraseña»: responde siempre igual. Solo envía si la cuenta
     * existe, está activa (y su empresa), tiene correo, no pasó de 3 por hora
     * ni pidió otro hace menos de un minuto. Devuelve si se envió (para la
     * bitácora; la pantalla no lo muestra).
     */
    public function solicitar(string $identificador, ?string $ip): bool
    {
        $identificador = trim($identificador);
        if ($identificador === '' || ! $this->correo->configurado()) {
            return false;
        }

        $llave = 'recuperacion-cuenta:'.hash('sha256', mb_strtolower($identificador));
        if (RateLimiter::tooManyAttempts($llave, self::POR_CUENTA_HORA)) {
            return false;
        }
        RateLimiter::hit($llave, 3600);

        $usuario = User::query()->with('empresa')
            ->where(fn ($q) => $q->where('email', $identificador)->orWhere('username', $identificador))
            ->where('activo', true)
            ->first();
        if ($usuario === null || (! $usuario->es_superadmin && ($usuario->empresa_id === null || ! $usuario->empresa?->activo))) {
            return false;
        }
        // A-09: 3 por hora por CUENTA, la escriban por su usuario o por su correo
        $porCuenta = 'recuperacion-cuenta-usuario:'.$usuario->id;
        if (RateLimiter::tooManyAttempts($porCuenta, self::POR_CUENTA_HORA)) {
            return false;
        }
        RateLimiter::hit($porCuenta, 3600);

        $enviado = $this->enviarEnlace($usuario, self::RESTABLECER, null);
        $this->auditoria->auditar($usuario, 'usuarios.recuperacion_solicitada', $usuario, null, [
            'correo_enviado' => $enviado, 'origen' => AvisosCuenta::ipRecortada($ip),
        ]);

        return $enviado;
    }

    /**
     * Enlace para crear la contraseña (restablecer, 60 min; o bienvenida,
     * 24 h). $actor es el administrador que lo pide; null si lo pidió la
     * propia persona. Devuelve false si la cuenta no tiene correo, el SMTP no
     * está configurado o el broker lo acaba de enviar.
     */
    public function enviarEnlace(User $usuario, string $tipo, ?User $actor): bool
    {
        if (! $this->correo->configurado() || filter_var((string) $usuario->email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        $broker = $this->broker($tipo);
        $repositorio = $broker->getRepository();
        if ($tipo === self::RESTABLECER && $repositorio->recentlyCreatedToken($usuario)) {
            return false;
        }
        $token = $repositorio->create($usuario);
        $minutos = (int) config("auth.passwords.{$tipo}.expire", 60);
        $enlace = URL::temporarySignedRoute('contrasena.restablecer', now()->addMinutes($minutos), ['token' => $token, 'u' => $usuario->id, 'tipo' => $tipo]);

        $mensaje = $tipo === self::BIENVENIDA
            ? new BienvenidaUsuario($usuario->name, (string) $usuario->username, $enlace, intdiv($minutos, 60))
            : new RestablecerContrasena($usuario->name, $enlace, $minutos, $actor !== null);
        $para = $usuario->email;
        defer(fn () => $this->correo->enviar([$para], $mensaje));

        if ($actor !== null) {
            $this->auditoria->auditar($actor, $tipo === self::BIENVENIDA ? 'usuarios.bienvenida_enviada' : 'usuarios.enlace_restablecer_enviado', $usuario, null, [
                'minutos' => $minutos,
            ]);
        }

        return true;
    }

    /**
     * Usuario y tipo del enlace recibido (firma válida y token vigente), o null.
     *
     * @return array{0: User, 1: string}|null
     */
    public function usuarioDelEnlace(string $token, mixed $usuarioId, mixed $tipo): ?array
    {
        $tipo = in_array($tipo, [self::RESTABLECER, self::BIENVENIDA], true) ? $tipo : self::RESTABLECER;
        $usuario = is_numeric($usuarioId) ? User::find((int) $usuarioId) : null;
        if ($usuario === null || ! $this->broker($tipo)->tokenExists($usuario, $token)) {
            return null;
        }

        return [$usuario, $tipo];
    }

    /**
     * Guarda la contraseña nueva con el token. Devuelve null si quedó bien o
     * el mensaje para la pantalla.
     *
     * @param  array{email: string, token: string, password: string, password_confirmation: string}  $datos
     */
    public function restablecer(array $datos, string $tipo, ?string $ip): ?string
    {
        $tipo = in_array($tipo, [self::RESTABLECER, self::BIENVENIDA], true) ? $tipo : self::RESTABLECER;
        $completado = null;

        $estado = $this->broker($tipo)->reset($datos, function (CanResetPassword $cuenta, string $contrasena) use (&$completado) {
            /** @var User $cuenta */
            $cuenta->forceFill([
                'password' => $contrasena,
                'debe_cambiar_contrasena' => false,
                'intentos_fallidos' => 0,
                'bloqueado_hasta' => null,
                'actualizado_por' => $cuenta->id,
            ])->save();
            $completado = $cuenta;
        });

        if ($estado !== PasswordBroker::PASSWORD_RESET || $completado === null) {
            return 'El enlace ya no sirve (venció o ya se usó). Pide uno nuevo con «Olvidé mi contraseña».';
        }

        AvisosCuenta::cerrarSesiones($completado);
        $this->auditoria->auditar($completado, 'usuarios.recuperacion_completada', $completado, null, [
            'contrasena' => 'cambiada', 'tipo' => $tipo === self::BIENVENIDA ? 'bienvenida' : 'restablecer', 'origen' => AvisosCuenta::ipRecortada($ip),
        ]);
        $this->avisos->contrasenaCambiada($completado, $tipo === self::BIENVENIDA
            ? 'Creaste tu contraseña con el enlace de bienvenida.'
            : 'La cambiaste con el enlace que llegó a tu correo; tus otras sesiones se cerraron.');

        return null;
    }

    private function broker(string $tipo): PasswordBroker
    {
        /** @var PasswordBroker $broker */
        $broker = Password::broker($tipo);

        return $broker;
    }
}
