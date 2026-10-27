<?php

namespace App\Services\Cuenta;

use App\Mail\AvisoGeneral;
use App\Models\Auditoria;
use App\Models\SolicitudCuenta;
use App\Models\User;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Services\Usuarios\AdministradorUsuarios;
use App\Support\CorreoPlataforma;
use App\Support\Tenancy\EmpresaScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Timebox;

use function Illuminate\Support\defer;

/**
 * «Pedir a mi administrador que restablezca mi contraseña o mis dos pasos»
 * (C-36, DD-21, X-07; docs/tecnico/usuarios.md):
 *
 *  - La recuperación no depende solo del correo de la plataforma: quien no
 *    tiene correo (cuentas de caseta) o no lo recibe pide a su administrador.
 *  - La solicitud cae en «Cuentas por revisar» (Mis pendientes y la lista de
 *    Usuarios) y en la campana de quien puede atenderla: `usuarios.editar`
 *    (contraseña) o `usuarios.desbloquear` (dos pasos), con alcance a la sede
 *    de la cuenta y de mayor jerarquía. Las del Super Administrador, a los
 *    otros Super Administradores.
 *  - El administrador confirma EN PERSONA que fue la persona y escribe una
 *    contraseña temporal que la persona cambia al entrar (la cuenta sigue
 *    siendo de su dueño), o le quita los dos pasos para que los active de nuevo.
 *    Al hacerlo, la solicitud se marca atendida sola.
 *  - La pantalla responde siempre igual (no revela qué cuentas existen).
 *    Una solicitud abierta por cuenta y tipo; 3 por hora por cuenta.
 */
class SolicitudesCuenta
{
    public const REFERENCIA = 'solicitud_cuenta';

    public const TIPO_AVISO = 'cuenta_solicitud';

    public const POR_CUENTA_HORA = 3;

    /** Tiempo mínimo de la respuesta de «Pedir a mi administrador» (exista o no la cuenta). */
    public const MICROSEGUNDOS_MINIMOS = 400_000;

    /** Permiso con el que se atiende cada tipo (el mismo de la acción en Usuarios). */
    public const PERMISOS = [SolicitudCuenta::CONTRASENA => 'usuarios.editar', SolicitudCuenta::DOS_PASOS => 'usuarios.desbloquear'];

    public function __construct(
        private readonly CentroNotificaciones $campana,
        private readonly AvisosCuenta $avisos,
        private readonly CorreoPlataforma $correo,
    ) {}

    /**
     * Desde «Olvidé mi contraseña» (sin sesión): usuario o correo. No dice
     * nada a la pantalla (respuesta uniforme) y tarda lo mismo exista o no
     * la cuenta (A-04): la búsqueda es una sola consulta igual en los dos
     * casos, el trabajo de crear la petición y avisar se hace después de
     * responder (defer) y todo queda dentro de un tiempo mínimo fijo
     * (Timebox, como el inicio de sesión de Laravel).
     *
     * Límite (A-09): POR_CUENTA_HORA por hora y por CUENTA, la escriban por
     * su usuario o por su correo; además, por texto escrito (cuenta que no
     * existe) y throttle:5,1 por equipo en la ruta.
     */
    public function pedirPorIdentificador(string $identificador, string $tipo, ?string $ip): void
    {
        app(Timebox::class)->call(function () use ($identificador, $tipo, $ip) {
            $identificador = trim($identificador);
            if ($identificador === '' || ! isset(SolicitudCuenta::TIPOS[$tipo])) {
                return;
            }
            $porTexto = 'solicitud-cuenta:'.hash('sha256', mb_strtolower($identificador));
            if (RateLimiter::tooManyAttempts($porTexto, self::POR_CUENTA_HORA)) {
                return;
            }
            RateLimiter::hit($porTexto, 3600);

            // Una sola consulta, igual exista o no (sin cargar relaciones aparte)
            $usuario = User::query()->leftJoin('empresas', 'empresas.id', '=', 'users.empresa_id')
                ->where(fn ($q) => $q->where('users.email', $identificador)->orWhere('users.username', $identificador))
                ->where('users.activo', true)->select('users.*', 'empresas.activo as empresa_activa')->first();
            if ($usuario === null || (! $usuario->es_superadmin && ($usuario->empresa_id === null || ! $usuario->empresa_activa))) {
                return;
            }
            $porCuenta = self::llaveDeCuenta($usuario->id);
            if (RateLimiter::tooManyAttempts($porCuenta, self::POR_CUENTA_HORA)) {
                return;
            }
            RateLimiter::hit($porCuenta, 3600);
            $id = $usuario->id;
            defer(fn () => $this->pedir(User::findOrFail($id), $tipo, $ip));
        }, self::MICROSEGUNDOS_MINIMOS);
    }

