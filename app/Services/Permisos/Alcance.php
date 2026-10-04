<?php

namespace App\Services\Permisos;

/**
 * Sobre que registros aplica un permiso.
 */
enum Alcance: string
{
    case Propios = 'propios';
    case Sede = 'sede';
    case Empresa = 'empresa';

    public function rango(): int
    {
        return match ($this) {
            self::Propios => 1,
            self::Sede => 2,
            self::Empresa => 3,
        };
    }

    public function cubre(self $otro): bool
    {
        return $this->rango() >= $otro->rango();
    }

    public static function mayor(self $a, self $b): self
    {
        return $a->rango() >= $b->rango() ? $a : $b;
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Propios => 'Solo los propios',
            self::Sede => 'Su sede',
            self::Empresa => 'Toda la empresa',
        };
    }
}
