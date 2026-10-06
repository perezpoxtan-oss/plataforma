<?php

namespace App\Services\Autenticacion;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * Valida usuario (o correo) y contrasena con bloqueo temporal por fuerza
 * bruta. Lo usan la pantalla web y, despues, la API de las apps moviles.
 *
 * Reglas (iguales a SEGCAT):
 *  - Solo cuentas activas de empresas activas (o el Super Administrador).
 *  - N intentos fallidos seguidos bloquean la cuenta M minutos.
 *  - Un acceso correcto reinicia el contador.
 */
class Autenticador
{
    public function intentar(string $identificador, string $contrasena): ResultadoAcceso
    {
        $identificador = trim($identificador);

        if ($identificador === '' || $contrasena === '') {
            return ResultadoAcceso::credenciales();
        }

        $usuario = User::query()
            ->with('empresa')
            ->where(fn ($q) => $q->where('email', $identificador)->orWhere('username', $identificador))
            ->where('activo', true)
            ->first();

        if ($usuario === null) {
            return $this->falloSinCuenta($identificador, $contrasena);
        }

        if ($usuario->estaBloqueado()) {
            return ResultadoAcceso::bloqueado((int) ceil(now()->diffInSeconds($usuario->bloqueado_hasta) / 60));
        }

        if (! Hash::check($contrasena, $usuario->password)) {
            return $this->registrarFallo($usuario);
        }

        if (! $usuario->es_superadmin && ($usuario->empresa_id === null || ! $usuario->empresa?->activo)) {
            return ResultadoAcceso::credenciales();
        }

        // Si cambió el costo del cifrado, la contraseña se vuelve a cifrar con el actual
        if (Hash::needsRehash($usuario->password)) {
            $usuario->password = $contrasena;
        }

        $usuario->forceFill([
            'intentos_fallidos' => 0,
            'bloqueado_hasta' => null,
            'ultimo_acceso_en' => now(),
        ])->save();

        return ResultadoAcceso::correcto($usuario);
    }

    /**
     * Usuario o correo que no existe (o cuenta desactivada): responde igual que
     * una cuenta real, incluido el bloqueo tras los mismos intentos, para que
     * el mensaje "cuenta bloqueada" no revele qué cuentas existen.
     */
    private function falloSinCuenta(string $identificador, string $contrasena): ResultadoAcceso
    {
        $llave = 'acceso-cuenta:'.hash('sha256', mb_strtolower($identificador));
        $maximo = (int) config('plataforma.sesion.max_intentos');
        $minutos = (int) config('plataforma.sesion.minutos_bloqueo');

        $hasta = (int) Cache::get($llave.':hasta', 0);
        if ($hasta > now()->getTimestamp()) {
            return ResultadoAcceso::bloqueado((int) ceil(($hasta - now()->getTimestamp()) / 60));
        }

        // Hash falso con el mismo costo que los reales: el tiempo de respuesta no
        // revela si la cuenta existe
        $coste = max(4, min(31, (int) config('hashing.bcrypt.rounds', 12)));
        Hash::check($contrasena, sprintf('$2y$%02d$', $coste).str_repeat('a', 53));

        // Igual que en una cuenta real: el contador no se reinicia solo con el tiempo
        // (aquí se conserva un día) y al bloquear vuelve a cero
        Cache::add($llave, 0, now()->addDay());
        $intentos = (int) Cache::increment($llave);

        if ($intentos >= $maximo) {
            Cache::forget($llave);
            Cache::put($llave.':hasta', now()->addMinutes($minutos)->getTimestamp(), now()->addMinutes($minutos));

            return ResultadoAcceso::bloqueado($minutos);
        }

        return ResultadoAcceso::credenciales();
    }

    private function registrarFallo(User $usuario): ResultadoAcceso
    {
        $maximo = (int) config('plataforma.sesion.max_intentos');
        $minutos = (int) config('plataforma.sesion.minutos_bloqueo');
        $intentos = (int) $usuario->intentos_fallidos + 1;

        if ($intentos >= $maximo) {
            $usuario->forceFill(['intentos_fallidos' => 0, 'bloqueado_hasta' => now()->addMinutes($minutos)])->save();

            return ResultadoAcceso::bloqueado($minutos);
        }

        $usuario->forceFill(['intentos_fallidos' => $intentos])->save();

        return ResultadoAcceso::credenciales();
    }
}
