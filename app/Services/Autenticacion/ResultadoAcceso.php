<?php

namespace App\Services\Autenticacion;

use App\Models\User;

/**
 * Resultado de un intento de inicio de sesion.
 */
final class ResultadoAcceso
{
    public const CORRECTO = 'correcto';

    public const CREDENCIALES = 'credenciales';

    public const BLOQUEADO = 'bloqueado';

    private function __construct(
        public readonly string $estado,
        public readonly ?User $usuario = null,
        public readonly int $minutos = 0,
    ) {}

    public static function correcto(User $usuario): self
    {
        return new self(self::CORRECTO, $usuario);
    }

    public static function credenciales(): self
    {
        return new self(self::CREDENCIALES);
    }

    public static function bloqueado(int $minutos): self
    {
        return new self(self::BLOQUEADO, minutos: max(1, $minutos));
    }

    public function exitoso(): bool
    {
        return $this->estado === self::CORRECTO;
    }
}
