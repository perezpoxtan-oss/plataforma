<?php

namespace App\Support\Espacios;

use App\Models\Empresa;
use App\Models\Espacio;

/**
 * Cómo se llama cada nivel del árbol en pantalla. Se conservan los nombres de
 * SEGCAT y el nivel "área específica" toma el término del rubro
 * (Habitación en hoteles, Oficina en corporativos, Departamento en condominios...).
 */
class Etiquetas
{
    /**
     * @return array<string, array{singular: string, plural: string}>
     */
    public static function para(?Empresa $empresa): array
    {
        $especifica = $empresa?->rubro?->terminologia['area_especifica'] ?? 'Área específica';

        return [
            Espacio::EDIFICIO => ['singular' => 'Zona / Edificio', 'plural' => 'Zonas / Edificios'],
            Espacio::AREA => ['singular' => 'Piso', 'plural' => 'Pisos'],
            Espacio::AREA_ESPECIFICA => ['singular' => $especifica, 'plural' => self::plural($especifica)],
            Espacio::SUBAREA => ['singular' => 'Área', 'plural' => 'Áreas'],
            Espacio::ELEMENTO => ['singular' => 'Elemento', 'plural' => 'Elementos'],
        ];
    }

    /**
     * Plural en español de un sustantivo (o de la primera palabra de una frase).
     */
    public static function plural(string $texto): string
    {
        $partes = explode(' ', $texto, 2);
        $palabra = $partes[0];
        $sinAcento = strtr($palabra, ['ón' => 'on', 'án' => 'an', 'én' => 'en', 'ín' => 'in', 'ún' => 'un']);

        $plural = match (true) {
            preg_match('/[aeiouáéíóú]$/iu', $palabra) === 1 => $palabra.'s',
            preg_match('/z$/iu', $palabra) === 1 => mb_substr($palabra, 0, -1).'ces',
            default => $sinAcento.'es',
        };

        // "Área específica" -> "Áreas específicas"
        return isset($partes[1]) ? $plural.' '.self::plural($partes[1]) : $plural;
    }
}
