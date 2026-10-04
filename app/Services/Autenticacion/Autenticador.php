<?php

namespace App\Services\Autenticacion;

use App\Models\User;
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
            // Se compara contra un hash falso para no revelar por el tiempo de respuesta si la cuenta existe
            Hash::check($contrasena, '$2y$12$'.str_repeat('a', 53));

            return ResultadoAcceso::credenciales();
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

        $usuario->forceFill([
            'intentos_fallidos' => 0,
            'bloqueado_hasta' => null,
            'ultimo_acceso_en' => now(),
        ])->save();

        return ResultadoAcceso::correcto($usuario);
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
