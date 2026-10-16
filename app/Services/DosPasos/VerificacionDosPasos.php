<?php

namespace App\Services\DosPasos;

use App\Mail\AvisoGeneral;
use App\Models\Auditoria;
use App\Models\Empresa;
use App\Models\User;
use App\Services\Cuenta\AvisosCuenta;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Autorizador;
use App\Services\Usuarios\AdministradorUsuarios;
use App\Support\CorreoPlataforma;
use App\Support\Identidad;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Cookie as CookieHttp;

use function Illuminate\Support\defer;

/**
 * Verificación en dos pasos de una cuenta (docs/tecnico/dos-pasos.md):
 *
 *  - Activar desde Mi cuenta: la clave nueva vive en la sesión hasta que la
 *    persona escribe un código correcto; entonces se guarda CIFRADA y se
 *    generan 10 códigos de respaldo de un solo uso (se muestran una vez; se
 *    guardan solo sus huellas).
 *  - Al entrar: después de la contraseña se pide el código (o uno de
 *    respaldo). Un código no sirve dos veces (último paso aceptado).
 *  - «Recordar este dispositivo 30 días»: cookie firmada con la llave de la
 *    aplicación; deja de valer al desactivar, restablecer o volver a activar
 *    la verificación y al cambiar la contraseña.
 *  - Política por empresa (Configuración → Cuentas y seguridad): exigirla a
 *    nadie (original), a los administradores o a todos. El Super
 *    Administrador SIEMPRE la necesita.
 *  - Restablecer (quien desbloquea usuarios, con las mismas reglas de
 *    alcance y jerarquía): para quien perdió su teléfono; se audita y se
 *    avisa a la persona.
 *
 * Todo cambio queda en la Bitácora de auditoría sin la clave ni los códigos.
 */
class VerificacionDosPasos
{
    /** Valores de la política por empresa (preferencias.dos_pasos). */
    public const POLITICAS = [
        'nadie' => 'Nadie (cada persona decide)',
        'administradores' => 'Administradores',
        'todos' => 'Todos',
    ];

    public const POLITICA_ORIGINAL = 'nadie';

    /** Quien tenga alguno de estos permisos cuenta como «administrador» para la política. */
    public const PERMISOS_ADMINISTRADOR = ['usuarios.crear', 'usuarios.editar', 'roles.editar', 'permisos.editar', 'configuracion.editar'];

    public const CODIGOS_RESPALDO = 10;

    public const DIAS_RECORDAR = 30;

    /** Intentos de código por inicio de sesión antes de volver a pedir la contraseña. */
    public const INTENTOS = 5;

    /** Minutos que espera la pantalla del código después de la contraseña. */
    public const MINUTOS_PENDIENTE = 10;

    private const PREFIJO_COOKIE = 'recordar_dos_pasos_';

    public function __construct(
        private readonly AdministradorRoles $auditoria,
        private readonly Autorizador $autorizador,
        private readonly CentroNotificaciones $campana,
        private readonly CorreoPlataforma $correo,
    ) {}

    // ------------------------------------------------------------- Política

    public function politica(?Empresa $empresa): string
    {
        $valor = $empresa?->preferencias['dos_pasos'] ?? null;

        return is_string($valor) && isset(self::POLITICAS[$valor]) ? $valor : self::POLITICA_ORIGINAL;
    }

    public function esAdministrador(User $usuario): bool
    {
        $permisos = $this->autorizador->permisosEfectivos($usuario);
        foreach (self::PERMISOS_ADMINISTRADOR as $permiso) {
            if (isset($permisos[$permiso])) {
                return true;
            }
        }

        return false;
    }

    /** ¿Esta cuenta debe tener la verificación activa para usar la plataforma? */
    public function exigida(User $usuario): bool
    {
        if ($usuario->es_superadmin) {
            return true;
        }

        return match ($this->politica($usuario->empresa)) {
            'todos' => true,
            'administradores' => $this->esAdministrador($usuario),
            default => false,
        };
    }

