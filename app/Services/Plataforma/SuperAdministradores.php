<?php

namespace App\Services\Plataforma;

use App\Models\SolicitudCuenta;
use App\Models\User;
use App\Rules\ContrasenaSegura;
use App\Services\Cuenta\AvisosCuenta;
use App\Services\Cuenta\RecuperacionContrasena;
use App\Services\Cuenta\SolicitudesCuenta;
use App\Services\DosPasos\VerificacionDosPasos;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Services\Permisos\AdministradorRoles;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Super Administradores de la plataforma (C-36, DD-21, E-14; docs/tecnico/dos-pasos.md):
 *
 *  - Se exigen DOS activos: mientras haya uno solo, Inicio y Configuración lo
 *    avisan; y no se puede dar de baja a uno si quedaría solo uno.
 *  - Uno restablece la contraseña o la verificación en dos pasos del otro
 *    (sin terminal: el hospedaje no la tiene), con su propia contraseña como
 *    confirmación, auditoría y aviso a la cuenta afectada y a los demás.
 *  - Se dan de alta desde Configuración → Cuentas y seguridad (ya no hace
 *    falta la consola). La consola (plataforma:superadmin, plataforma:dos-pasos)
 *    sigue sirviendo donde sí hay terminal.
 *
 * Solo los usa quien es Super Administrador: es la identidad de la
 * plataforma, no un permiso de rol (los roles son por empresa).
 */
class SuperAdministradores
{
    public const MINIMO = 2;

    public const TIPO_AVISO = 'plataforma_superadmin';

    /** Intentos con la contraseña equivocada antes de esperar (por cuenta), y la espera en segundos. */
    public const INTENTOS_CONTRASENA = 5;

    public const ESPERA_CONTRASENA = 15 * 60;

    public function __construct(
        private readonly AdministradorRoles $auditoria,
        private readonly CentroNotificaciones $campana,
        private readonly RecuperacionContrasena $recuperacion,
        private readonly VerificacionDosPasos $dosPasos,
        private readonly SolicitudesCuenta $solicitudes,
    ) {}

    /** @return Collection<int, User> */
    public function todos(): Collection
    {
        return User::query()->where('es_superadmin', true)->orderByDesc('activo')->orderBy('name')
            ->get(['id', 'name', 'username', 'email', 'activo', 'ultimo_acceso_en', 'two_factor_confirmado_en', 'two_factor_recovery_codes', 'bloqueado_hasta']);
    }

    public function activos(): int
    {
        return User::query()->where('es_superadmin', true)->where('activo', true)->count();
    }

    /** ¿Falta el segundo Super Administrador? */
    public function faltan(): bool
    {
        return $this->activos() < self::MINIMO;
    }

    /**
     * Alta de otro Super Administrador: con enlace de bienvenida si la
     * plataforma tiene correo, o con una contraseña temporal que cambia al
     * entrar. Al entrar, la plataforma le pide activar sus dos pasos.
     *
     * @param  array<string, mixed>  $entrada
     * @return array{0: User, 1: bool} cuenta y si se envió el enlace
     */
    public function crear(User $actor, array $entrada): array
    {
        $this->exigirSuperadmin($actor);
        $conEnlace = $this->recuperacion->correoConfigurado() && ! filled($entrada['password'] ?? null);
        $d = Validator::make($entrada, [
            'name' => ['required', 'string', 'max:150'],
            'username' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('users', 'username')],
            'email' => ['required', 'email:rfc', 'max:150', Rule::unique('users', 'email')],
            'password' => [$conEnlace ? 'nullable' : 'required', 'string', Password::min(8)->letters()->numbers(),
                new ContrasenaSegura((string) ($entrada['username'] ?? ''), (string) ($entrada['email'] ?? ''))],
            'contrasena_actual' => ['required', 'string'],
        ], [
            'username.unique' => 'Ya existe otra cuenta con ese nombre de usuario.',
            'email.unique' => 'Ya existe otra cuenta con ese correo.',
            'username.regex' => 'El nombre de usuario solo puede tener letras, números, punto, guion y guion bajo (sin espacios).',
            'password.required' => 'La plataforma no tiene correo configurado: escribe una contraseña temporal (la cambiará al entrar).',
        ], ['name' => 'nombre completo', 'username' => 'nombre de usuario', 'email' => 'correo', 'password' => 'contraseña temporal', 'contrasena_actual' => 'tu contraseña'])->validate();
        $this->exigirContrasena($actor, $d['contrasena_actual']);

