<?php

namespace App\Support;

use DateTimeInterface;

/**
 * Reloj y color de lo que espera un visto bueno (decisión del dueño,
 * 2026-10-12; criterio 3): vales de taxi sin Vo.Bo. en la Bitácora de
 * transporte y procedimientos en revisión.
 *
 *  - menos de HORAS_AMARILLO      → normal (verde, «a tiempo»)
 *  - de HORAS_AMARILLO a HORAS_ROJO → amarillo («más de 1 día»)
 *  - más de HORAS_ROJO             → rojo («más de 3 días»)
 *
 * El color nunca va solo: siempre lleva el texto del tiempo y del nivel
 * (accesibilidad). Para cambiar los umbrales, cambia estas constantes.
 */
final class RelojVoBo
{
    /** Desde estas horas de espera, amarillo. */
    public const HORAS_AMARILLO = 24;

    /** Más de estas horas de espera, rojo. */
    public const HORAS_ROJO = 72;

    /** nivel => texto que acompaña al color */
    public const NIVELES = [
        'normal' => 'a tiempo',
        'ambar' => 'más de 1 día',
        'roja' => 'más de 3 días',
    ];

    /**
     * @return array{minutos: int, hace: string, nivel: string, etiqueta: string, texto: string}|null
     */
    public static function de(?DateTimeInterface $desde, ?DateTimeInterface $ahora = null): ?array
    {
        if ($desde === null) {
            return null;
        }
        $ahora ??= now();
        $minutos = (int) max(0, intdiv($ahora->getTimestamp() - $desde->getTimestamp(), 60));
        $nivel = self::nivel($minutos);
        $hace = 'hace '.self::duracion($minutos);

        return [
            'minutos' => $minutos,
            'hace' => $hace,
            'nivel' => $nivel,
            'etiqueta' => self::NIVELES[$nivel],
            'texto' => 'Esperando Vo.Bo. '.$hace.' ('.self::NIVELES[$nivel].')',
        ];
    }

    public static function nivel(int $minutos): string
    {
        return match (true) {
            $minutos > self::HORAS_ROJO * 60 => 'roja',
            $minutos >= self::HORAS_AMARILLO * 60 => 'ambar',
            default => 'normal',
        };
    }

    /** «25 min», «5 h», «2 días 3 h». */
    public static function duracion(int $minutos): string
    {
        if ($minutos < 60) {
            return max(1, $minutos).' min';
        }
        $horas = intdiv($minutos, 60);
        if ($horas < 24) {
            return $horas.' h';
        }
        $dias = intdiv($horas, 24);
        $resto = $horas % 24;

        return $dias.' '.($dias === 1 ? 'día' : 'días').($resto > 0 ? ' '.$resto.' h' : '');
    }
}
