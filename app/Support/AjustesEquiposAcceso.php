<?php

namespace App\Support;

use App\Models\Empresa;

/**
 * Ajustes de Configuración → Equipos de acceso, por empresa
 * (empresas.preferencias['equipos_acceso']).
 *
 *  - sin_conexion_horas: después de cuántas horas sin que un equipo llame se
 *    avisa al jefe de seguridad (y al doble, a su suplente). De fábrica 2.
 */
final class AjustesEquiposAcceso
{
    public const CLAVE = 'equipos_acceso';

    public const OMISION = ['sin_conexion_horas' => 2];

    public const MINIMO = 1;

    public const MAXIMO = 72;

    /** @return array{sin_conexion_horas: int} */
    public static function de(?Empresa $empresa): array
    {
        $guardado = (array) (($empresa?->preferencias ?? [])[self::CLAVE] ?? []);
        $horas = (int) ($guardado['sin_conexion_horas'] ?? self::OMISION['sin_conexion_horas']);

        return ['sin_conexion_horas' => max(self::MINIMO, min(self::MAXIMO, $horas))];
    }
}
