<?php

namespace App\Services\EquiposAcceso;

use InvalidArgumentException;

/** Marcas de equipos de acceso que la plataforma entiende. */
final class Conectores
{
    /** clave => clase que implementa Conector */
    public const MARCAS = [
        'zkteco' => ConectorZkteco::class,
        'hikvision' => ConectorHikvision::class,
    ];

    public static function para(string $marca): Conector
    {
        $clase = self::MARCAS[$marca] ?? throw new InvalidArgumentException("Marca de equipo desconocida: {$marca}");

        return app($clase);
    }

    /** @return array<string, string> clave => nombre */
    public static function nombres(): array
    {
        return array_map(fn (string $clase) => app($clase)->nombre(), self::MARCAS);
    }

    /** @return list<string> marcas que reciben altas y bajas desde la plataforma */
    public static function queSincronizan(): array
    {
        return array_keys(array_filter(self::MARCAS, fn (string $clase) => app($clase)->sincronizaUsuarios()));
    }
}
