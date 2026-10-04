<?php

namespace App\Support;

use DateTimeZone;

/**
 * Zonas horarias para empresas y sedes: primero las de México (las más usadas)
 * y después el resto del mundo.
 */
class ZonasHorarias
{
    public const MEXICO = [
        'America/Cancun' => 'Quintana Roo (Cancún)',
        'America/Mexico_City' => 'Centro (Ciudad de México)',
        'America/Merida' => 'Yucatán y Campeche (Mérida)',
        'America/Monterrey' => 'Nuevo León (Monterrey)',
        'America/Matamoros' => 'Frontera noreste (Matamoros)',
        'America/Chihuahua' => 'Chihuahua',
        'America/Ciudad_Juarez' => 'Ciudad Juárez',
        'America/Mazatlan' => 'Pacífico (Mazatlán, La Paz)',
        'America/Hermosillo' => 'Sonora (Hermosillo)',
        'America/Tijuana' => 'Baja California (Tijuana)',
    ];

    /**
     * @return array<string, array<string, string>> grupo => [zona => etiqueta]
     */
    public static function opciones(): array
    {
        $resto = array_diff(DateTimeZone::listIdentifiers(), array_keys(self::MEXICO));

        return [
            'México' => self::MEXICO,
            'Otros países' => array_combine($resto, array_map(fn ($z) => str_replace('_', ' ', $z), $resto)),
        ];
    }

    public static function etiqueta(?string $zona): string
    {
        return $zona === null ? '' : (self::MEXICO[$zona] ?? str_replace('_', ' ', $zona));
    }
}