    public function guardarPolitica(User $actor, Empresa $empresa, string $valor): void
    {
        if (! isset(self::POLITICAS[$valor])) {
            throw ValidationException::withMessages(['dos_pasos' => 'Elige una de las opciones.']);
        }
        $antes = $this->politica($empresa);
        $preferencias = $empresa->preferencias ?? [];
        $preferencias['dos_pasos'] = $valor;
        $empresa->forceFill(['preferencias' => $preferencias])->save();
        $this->auditoria->auditar($actor, 'configuracion.dos_pasos', $empresa, ['dos_pasos' => $antes], ['dos_pasos' => $valor]);
    }

    /** Cuántas cuentas activas de la empresa ya la tienen activa (para la tarjeta de Configuración). */
    public function conteo(Empresa $empresa): array
    {
        $base = User::where('empresa_id', $empresa->id)->where('activo', true);

        return ['activas' => (clone $base)->whereNotNull('two_factor_confirmado_en')->count(), 'total' => $base->count()];
    }

    // ------------------------------------------------------------ Activar

    /**
     * Activa la verificación con la clave que se mostró en la pantalla y el
     * primer código. Devuelve los códigos de respaldo en claro (una sola vez).
     *
     * @return list<string>
     */
    public function activar(User $usuario, string $secreto, string $codigo): array
    {
        $paso = Totp::verificar($secreto, $codigo);
        if ($paso === null) {
            throw ValidationException::withMessages(['codigo' => 'El código no coincide. Revisa que la hora de tu teléfono sea automática y escribe el código que se ve ahora.']);
        }
        $codigos = $this->nuevosCodigos();
        $usuario->forceFill([
            'two_factor_secret' => $secreto,
            'two_factor_recovery_codes' => array_map([$this, 'huella'], $codigos),
            'two_factor_confirmado_en' => now(),
            'two_factor_ultimo_paso' => $paso,
        ])->save();
        $this->auditoria->auditar($usuario, 'cuenta.dos_pasos_activada', $usuario, null, ['dos_pasos' => 'activada', 'codigos_respaldo' => self::CODIGOS_RESPALDO]);
        $this->avisarPropio($usuario, 'Activaste la verificación en dos pasos', 'Desde ahora, al entrar te pediremos el código de tu app de autenticación. Guarda tus códigos de respaldo en un lugar seguro.');

        return $codigos;
    }

    /** @return list<string> */
    public function regenerarCodigos(User $usuario, string $contrasena): array
    {
        $this->exigirContrasena($usuario, $contrasena);
        $codigos = $this->nuevosCodigos();
        $usuario->forceFill(['two_factor_recovery_codes' => array_map([$this, 'huella'], $codigos)])->save();
        $this->auditoria->auditar($usuario, 'cuenta.dos_pasos_codigos', $usuario, null, ['codigos_respaldo' => 'restablecidos']);

        return $codigos;
    }

    public function desactivar(User $usuario, string $contrasena, string $codigo): void
    {
        $this->exigirContrasena($usuario, $contrasena);
        if ($this->exigida($usuario)) {
            throw ValidationException::withMessages(['codigo' => $usuario->es_superadmin
                ? 'El Super Administrador siempre necesita la verificación en dos pasos: no se puede quitar.'
                : 'Tu empresa exige la verificación en dos pasos para tu cuenta: no se puede quitar.']);
        }
        if ($this->verificarCodigo($usuario, $codigo) === null) {
            $this->auditarFallo($usuario, 'desactivar');
            throw ValidationException::withMessages(['codigo' => 'El código no es correcto.']);
        }
        $this->limpiar($usuario);
        $this->auditoria->auditar($usuario, 'cuenta.dos_pasos_desactivada', $usuario, ['dos_pasos' => 'activa'], ['dos_pasos' => 'desactivada']);
        $this->avisarPropio($usuario, 'Quitaste la verificación en dos pasos', 'Ya solo se pide tu contraseña para entrar. Puedes volver a activarla en Mi cuenta cuando quieras.');
    }

