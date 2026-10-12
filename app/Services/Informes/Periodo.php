<?php

namespace App\Services\Informes;

use Carbon\CarbonImmutable;

/**
 * Periodo de un informe en la hora local de la sede o empresa (ADR-0017).
 *
 * Como el sistema original: «semana», «mes», «trimestre» y «año» son de
 * calendario (del lunes, del día 1… hasta hoy) y «personalizado» va de
 * «desde» a «hasta». El periodo anterior dura lo mismo y termina el día
 * antes de que empiece este. Las consultas usan los límites en hora
 * universal (así se guardan las fechas), sin funciones de fecha del motor.
 */
final class Periodo
{
    public const TIPOS = [
        'semana' => 'Esta semana',
        'mes' => 'Este mes',
        'trimestre' => 'Este trimestre',
        'anio' => 'Este año',
        'personalizado' => 'Personalizado',
    ];

    /** Un periodo personalizado no pasa de dos años (la gráfica se agrupa por mes). */
    public const MAX_DIAS = 731;

    private const MESES = [1 => 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    private function __construct(
        public readonly string $tipo,
        public readonly CarbonImmutable $desde,
        public readonly CarbonImmutable $hasta,
        public readonly string $zona,
    ) {}

    public static function crear(string $tipo, ?string $desde, ?string $hasta, string $zona, ?CarbonImmutable $ahora = null): self
    {
        $hoy = ($ahora ?? CarbonImmutable::now())->setTimezone($zona)->startOfDay();
        $tipo = array_key_exists($tipo, self::TIPOS) ? $tipo : 'semana';

        [$inicio, $fin] = match ($tipo) {
            'mes' => [$hoy->startOfMonth(), $hoy],
            'trimestre' => [$hoy->firstOfQuarter(), $hoy],
            'anio' => [$hoy->startOfYear(), $hoy],
            'personalizado' => [self::fecha($desde, $zona) ?? $hoy->subDays(29), self::fecha($hasta, $zona) ?? $hoy],
            default => [$hoy->startOfWeek(CarbonImmutable::MONDAY), $hoy],
        };
        if ($inicio->greaterThan($fin)) {
            [$inicio, $fin] = [$fin, $inicio];
        }
        if ($inicio->diffInDays($fin) >= self::MAX_DIAS) {
            $inicio = $fin->subDays(self::MAX_DIAS - 1);
        }

        return new self($tipo, $inicio->startOfDay(), $fin->endOfDay(), $zona);
    }

    private static function fecha(?string $valor, string $zona): ?CarbonImmutable
    {
        if (! is_string($valor) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            return null;
        }
        try {
            $f = CarbonImmutable::createFromFormat('!Y-m-d', $valor, $zona);
        } catch (\Throwable) {
            return null;
        }

        return $f !== false && $f->format('Y-m-d') === $valor ? $f : null;
    }

    public function dias(): int
    {
        return (int) $this->desde->startOfDay()->diffInDays($this->hasta->startOfDay()) + 1;
    }

    /** Mismo largo, inmediatamente antes. */
    public function anterior(): self
    {
        $fin = $this->desde->subDay();

        return new self($this->tipo, $fin->subDays($this->dias() - 1)->startOfDay(), $fin->endOfDay(), $this->zona);
    }

    /** Límite inferior en hora universal (incluido). */
    public function inicioUtc(): string
    {
        return $this->desde->utc()->format('Y-m-d H:i:s');
    }

    /** Límite superior en hora universal (excluido): el inicio del día siguiente. */
    public function finUtc(): string
    {
        return $this->hasta->addSecond()->startOfDay()->utc()->format('Y-m-d H:i:s');
    }

    /** Día, semana o mes según el largo (como el sistema original: nunca 365 puntos). */
    public function agrupacion(): string
    {
        return match (true) {
            $this->dias() <= 14 => 'dia',
            $this->dias() <= 120 => 'semana',
            default => 'mes',
        };
    }

    /**
     * Cubetas de la gráfica: [etiqueta, inicio UTC, fin UTC (excluido)].
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public function cubetas(?string $agrupacion = null): array
    {
        $agrupacion ??= $this->agrupacion();
        $limite = $this->hasta->addSecond()->startOfDay();
        $cubetas = [];
        $inicio = $this->desde;
        while ($inicio->lessThan($limite)) {
            $siguiente = match ($agrupacion) {
                'semana' => $inicio->addWeek()->startOfWeek(CarbonImmutable::MONDAY),
                'mes' => $inicio->addMonthNoOverflow()->startOfMonth(),
                default => $inicio->addDay(),
            };
            if ($siguiente->greaterThan($limite)) {
                $siguiente = $limite;
            }
            $etiqueta = match ($agrupacion) {
                'semana' => $inicio->format('d').' '.self::MESES[$inicio->month],
                'mes' => self::MESES[$inicio->month].' '.$inicio->format('y'),
                default => $inicio->format('d/m'),
            };
            $cubetas[] = [$etiqueta, $inicio->utc()->format('Y-m-d H:i:s'), $siguiente->utc()->format('Y-m-d H:i:s')];
            $inicio = $siguiente;
        }

        return $cubetas;
    }

    /**
     * Las mismas cubetas recorridas al periodo anterior (para comparar punto a punto).
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public function cubetasAnteriores(): array
    {
        $mover = fn (string $utc) => CarbonImmutable::parse($utc, 'UTC')->setTimezone($this->zona)->subDays($this->dias())->utc()->format('Y-m-d H:i:s');

        return array_map(fn ($c) => [$c[0], $mover($c[1]), $mover($c[2])], $this->cubetas());
    }

    /** «06/10/2026 – 12/10/2026» */
    public function texto(): string
    {
        return $this->desde->format('d/m/Y').' – '.$this->hasta->format('d/m/Y');
    }

    public function clave(): string
    {
        return $this->desde->format('Ymd').'-'.$this->hasta->format('Ymd').'-'.$this->zona;
    }
}
