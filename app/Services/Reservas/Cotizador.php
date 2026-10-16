<?php

namespace App\Services\Reservas;

use App\Models\ReservaArea;
use Carbon\CarbonImmutable;

/**
 * Costo de una reserva. Uso interno: sin costo (todo en cero). Para un
 * cliente de la sede: tarifa del área (por hora —cada hora o fracción—,
 * medio día, día completo o por evento; o el importe que fije quien tiene
 * permiso) + servicios (cantidad × costo) − descuento (o cortesía con
 * motivo) + IVA de la empresa si aplica.
 */
class Cotizador
{
    /**
     * @param  list<array{cantidad: float, costo_unitario: float}>  $items
     * @param  array{iva_aplica: bool, iva_porcentaje: float}  $ajustes
     * @return array{tarifa: ?string, importe_area: float, importe_servicios: float, descuento: float, iva_porcentaje: float, iva: float, total: float, items: list<float>}
     */
    public function calcular(ReservaArea $area, bool $paraCliente, ?string $tarifa, CarbonImmutable $inicio, CarbonImmutable $fin, array $items,
        array $ajustes, ?float $importeAreaManual = null, float $descuento = 0, bool $cortesia = false): array
    {
        if (! $paraCliente) {
            return ['tarifa' => null, 'importe_area' => 0.0, 'importe_servicios' => 0.0, 'descuento' => 0.0, 'iva_porcentaje' => 0.0,
                'iva' => 0.0, 'total' => 0.0, 'items' => array_map(fn () => 0.0, $items)];
        }

        $tarifas = $area->tarifas();
        $tarifa = $tarifa !== null && isset($tarifas[$tarifa]) ? $tarifa : self::tarifaPorOmision($tarifas);
        $importeArea = match ($tarifa) {
            'hora' => $tarifas['hora'] * self::horasCobradas($inicio, $fin),
            null => 0.0,
            default => $tarifas[$tarifa],
        };
        if ($importeAreaManual !== null) {
            $importeArea = $importeAreaManual;
        }

        $importes = array_map(fn ($i) => round((float) $i['cantidad'] * (float) $i['costo_unitario'], 2), $items);
        $servicios = round(array_sum($importes), 2);
        $subtotal = round($importeArea + $servicios, 2);
        $descuento = $cortesia ? $subtotal : min($subtotal, max(0.0, round($descuento, 2)));
        $base = round($subtotal - $descuento, 2);
        $porcentaje = $ajustes['iva_aplica'] ? (float) $ajustes['iva_porcentaje'] : 0.0;
        $iva = round($base * $porcentaje / 100, 2);

        return ['tarifa' => $tarifa, 'importe_area' => round($importeArea, 2), 'importe_servicios' => $servicios, 'descuento' => $descuento,
            'iva_porcentaje' => $porcentaje, 'iva' => $iva, 'total' => round($base + $iva, 2), 'items' => $importes];
    }

    /**
     * @param  array<string, float>  $tarifas
     */
    public static function tarifaPorOmision(array $tarifas): ?string
    {
        foreach (['evento', 'hora', 'medio_dia', 'dia'] as $clave) {
            if (isset($tarifas[$clave])) {
                return $clave;
            }
        }

        return null;
    }

    /** Horas que se cobran: cada hora o fracción. */
    public static function horasCobradas(CarbonImmutable $inicio, CarbonImmutable $fin): int
    {
        return max(1, (int) ceil($inicio->diffInMinutes($fin, true) / 60));
    }

    /** «$1,250.00 MXN». */
    public static function dinero(float|string|null $monto, string $moneda = 'MXN'): string
    {
        return '$'.number_format((float) $monto, 2).' '.$moneda;
    }
}