    /**
     * Quien desbloquea usuarios (con su alcance y jerarquía) la quita para
     * que la persona la active de nuevo con su teléfono nuevo.
     */
    public function restablecer(User $actor, User $usuario): void
    {
        $administrador = app(AdministradorUsuarios::class);
        $administrador->exigirPuedeAdministrar($actor, $usuario, 'usuarios.desbloquear');
        if (! $administrador->limitarAlcance(User::query()->whereKey($usuario->id), $actor, 'usuarios.desbloquear')->exists()) {
            throw new AuthorizationException('Ese usuario está fuera de tu alcance.');
        }
        if (! $usuario->tieneDosPasos()) {
            throw new AuthorizationException("«{$usuario->name}» no tiene activa la verificación en dos pasos.");
        }
        $this->limpiar($usuario, $actor);
        AvisosCuenta::cerrarSesiones($usuario);
        $this->auditoria->auditar($actor, 'usuarios.dos_pasos_restablecida', $usuario, ['dos_pasos' => 'activa'], ['dos_pasos' => 'restablecida', 'avisado' => true]);
        $this->avisarPropio($usuario, 'Restablecieron tu verificación en dos pasos',
            $actor->name.' quitó la verificación de tu cuenta (por ejemplo, porque cambiaste de teléfono). Al entrar, actívala de nuevo en Mi cuenta. Si no lo pediste, avisa a tu administrador.', true);
    }

    /** Uso de consola (plataforma:dos-pasos): quita la verificación de cualquier cuenta, también del Super Administrador. */
    public function restablecerDesdeConsola(User $usuario): void
    {
        $this->limpiar($usuario);
        AvisosCuenta::cerrarSesiones($usuario);
        Auditoria::create(['empresa_id' => $usuario->empresa_id, 'user_id' => null, 'evento' => 'usuarios.dos_pasos_restablecida',
            'auditable_type' => User::class, 'auditable_id' => $usuario->id, 'antes' => ['dos_pasos' => 'activa'], 'despues' => ['dos_pasos' => 'restablecida', 'origen' => 'consola'], 'ip' => null]);
    }

    private function limpiar(User $usuario, ?User $actor = null): void
    {
        $usuario->forceFill([
            'two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmado_en' => null, 'two_factor_ultimo_paso' => null,
        ] + ($actor !== null ? ['actualizado_por' => $actor->id] : []))->save();
    }

    // ------------------------------------------------------------ Al entrar

    /**
     * Revisa un código de la app (6 dígitos) o uno de respaldo (XXXX-XXXX).
     * Devuelve 'app' o 'respaldo', o null si no sirve. El código de la app
     * no se acepta dos veces; el de respaldo se consume.
     */
    public function verificarCodigo(User $usuario, string $codigo): ?string
    {
        if (! $usuario->tieneDosPasos()) {
            return null;
        }
        $limpio = strtoupper(preg_replace('/[\s-]/', '', $codigo) ?? '');
        if (preg_match('/^\d{6}$/', $limpio)) {
            $paso = Totp::verificar((string) $usuario->two_factor_secret, $limpio, $usuario->two_factor_ultimo_paso);
            if ($paso === null) {
                return null;
            }
            // Solo si nadie más lo usó en el mismo instante (dos pestañas con el mismo código)
            $actualizados = User::whereKey($usuario->id)
                ->where(fn ($q) => $q->whereNull('two_factor_ultimo_paso')->orWhere('two_factor_ultimo_paso', '<', $paso))
                ->update(['two_factor_ultimo_paso' => $paso]);
            $usuario->two_factor_ultimo_paso = $paso;

            return $actualizados === 1 ? 'app' : null;
        }
        if (! preg_match('/^[A-Z0-9]{8}$/', $limpio)) {
            return null;
        }
        $huella = $this->huella($limpio);
        $guardados = array_values((array) ($usuario->two_factor_recovery_codes ?? []));
        $restantes = array_values(array_filter($guardados, fn ($h) => ! hash_equals((string) $h, $huella)));
        if (count($restantes) === count($guardados)) {
            return null;
        }
        $usuario->forceFill(['two_factor_recovery_codes' => $restantes])->save();
        $this->auditoria->auditar($usuario, 'cuenta.dos_pasos_respaldo_usado', $usuario, null, ['codigos_restantes' => count($restantes)]);

        return 'respaldo';
    }

    public function codigosRestantes(User $usuario): int
    {
        return count((array) ($usuario->two_factor_recovery_codes ?? []));
    }

