<?php

namespace App\Services\Comercial;

use App\Models\Paquete;
use App\Models\Plan;
use App\Models\Producto;
use App\Models\SolicitudCompra;
use App\Services\Comercial\Pagos\Pasarelas;
use App\Services\Comercial\Pagos\ResultadoCobro;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Lo que muestra la página pública (solo activo y publicado) y el registro
 * de solicitudes. El precio SIEMPRE lo calcula el servidor (Calculadora):
 * del navegador solo se toman el plan, las sedes y los paquetes elegidos.
 */
class CatalogoPublico
{
    public function __construct(
        private readonly AjustesComerciales $ajustes,
        private readonly Calculadora $calculadora,
        private readonly Pasarelas $pasarelas,
    ) {}

    /** @return Collection<int, Plan> */
    public function planes(): Collection
    {
        return Plan::publicos()->with('productos:id,nombre,icono')->get();
    }

    /** @return Collection<int, Paquete> */
    public function paquetes(): Collection
    {
        return Paquete::publicos()->with(['productos:id,nombre,icono', 'planes:id,nombre'])->get();
    }

    /**
     * Productos que se muestran: los activos que están en algún plan o paquete publicado.
     *
     * @return Collection<int, Producto>
     */
    public function productos(Collection $planes, Collection $paquetes): Collection
    {
        $ids = $planes->flatMap->productos->pluck('id')->merge($paquetes->flatMap->productos->pluck('id'))->unique();

        return Producto::activos()->whereIn('id', $ids)->get();
    }

    /**
     * Todo lo de la página en una sola llamada.
     *
     * @return array<string, mixed>
     */
    public function pagina(): array
    {
        $planes = $this->planes();
        $paquetes = $this->paquetes();
        // La calculadora abre con el plan recomendado (o el primero) y una sede
        $inicial = $planes->firstWhere('destacado', true) ?? $planes->first();

        return [
            'ajustes' => $this->ajustes->todos(),
            'planes' => $planes,
            'paquetes' => $paquetes,
            'productos' => $this->productos($planes, $paquetes),
            'calculadora' => $this->calculadora->datosParaNavegador($planes, $paquetes),
            'eleccionCalculadora' => ['plan_id' => $inicial?->id, 'sedes' => 1, 'paquetes' => []],
            'calculoCalculadora' => $this->calculadora->calcular($inicial, 1, collect()),
        ];
    }

    /**
     * Calcula lo elegido con los precios de hoy (solo planes y paquetes publicados).
     *
     * @param  list<int>  $paquetesIds
     * @return array{0: ?Plan, 1: Collection<int, Paquete>, 2: array<string, mixed>}
     */
    public function cotizar(?int $planId, int $sedes, array $paquetesIds): array
    {
        $plan = $planId ? Plan::publicos()->whereKey($planId)->first() : null;
        $paquetes = $paquetesIds === [] ? collect() : Paquete::publicos()->whereIn('id', $paquetesIds)->with('planes:id')->get();

        return [$plan, $paquetes, $this->calculadora->calcular($plan, $sedes, $paquetes)];
    }

    /**
     * Registra la solicitud con su desglose congelado y la entrega a la pasarela activa.
     *
     * @param  array{nombre: string, empresa?: ?string, correo: string, telefono?: ?string, sedes: int, mensaje?: ?string,
     *     plan_id?: ?int, paquetes?: list<int>}  $datos
     * @return array{0: SolicitudCompra, 1: ResultadoCobro}
     */
    public function registrar(array $datos, string $ip): array
    {
        [$plan, $paquetes, $calculo] = $this->cotizar($datos['plan_id'] ?? null, (int) $datos['sedes'], $datos['paquetes'] ?? []);
        if (! empty($datos['plan_id']) && $plan === null) {
            throw ValidationException::withMessages(['plan_id' => 'Ese plan ya no está disponible. Elige otro.']);
        }
        if ($calculo['incompatibles'] !== []) {
            throw ValidationException::withMessages(['paquetes' => 'No se puede sumar al plan elegido: '.implode(', ', $calculo['incompatibles']).'.']);
        }
        $peso = fn (int $c) => number_format($c / 100, 2, '.', '');

        $solicitud = DB::transaction(fn () => SolicitudCompra::create([
            'folio' => $this->folio(),
            'nombre' => $datos['nombre'],
            'empresa' => $datos['empresa'] ?? null,
            'correo' => mb_strtolower($datos['correo']),
            'telefono' => $datos['telefono'] ?? null,
            'sedes' => $calculo['sedes'],
            'mensaje' => $datos['mensaje'] ?? null,
            'plan_id' => $plan?->id,
            'moneda' => $calculo['moneda'],
            'iva_porcentaje' => $calculo['iva_porcentaje'],
            'mensual_subtotal' => $peso($calculo['mensual']['subtotal']),
            'mensual_iva' => $peso($calculo['mensual']['iva']),
            'mensual_total' => $peso($calculo['mensual']['total']),
            'unico_subtotal' => $peso($calculo['unico']['subtotal']),
            'unico_iva' => $peso($calculo['unico']['iva']),
            'unico_total' => $peso($calculo['unico']['total']),
            'requiere_cotizacion' => $calculo['requiere_cotizacion'],
            'detalle' => ['sedes_cobradas' => $calculo['sedes_cobradas'], 'renglones' => $calculo['renglones']],
            'estado' => 'nueva',
            'acepta_privacidad' => true,
            'privacidad_aceptada_en' => now(),
            'ip' => mb_substr($ip, 0, 45),
        ]));

        return [$solicitud, $this->pasarelas->activa()->crearCobro($solicitud)];
    }

    private function folio(): string
    {
        do {
            $folio = 'SC-'.now()->format('ymd').'-'.Str::upper(Str::random(4));
        } while (SolicitudCompra::where('folio', $folio)->exists());

        return $folio;
    }
}
