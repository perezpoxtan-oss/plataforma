<?php

namespace App\Support;

use App\Models\Empresa;
use App\Models\User;
use App\Support\Tenancy\EmpresaDeTrabajo;
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
 * (accesibilidad). Las constantes son los valores originales; cada empresa
 * puede cambiarlos en Configuración → General (AjustesCaseta: vobo_horas_*).
 * Lo que no es de una empresa (Catálogo comercial) usa los originales.
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
     * @param  array{0: int, 1: int}|null  $umbrales  [horas amarillo, horas rojo]; null = los originales
     * @return array{minutos: int, hace: string, nivel: string, etiqueta: string, texto: string}|null
     */
    public static function de(?DateTimeInterface $desde, ?DateTimeInterface $ahora = null, ?array $umbrales = null): ?array
    {
        if ($desde === null) {
            return null;
        }
        $ahora ??= now();
        $minutos = (int) max(0, intdiv($ahora->getTimestamp() - $desde->getTimestamp(), 60));
        $nivel = self::nivel($minutos, $umbrales);
        $hace = 'hace '.self::duracion($minutos);
        $etiqueta = self::etiqueta($nivel, $umbrales);

        return [
            'minutos' => $minutos,
            'hace' => $hace,
            'nivel' => $nivel,
            'etiqueta' => $etiqueta,
            'texto' => 'Esperando Vo.Bo. '.$hace.' ('.$etiqueta.')',
        ];
    }

    /** Igual que de(), con los umbrales de la empresa (sin empresa: los originales). */
    public static function deEmpresa(?DateTimeInterface $desde, Empresa|int|null $empresa, ?DateTimeInterface $ahora = null): ?array
    {
        return self::de($desde, $ahora, self::umbrales($empresa));
    }

    /**
     * Umbrales [amarillo, rojo] en horas de la empresa.
     *
     * @return array{0: int, 1: int}
     */
    public static function umbrales(Empresa|int|null $empresa): array
    {
        $ajustes = AjustesCaseta::de($empresa);

        return [(int) $ajustes['vobo_horas_amarillo'], (int) $ajustes['vobo_horas_rojo']];
    }

    /** Umbrales de la empresa de trabajo de la sesión (para las pantallas). */
    public static function umbralesActuales(): array
    {
        $usuario = auth()->user();
        $empresaId = $usuario instanceof User ? app(EmpresaDeTrabajo::class)->id($usuario) : null;
        if ($empresaId === null) {
            return [self::HORAS_AMARILLO, self::HORAS_ROJO];
        }
        $memoria = request()->attributes;
        $clave = 'reloj_vobo.umbrales.'.$empresaId;
        if (! $memoria->has($clave)) {
            $empresa = $usuario->relationLoaded('empresa') && (int) $usuario->empresa?->id === $empresaId ? $usuario->empresa : $empresaId;
            $memoria->set($clave, self::umbrales($empresa));
        }

        return $memoria->get($clave);
    }

    public static function nivel(int $minutos, ?array $umbrales = null): string
    {
        [$amarillo, $rojo] = $umbrales ?? [self::HORAS_AMARILLO, self::HORAS_ROJO];

        return match (true) {
            $minutos > $rojo * 60 => 'roja',
            $minutos >= $amarillo * 60 => 'ambar',
            default => 'normal',
        };
    }

    /** Texto que acompaña al color: «a tiempo», «más de 1 día», «más de 36 h»… */
    public static function etiqueta(string $nivel, ?array $umbrales = null): string
    {
        [$amarillo, $rojo] = $umbrales ?? [self::HORAS_AMARILLO, self::HORAS_ROJO];

        return match ($nivel) {
            'ambar' => 'más de '.self::horas($amarillo),
            'roja' => 'más de '.self::horas($rojo),
            default => self::NIVELES['normal'],
        };
    }

    /** «1 día», «3 días», «36 h». */
    public static function horas(int $horas): string
    {
        if ($horas >= 24 && $horas % 24 === 0) {
            $dias = intdiv($horas, 24);

            return $dias.' '.($dias === 1 ? 'día' : 'días');
        }

        return $horas.' h';
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