    /** Llave del límite por cuenta (la misma para el usuario y para el correo). */
    public static function llaveDeCuenta(int $userId): string
    {
        return 'solicitud-cuenta-usuario:'.$userId;
    }

    /** La persona ya escribió su contraseña (pantalla del código) o está identificada: crea o reutiliza la abierta. */
    public function pedir(User $usuario, string $tipo, ?string $ip): SolicitudCuenta
    {
        $abierta = $this->consulta()->where('user_id', $usuario->id)->where('tipo', $tipo)->where('estado', SolicitudCuenta::PENDIENTE)->first();
        if ($abierta !== null) {
            return $abierta;
        }
        $s = new SolicitudCuenta(['user_id' => $usuario->id, 'tipo' => $tipo, 'origen' => AvisosCuenta::ipRecortada($ip)]);
        $s->forceFill(['empresa_id' => $usuario->empresa_id, 'creado_por' => $usuario->id, 'actualizado_por' => $usuario->id]);
        SolicitudCuenta::withoutEvents(fn () => $s->save()); // sin tenant: la empresa ya viene de la cuenta
        $this->auditar($s, $usuario, 'usuarios.solicitud_cuenta', ['tipo' => $tipo, 'origen' => $s->origen]);
        $this->avisar($s, $usuario);

        // A la propia persona, si tiene correo: así se entera si no fue ella
        if ($this->correo->configurado() && filter_var((string) $usuario->email, FILTER_VALIDATE_EMAIL) !== false) {
            $mensaje = new AvisoGeneral('Pediste ayuda con tu cuenta', [
                'Recibimos tu petición para '.$s->etiquetaTipo().'. Tu administrador la atenderá en persona: lleva una identificación.',
                'Si no fuiste tú, avisa a tu administrador.',
            ]);
            $para = $usuario->email;
            defer(fn () => $this->correo->enviar([$para], $mensaje));
        }

        return $s;
    }

    /**
     * El administrador ya restableció la contraseña o los dos pasos de la
     * cuenta: las solicitudes abiertas de ese tipo quedan atendidas.
     */
    public function atendidas(User $usuario, string $tipo, User $actor): int
    {
        $abiertas = $this->consulta()->where('user_id', $usuario->id)->where('tipo', $tipo)->where('estado', SolicitudCuenta::PENDIENTE)->get();
        foreach ($abiertas as $s) {
            $this->cerrar($s, $actor, SolicitudCuenta::ATENDIDA, null);
        }

        return $abiertas->count();
    }

    /** «Rechazar»: no fue la persona o no procede. Se le avisa (campana o correo). */
    public function rechazar(User $actor, SolicitudCuenta $s, ?string $motivo): void
    {
        if ($s->estado !== SolicitudCuenta::PENDIENTE) {
            throw new AuthorizationException('Esa solicitud ya se atendió.');
        }
        $usuario = User::findOrFail($s->user_id);
        $this->exigirPuedeAtender($actor, $usuario, $s->tipo);
        $motivo = trim((string) $motivo) === '' ? null : mb_substr(trim((string) $motivo), 0, 255);
        $this->cerrar($s, $actor, SolicitudCuenta::RECHAZADA, $motivo);
        $texto = 'Tu administrador no atendió tu petición para '.$s->etiquetaTipo().($motivo ? ': '.$motivo : '.').' Si la necesitas, búscalo en persona.';
        $aviso = ['titulo' => 'Tu petición sobre tu cuenta no se atendió', 'texto' => $texto, 'url' => route('mi-cuenta.index')];
        if ($usuario->empresa_id !== null) {
            $this->campana->avisar((int) $usuario->empresa_id, [$usuario->id], 'cuenta_dos_pasos', $aviso);
        } else {
            // A-08: la de un Super Administrador la rechaza otro Super Administrador
            $aviso['texto'] = str_replace('Tu administrador', 'Otro Super Administrador', $texto);
            $this->campana->avisarPlataforma([$usuario->id], 'plataforma_superadmin', $aviso);
        }
    }

