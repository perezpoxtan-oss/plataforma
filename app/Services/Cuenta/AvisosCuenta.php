<?php

namespace App\Services\Cuenta;

use App\Mail\CuentaBloqueada;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\User;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use App\Support\CorreoPlataforma;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

use function Illuminate\Support\defer;

/**
 * Avisos de seguridad sobre las cuentas (criterio 1 de docs/criterios-ux.md:
 * todo lo que espera a alguien llega a la campana; criterio 6: la cuenta es
 * de su dueño). Van a quien administra usuarios (`usuarios.editar`) de la
 * empresa con alcance a la sede de la cuenta afectada, y a la propia persona
 * por correo cuando tiene. Nunca llevan datos sensibles.
 */
class AvisosCuenta
{
    public function __construct(
        private readonly CentroNotificaciones $campana,
        private readonly CorreoPlataforma $correo,
        private readonly Autorizador $autorizador,
    ) {}

    /**
     * Bloqueo por intentos fallidos: un aviso por bloqueo (no por intento).
     * Campana a quien edita usuarios en esa sede; correo a ellos y al
     * afectado si la empresa tiene encendido el aviso «cuenta_bloqueada».
     */
    public function bloqueo(User $usuario, ?string $ip, int $minutos): void
    {
        if ($usuario->empresa_id === null) {
            return; // Super Administrador: no hay empresa a quien avisar
        }
        $empresaId = (int) $usuario->empresa_id;
        $origen = self::ipRecortada($ip);
        $texto = sprintf('La cuenta de %s (@%s) se bloqueó %d minutos por intentos fallidos desde %s.',
            $usuario->name, $usuario->username, $minutos, $origen);

        $administradores = $this->usuariosConPermiso($empresaId, 'usuarios.editar', $usuario->sedeAsignadaId())
            ->reject(fn (User $u) => $u->id === $usuario->id);

        $this->campana->avisar($empresaId, $administradores->pluck('id'), 'cuenta_bloqueada', [
            'titulo' => 'Cuenta bloqueada: '.$usuario->name,
            'texto' => $texto.' Si la persona necesita entrar antes, desbloquéala en Usuarios.',
            'url' => route('usuarios.index'),
            'referencia_tipo' => 'usuario_bloqueo',
            'referencia_id' => $usuario->id,
        ]);

        $empresa = Empresa::find($empresaId);
        if ($empresa === null || ! $empresa->aviso('cuenta_bloqueada') || ! $this->correo->configurado()) {
            return;
        }
        $enlace = route('usuarios.index');
        $correos = $administradores->pluck('email')->filter()->unique()->values()->all();
        if ($correos !== []) {
            defer(fn () => $this->correo->enviar($correos, new CuentaBloqueada($texto, false, $enlace)));
        }
        if (filter_var($usuario->email, FILTER_VALIDATE_EMAIL) !== false) {
            $propio = sprintf('Tu cuenta (@%s) se bloqueó %d minutos por intentos fallidos desde %s.', $usuario->username, $minutos, $origen);
            defer(fn () => $this->correo->enviar([$usuario->email], new CuentaBloqueada($propio, true, null)));
        }
    }

    /**
     * La cuenta se desactivó sola porque su colaborador se dio de baja: aviso
     * por campana a quien edita usuarios en la sede de la cuenta.
     */
    public function desactivadaPorBaja(User $usuario, Colaborador $colaborador, User $actor): void
    {
        if ($usuario->empresa_id === null) {
            return;
        }
        $empresaId = (int) $usuario->empresa_id;
        $destinatarios = $this->usuariosConPermiso($empresaId, 'usuarios.editar', $usuario->sedeAsignadaId())->pluck('id');
        if (! $destinatarios->contains($actor->id) && ($actor->es_superadmin || $actor->can('usuarios.editar'))) {
            $destinatarios->push($actor->id);
        }

        $this->campana->avisar($empresaId, $destinatarios, 'cuenta_desactivada', [
            'titulo' => 'Cuenta dada de baja junto con su colaborador: '.$usuario->name,
            'texto' => sprintf('Se dio de baja la cuenta de %s (@%s) junto con el colaborador %s; reactívala en Usuarios si es necesario.',
                $usuario->name, $usuario->username, $colaborador->nombreCompleto()),
            'url' => route('usuarios.index', ['revisar' => 1]),
            'referencia_tipo' => 'usuario_baja',
            'referencia_id' => $usuario->id,
        ]);
    }

    /**
     * «Cambiaste tu contraseña»: a la propia persona, por campana.
     */
    public function contrasenaCambiada(User $usuario, string $como): void
    {
        if ($usuario->empresa_id === null) {
            return; // la campana es por empresa; el Super Administrador no la tiene
        }
        $this->campana->avisar((int) $usuario->empresa_id, [$usuario->id], 'cuenta_contrasena', [
            'titulo' => 'Cambiaste tu contraseña',
            'texto' => $como.' Si no fuiste tú, cámbiala de nuevo en Mi cuenta y avisa a tu administrador.',
            'url' => route('mi-cuenta.index'),
        ]);
    }

    /**
     * Usuarios activos de la empresa con un permiso cuyo alcance cubre esa
     * sede (null = la cuenta ve todas las sedes: solo quien tiene alcance de
     * toda la empresa).
     *
     * @return Collection<int, User>
     */
    public function usuariosConPermiso(int $empresaId, string $permiso, ?int $sedeId): Collection
    {
        return User::where('empresa_id', $empresaId)->where('activo', true)->get()
            ->filter(function (User $u) use ($permiso, $sedeId) {
                $efectivo = $this->autorizador->permisosEfectivos($u)[$permiso] ?? null;

                return $efectivo !== null && ($efectivo->alcance === Alcance::Empresa || $efectivo->sedes === null
                    || ($sedeId !== null && in_array($sedeId, $efectivo->sedes, true)));
            })->values();
    }

    /**
     * Cierra las sesiones guardadas de la cuenta (todas, o todas menos la actual).
     */
    public static function cerrarSesiones(User $usuario, ?string $exceptoSesion = null): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }
        DB::table(config('session.table', 'sessions'))->where('user_id', $usuario->id)
            ->when($exceptoSesion !== null, fn ($q) => $q->where('id', '!=', $exceptoSesion))
            ->delete();
    }

    /**
     * Origen del intento sin identificar al equipo: «187.190.x.x» o «2806:10a6::…».
     */
    public static function ipRecortada(?string $ip): string
    {
        $ip = trim((string) $ip);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $p = explode('.', $ip);

            return "{$p[0]}.{$p[1]}.x.x";
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $p = explode(':', $ip);

            return "{$p[0]}:".($p[1] ?? '').'::…';
        }

        return 'un equipo desconocido';
    }
}
