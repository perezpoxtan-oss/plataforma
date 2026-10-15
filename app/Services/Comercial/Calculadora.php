<?php

namespace App\Services\Comercial;

use App\Models\Paquete;
use App\Models\Plan;
use Illuminate\Support\Collection;

/**
 * Cálculo del costo de un plan con sus paquetes. Es la ÚNICA regla de precio:
 * la calculadora de la página pública (public/js/landing-calculo.js) hace
 * exactamente lo mismo y una prueba compara ambos resultados.
 *
 * Reglas (todo en centavos, con enteros):
 *  - Sedes cobradas = el mayor entre las sedes pedidas y las sedes mínimas del plan.
 *  - Plan: precio por sede al mes × sedes cobradas → mensual.
 *  - Paquete de suscripción: precio × (sedes cobradas si es por sede; si no, 1) → mensual.
 *  - Paquete de pago único: precio × (sedes cobradas si es por sede; si no, 1) → pago único.
 *  - IVA = redondeo(subtotal × IVA % / 100), por separado para lo mensual y lo único.
 *  - Plan «Cotizar»: sin precio; el resultado marca requiere_cotizacion y el plan no suma.
 *  - Un paquete que no se puede sumar al plan elegido se rechaza (no se ignora en silencio).
 */
class Calculadora
{
    public function __construct(private readonly AjustesComerciales $ajustes) {}

    public static function centavos(mixed $precio): int
    {
        return (int) round(((float) $precio) * 100);
    }

    public static function compatible(Paquete $paquete, ?Plan $plan): bool
    {
        $planes = $paquete->relationLoaded('planes') ? $paquete->planes : $paquete->planes()->get();

        return $planes->isEmpty() || ($plan !== null && $planes->contains('id', $plan->id));
    }

    /**
     * @param  Collection<int, Paquete>  $paquetes
     * @return array{moneda: string, iva_porcentaje: float, sedes: int, sedes_cobradas: int, requiere_cotizacion: bool,
     *     renglones: list<array<string, mixed>>, incompatibles: list<string>,
     *     mensual: array{subtotal: int, iva: int, total: int}, unico: array{subtotal: int, iva: int, total: int}}
     */
    public function calcular(?Plan $plan, int $sedes, Collection $paquetes): array
    {
        $sedes = max(1, min(999, $sedes));
        $cobradas = max($sedes, (int) ($plan?->sedes_minimas ?? 1));
        $renglones = [];
        $incompatibles = [];
        $mensual = 0;
        $unico = 0;
        $cotizar = false;

        if ($plan !== null) {
            $cotizar = $plan->cotizar;
            $precio = $cotizar ? 0 : self::centavos($plan->precio_por_sede_mensual);
            $importe = $precio * $cobradas;
            $mensual += $importe;
            $renglones[] = [
                'tipo' => 'plan', 'id' => $plan->id, 'nombre' => $plan->nombre, 'modalidad' => Paquete::SUSCRIPCION,
                'precio' => $precio, 'cantidad' => $cobradas, 'importe' => $importe, 'cotizar' => $cotizar,
            ];
        }

        foreach ($paquetes as $paquete) {
            if (! self::compatible($paquete, $plan)) {
                $incompatibles[] = $paquete->nombre;

                continue;
            }
            $precio = self::centavos($paquete->precio);
            $cantidad = $paquete->por_sede ? $cobradas : 1;
            $importe = $precio * $cantidad;
            $paquete->esUnico() ? $unico += $importe : $mensual += $importe;
            $renglones[] = [
                'tipo' => 'paquete', 'id' => $paquete->id, 'nombre' => $paquete->nombre, 'modalidad' => $paquete->modalidad,
                'precio' => $precio, 'cantidad' => $cantidad, 'importe' => $importe, 'cotizar' => false,
            ];
        }

        $iva = $this->ajustes->ivaCentesimas();

        return [
            'moneda' => (string) $this->ajustes->get('moneda'),
            'iva_porcentaje' => (float) $this->ajustes->get('iva_porcentaje'),
            'sedes' => $sedes,
            'sedes_cobradas' => $cobradas,
            'requiere_cotizacion' => $cotizar,
            'renglones' => $renglones,
            'incompatibles' => $incompatibles,
            'mensual' => self::totales($mensual, $iva),
            'unico' => self::totales($unico, $iva),
        ];
    }

    /**
     * @return array{subtotal: int, iva: int, total: int}
     */
    public static function totales(int $subtotal, int $ivaCentesimas): array
    {
        // Enteros: subtotal (centavos) × centésimas de punto / 10 000, redondeo a la mitad hacia arriba (igual que Math.round en JS para positivos)
        $iva = intdiv($subtotal * $ivaCentesimas + 5000, 10000);

        return ['subtotal' => $subtotal, 'iva' => $iva, 'total' => $subtotal + $iva];
    }

    /**
     * Lo que necesita la calculadora del navegador (solo lo público).
     *
     * @param  Collection<int, Plan>  $planes
     * @param  Collection<int, Paquete>  $paquetes
     * @return array<string, mixed>
     */
    public function datosParaNavegador(Collection $planes, Collection $paquetes): array
    {
        return [
            'moneda' => $this->ajustes->get('moneda'),
            'iva' => $this->ajustes->ivaCentesimas(),
            'leyenda' => $this->ajustes->get('leyenda_iva'),
            'planes' => $planes->map(fn (Plan $p) => [
                'id' => $p->id, 'nombre' => $p->nombre, 'precio' => $p->cotizar ? 0 : self::centavos($p->precio_por_sede_mensual),
                'minimas' => (int) $p->sedes_minimas, 'cotizar' => (bool) $p->cotizar,
            ])->values()->all(),
            'paquetes' => $paquetes->map(fn (Paquete $q) => [
                'id' => $q->id, 'nombre' => $q->nombre, 'modalidad' => $q->modalidad, 'precio' => self::centavos($q->precio),
                'porSede' => (bool) $q->por_sede, 'planes' => $q->planes->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * $1,234.50 (o «MXN 1,234.50» si se pide el código).
     */
    public static function formato(int $centavos, ?string $moneda = null): string
    {
        $texto = '$'.number_format($centavos / 100, 2, '.', ',');

        return $moneda !== null ? $texto.' '.$moneda : $texto;
    }

    /** Precio corto para tarjetas: sin centavos cuando son cero. */
    public static function precioCorto(int $centavos): string
    {
        return '$'.number_format($centavos / 100, $centavos % 100 === 0 ? 0 : 2, '.', ',');
    }
}