        $cuenta = new User;
        $cuenta->fill(['name' => trim($d['name']), 'username' => trim($d['username']), 'email' => mb_strtolower(trim($d['email'])),
            'password' => $conEnlace ? Str::password(40) : $d['password'], 'activo' => true]);
        $cuenta->empresa_id = null;
        $cuenta->forceFill(['es_superadmin' => true, 'debe_cambiar_contrasena' => true, 'creado_por' => $actor->id, 'actualizado_por' => $actor->id])->save();
        $this->auditoria->auditar($actor, 'plataforma.superadmin_creado', $cuenta, null, ['name' => $cuenta->name, 'username' => $cuenta->username, 'email' => $cuenta->email]);
        $enviado = $conEnlace && $this->recuperacion->enviarEnlace($cuenta, RecuperacionContrasena::BIENVENIDA, $actor);
        $this->avisarOtros($actor, $cuenta, 'Nuevo Super Administrador: '.$cuenta->name, $actor->name.' dio de alta a '.$cuenta->name.' (@'.$cuenta->username.') como Super Administrador.');

        return [$cuenta, $enviado];
    }

    /** Dar de baja o reactivar. Nunca deja menos de dos activos (si ya había dos o más). */
    public function cambiarEstado(User $actor, User $cuenta, bool $activo, string $contrasena): void
    {
        $this->exigirSobreOtro($actor, $cuenta);
        $this->exigirContrasena($actor, $contrasena);
        if (! $activo && $cuenta->activo && $this->activos() - 1 < self::MINIMO) {
            throw ValidationException::withMessages(['superadmin' => 'Debe haber al menos dos Super Administradores activos, para que nadie quede fuera de la plataforma si pierde su teléfono. Da de alta otro antes de dar de baja a '.$cuenta->name.'.']);
        }
        $antes = $cuenta->activo;
        $cuenta->forceFill(['activo' => $activo, 'actualizado_por' => $actor->id])->save();
        if (! $activo) {
            AvisosCuenta::cerrarSesiones($cuenta);
        }
        $this->auditoria->auditar($actor, $activo ? 'plataforma.superadmin_reactivado' : 'plataforma.superadmin_dado_de_baja', $cuenta, ['activo' => $antes], ['activo' => $activo]);
        $this->avisarOtros($actor, $cuenta, ($activo ? 'Super Administrador reactivado: ' : 'Super Administrador dado de baja: ').$cuenta->name,
            $actor->name.($activo ? ' reactivó' : ' dio de baja').' la cuenta de Super Administrador de '.$cuenta->name.'.');
    }

    /**
     * Restablecer la contraseña de otro Super Administrador: enlace por correo
     * (si la plataforma tiene correo) o contraseña temporal que cambia al entrar.
     *
     * @return bool si se envió el enlace
     */
    public function restablecerContrasena(User $actor, User $cuenta, ?string $nueva, string $contrasena): bool
    {
        $this->exigirSobreOtro($actor, $cuenta);
        $this->exigirContrasena($actor, $contrasena);
        $conEnlace = $this->recuperacion->correoConfigurado() && ! filled($nueva);
        if (! $conEnlace) {
            Validator::make(['password' => $nueva], ['password' => ['required', 'string', Password::min(8)->letters()->numbers(), new ContrasenaSegura((string) $cuenta->username, (string) $cuenta->email)]],
                ['password.required' => 'Escribe una contraseña temporal (la cambiará al entrar).'], ['password' => 'contraseña temporal'])->validate();
        }
        $cuenta->forceFill(['password' => $conEnlace ? Str::password(40) : (string) $nueva, 'debe_cambiar_contrasena' => true, 'intentos_fallidos' => 0,
            'bloqueado_hasta' => null, 'actualizado_por' => $actor->id])->save();
        AvisosCuenta::cerrarSesiones($cuenta);
        $enviado = $conEnlace && $this->recuperacion->enviarEnlace($cuenta, RecuperacionContrasena::RESTABLECER, $actor);
        $this->auditoria->auditar($actor, 'plataforma.superadmin_contrasena', $cuenta, null, ['contrasena' => $conEnlace ? 'enlace' : 'temporal', 'debe_cambiar_contrasena' => true]);
        $this->solicitudes->atendidas($cuenta, SolicitudCuenta::CONTRASENA, $actor);
        $this->avisarOtros($actor, $cuenta, 'Contraseña restablecida: '.$cuenta->name, $actor->name.' restableció la contraseña del Super Administrador '.$cuenta->name.'.');

        return $enviado;
    }

    /** Quitar los dos pasos de otro Super Administrador que perdió su teléfono y sus códigos de respaldo. */
    public function restablecerDosPasos(User $actor, User $cuenta, string $contrasena): void
    {
        $this->exigirSobreOtro($actor, $cuenta);
        $this->exigirContrasena($actor, $contrasena);
        if (! $cuenta->tieneDosPasos()) {
            throw ValidationException::withMessages(['superadmin' => $cuenta->name.' no tiene activa la verificación en dos pasos: al entrar se le pedirá activarla.']);
        }
        $this->dosPasos->restablecerSuperadmin($actor, $cuenta);
        $this->solicitudes->atendidas($cuenta, SolicitudCuenta::DOS_PASOS, $actor);
        $this->avisarOtros($actor, $cuenta, 'Verificación en dos pasos restablecida: '.$cuenta->name,
            $actor->name.' quitó la verificación en dos pasos del Super Administrador '.$cuenta->name.'. Al entrar, la activará con su teléfono nuevo.');
    }

    private function exigirSuperadmin(User $actor): void
    {
        abort_unless($actor->es_superadmin && $actor->activo, 403);
    }

    private function exigirSobreOtro(User $actor, User $cuenta): void
    {
        $this->exigirSuperadmin($actor);
        abort_unless($cuenta->es_superadmin, 404);
        if ($cuenta->id === $actor->id) {
            throw ValidationException::withMessages(['superadmin' => 'Tu propia cuenta se cambia en Mi cuenta.']);
        }
    }

    /**
     * La contraseña del actor confirma la acción. Con una sesión robada no se
     * puede adivinar: tras INTENTOS_CONTRASENA errores se espera 15 minutos.
     */
    private function exigirContrasena(User $actor, string $contrasena): void
    {
        $llave = 'superadmin-confirmar:'.$actor->id;
        if (RateLimiter::tooManyAttempts($llave, self::INTENTOS_CONTRASENA)) {
            $minutos = (int) ceil(RateLimiter::availableIn($llave) / 60);
            throw ValidationException::withMessages(['contrasena_actual' => "Escribiste mal tu contraseña demasiadas veces. Espera {$minutos} minuto(s) e inténtalo de nuevo."]);
        }
        if (! Hash::check($contrasena, (string) $actor->password)) {
            RateLimiter::hit($llave, self::ESPERA_CONTRASENA);
            throw ValidationException::withMessages(['contrasena_actual' => 'Tu contraseña no es correcta.']);
        }
        RateLimiter::clear($llave);
    }

    /** Campana de los demás Super Administradores activos (también de la cuenta afectada si sigue activa). */
    private function avisarOtros(User $actor, User $cuenta, string $titulo, string $texto): void
    {
        $ids = User::where('es_superadmin', true)->where('activo', true)->whereKeyNot($actor->id)->pluck('id');
        $this->campana->avisarPlataforma($ids, self::TIPO_AVISO, [
            'titulo' => $titulo, 'texto' => $texto.' Si no lo esperabas, revisa la Bitácora de auditoría.', 'url' => route('configuracion.index').'#cfg-superadmins',
        ]);
    }
}