    /**
     * Solicitudes abiertas que este actor puede atender (por tipo: su permiso,
     * su alcance y su jerarquía). Super Administrador sin empresa: las de los
     * otros Super Administradores.
     *
     * @return Collection<int, SolicitudCuenta>
     */
    public function porAtender(User $actor, ?int $empresaId): Collection
    {
        if ($empresaId === null) {
            if (! $actor->es_superadmin) {
                return collect();
            }

            return $this->consulta()->whereNull('empresa_id')->where('estado', SolicitudCuenta::PENDIENTE)->where('user_id', '!=', $actor->id)
                ->with('usuario')->orderBy('created_at')->get();
        }
        $administrador = app(AdministradorUsuarios::class);
        $resultado = collect();
        foreach (self::PERMISOS as $tipo => $permiso) {
            if (! $actor->es_superadmin && ! $actor->can($permiso)) {
                continue;
            }
            $visibles = $administrador->limitarAlcance(User::query()->where('users.empresa_id', $empresaId)->where('users.es_superadmin', false), $actor, $permiso)
                ->pluck('users.id');
            $resultado = $resultado->merge($this->consulta()->where('empresa_id', $empresaId)->where('tipo', $tipo)->where('estado', SolicitudCuenta::PENDIENTE)
                ->whereIn('user_id', $visibles)->where('user_id', '!=', $actor->id)->with('usuario')->orderBy('created_at')->get()
                ->filter(fn (SolicitudCuenta $s) => $this->puedeAtender($actor, $s->usuario, $tipo)));
        }

        return $resultado->sortBy('created_at')->values();
    }

    public function puedeAtender(User $actor, ?User $usuario, string $tipo): bool
    {
        try {
            $usuario !== null && $this->exigirPuedeAtender($actor, $usuario, $tipo);

            return $usuario !== null;
        } catch (AuthorizationException) {
            return false;
        }
    }

    private function exigirPuedeAtender(User $actor, User $usuario, string $tipo): void
    {
        if ($usuario->es_superadmin) {
            if (! $actor->es_superadmin || $actor->id === $usuario->id) {
                throw new AuthorizationException('La cuenta de un Super Administrador la atiende otro Super Administrador.');
            }

            return;
        }
        app(AdministradorUsuarios::class)->exigirPuedeAdministrar($actor, $usuario, self::PERMISOS[$tipo] ?? 'usuarios.editar');
    }

    /** @return Builder<SolicitudCuenta> */
    private function consulta(): Builder
    {
        return SolicitudCuenta::query()->withoutGlobalScope(EmpresaScope::class);
    }

    private function cerrar(SolicitudCuenta $s, User $actor, string $estado, ?string $motivo): void
    {
        $this->consulta()->whereKey($s->id)->update(['estado' => $estado, 'atendida_en' => now(), 'atendida_por' => $actor->id,
            'motivo_rechazo' => $motivo, 'actualizado_por' => $actor->id, 'updated_at' => now()]);
        $s->forceFill(['estado' => $estado, 'atendida_en' => now(), 'atendida_por' => $actor->id, 'motivo_rechazo' => $motivo]);
        $this->campana->resolver(self::REFERENCIA, $s->id);
        $this->auditar($s, $actor, $estado === SolicitudCuenta::ATENDIDA ? 'usuarios.solicitud_cuenta_atendida' : 'usuarios.solicitud_cuenta_rechazada',
            ['tipo' => $s->tipo] + ($motivo ? ['motivo' => $motivo] : []));
    }

    /** Campana de quien puede atenderla (con alcance a la sede de la cuenta y mayor jerarquía), o de los otros Super Administradores. */
    private function avisar(SolicitudCuenta $s, User $usuario): void
    {
        $datos = [
            'titulo' => 'Pide ayuda con su cuenta: '.$usuario->name,
            'texto' => $usuario->name.' (@'.$usuario->username.') pide '.$s->etiquetaTipo().'. Confirma en persona que fue quien lo pidió antes de hacerlo.',
            'referencia_tipo' => self::REFERENCIA, 'referencia_id' => $s->id,
        ];
        if ($usuario->es_superadmin) {
            $otros = User::where('es_superadmin', true)->where('activo', true)->whereKeyNot($usuario->id)->pluck('id');
            $this->campana->avisarPlataforma($otros, self::TIPO_AVISO, $datos + ['url' => route('configuracion.index').'#cfg-superadmins']);

            return;
        }
        $permiso = self::PERMISOS[$s->tipo] ?? 'usuarios.editar';
        $destinatarios = $this->avisos->usuariosConPermiso((int) $usuario->empresa_id, $permiso, $usuario->sedeAsignadaId())
            ->filter(fn (User $u) => $this->puedeAtender($u, $usuario, $s->tipo))->pluck('id');
        $this->campana->avisar((int) $usuario->empresa_id, $destinatarios, self::TIPO_AVISO, $datos + ['url' => route('usuarios.index', ['revisar' => 1])]);
    }

    private function auditar(SolicitudCuenta $s, User $actor, string $evento, array $despues): void
    {
        Auditoria::create([
            'empresa_id' => $s->empresa_id, 'user_id' => $actor->id, 'evento' => $evento,
            'auditable_type' => User::class, 'auditable_id' => $s->user_id, 'antes' => null, 'despues' => $despues, 'ip' => request()?->ip(),
        ]);
    }
}