    public function auditarFallo(User $usuario, string $donde, ?string $ip = null): void
    {
        Auditoria::create([
            'empresa_id' => $usuario->empresa_id, 'user_id' => $usuario->id, 'evento' => 'cuenta.dos_pasos_fallido',
            'auditable_type' => User::class, 'auditable_id' => $usuario->id, 'antes' => null,
            'despues' => ['donde' => $donde, 'origen' => AvisosCuenta::ipRecortada($ip ?? request()?->ip())], 'ip' => $ip ?? request()?->ip(),
        ]);
    }

    // --------------------------------------------- Recordar este dispositivo

    public function cookieRecordar(User $usuario): CookieHttp
    {
        $expira = now()->addDays(self::DIAS_RECORDAR)->getTimestamp();
        $valor = $usuario->id.'|'.$expira.'|'.$this->firma($usuario, $expira);

        return Cookie::make(self::PREFIJO_COOKIE.$usuario->id, $valor, self::DIAS_RECORDAR * 24 * 60, null, null, null, true, false, 'lax');
    }

    public function dispositivoRecordado(Request $request, User $usuario): bool
    {
        $valor = (string) $request->cookie(self::PREFIJO_COOKIE.$usuario->id, '');
        $partes = explode('|', $valor);
        if (count($partes) !== 3 || (int) $partes[0] !== (int) $usuario->id || (int) $partes[1] < now()->getTimestamp()) {
            return false;
        }

        return hash_equals($this->firma($usuario, (int) $partes[1]), $partes[2]);
    }

    public function olvidarDispositivo(User $usuario): CookieHttp
    {
        return Cookie::forget(self::PREFIJO_COOKIE.$usuario->id);
    }

    /** Firma ligada a la cuenta, a la activación vigente y a la contraseña actual. */
    private function firma(User $usuario, int $expira): string
    {
        $activada = $usuario->two_factor_confirmado_en?->getTimestamp() ?? 0;

        return hash_hmac('sha256', implode('|', [$usuario->id, $expira, $activada, (string) $usuario->getAttributes()['password']]), $this->llave());
    }

    // ------------------------------------------------------------- Ayudas

    /** @return list<string> */
    private function nuevosCodigos(): array
    {
        $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sin 0/O ni 1/I para que no se confundan
        $codigos = [];
        while (count($codigos) < self::CODIGOS_RESPALDO) {
            $c = '';
            for ($i = 0; $i < 8; $i++) {
                $c .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
            }
            $codigos[$c] = substr($c, 0, 4).'-'.substr($c, 4);
        }

        return array_values($codigos);
    }

    private function huella(string $codigo): string
    {
        return hash_hmac('sha256', strtoupper(str_replace('-', '', $codigo)), $this->llave());
    }

    private function llave(): string
    {
        $llave = (string) config('app.key');

        return str_starts_with($llave, 'base64:') ? (string) base64_decode(substr($llave, 7)) : $llave;
    }

    private function exigirContrasena(User $usuario, string $contrasena): void
    {
        if (! Hash::check($contrasena, (string) $usuario->password)) {
            throw ValidationException::withMessages(['contrasena_actual' => 'La contraseña no es correcta.']);
        }
    }

    /** Aviso a la propia persona: campana (si es de una empresa) y correo si se pide. */
    private function avisarPropio(User $usuario, string $titulo, string $texto, bool $correo = false): void
    {
        if ($usuario->empresa_id !== null) {
            $this->campana->avisar((int) $usuario->empresa_id, [$usuario->id], 'cuenta_dos_pasos', [
                'titulo' => $titulo, 'texto' => $texto.' Si no fuiste tú, cambia tu contraseña y avisa a tu administrador.', 'url' => route('mi-cuenta.index').'#dos-pasos',
            ]);
        }
        if ($correo && filter_var($usuario->email, FILTER_VALIDATE_EMAIL) !== false && $this->correo->configurado()) {
            $mensaje = new AvisoGeneral($titulo, [$texto], [['Abrir Mi cuenta', route('mi-cuenta.index')]]);
            defer(fn () => $this->correo->enviar([$usuario->email], $mensaje));
        }
    }

    /** Nombre que verá la persona en su app de autenticación. */
    public function emisor(): string
    {
        return Str::limit((string) (app(Identidad::class)->get('nombre_corto') ?: app(Identidad::class)->get('nombre')), 40, '');
    }
}
