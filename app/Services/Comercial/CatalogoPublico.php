<?php

namespace App\Services\Comercial;

use App\Models\Paquete;
use App\Models\Plan;
use App\Models\Producto;
use App\Models\Promocion;
use App\Models\SolicitudCompra;
use App\Services\Comercial\Pagos\Pasarelas;
use App\Services\Comercial\Pagos\ResultadoCobro;
use App\Services\Suscripciones\Promociones;
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
        private readonly Promociones $promociones,
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
            'eleccionCalculadora' => ['plan_id' => $inicial?->id, 'sedes' => 1, 'paquetes' => [], 'periodicidad' => 'mensual', 'codigo_promocion' => ''],
            'calculoCalculadora' => $this->calculadora->calcular($inicial, 1, collect()),
        ];
    }

    /**
     * Calcula lo elegido con los precios de hoy (solo planes y paquetes publicados;
     * con $soloPublicos = false, cualquier plan o paquete activo: un cliente que renueva).
     *
     * @param  list<int>  $paquetesIds
     * @return array{0: ?Plan, 1: Collection<int, Paquete>, 2: array<string, mixed>}
     */
    public function cotizar(?int $planId, int $sedes, array $paquetesIds, string $periodicidad = 'mensual', ?Promocion $promocion = null, bool $soloPublicos = true): array
    {
        $planes = $soloPublicos ? Plan::publicos() : Plan::query()->where('activo', true);
        $plan = $planId ? $planes->whereKey($planId)->first() : null;
        $paquetes = $paquetesIds === [] ? collect()
            : ($soloPublicos ? Paquete::publicos() : Paquete::query()->where('activo', true))->whereIn('id', $paquetesIds)->with('planes:id')->get();

        return [$plan, $paquetes, $this->calculadora->calcular($plan, $sedes, $paquetes, $periodicidad, $promocion?->descriptor())];
    }

    /**
     * Valida el código de promoción contra lo elegido. Devuelve la promoción y el cálculo con su descuento.
     *
     * @param  list<int>  $paquetesIds
     * @return array{0: ?Plan, 1: Collection<int, Paquete>, 2: array<string, mixed>, 3: ?Promocion, 4: ?string}
     */
    public function cotizarConCodigo(?int $planId, int $sedes, array $paquetesIds, string $periodicidad, ?string $codigo, bool $soloPublicos = true): array
    {
        [$plan] = $this->cotizar($planId, $sedes, [], $periodicidad, null, $soloPublicos);
        [$promocion, $error] = $this->promociones->porCodigo($codigo, $plan, $periodicidad);
        [$plan, $paquetes, $calculo] = $this->cotizar($planId, $sedes, $paquetesIds, $periodicidad, $promocion, $soloPublicos);

        return [$plan, $paquetes, $calculo, $promocion, $error];
    }

    /**
     * Registra la solicitud con su desglose congelado y la entrega a la pasarela activa.
     *
     * @param  array{nombre: string, empresa?: ?string, correo: string, telefono?: ?string, sedes: int, mensaje?: ?string,
     *     plan_id?: ?int, paquetes?: list<int>, tipo?: string, periodicidad?: string, codigo_promocion?: ?string, empresa_id?: ?int}  $datos
     * @return array{0: SolicitudCompra, 1: ResultadoCobro}
     */
    public function registrar(array $datos, string $ip, bool $conPasarela = true): array
    {
        $tipo = isset(SolicitudCompra::TIPOS[$datos['tipo'] ?? '']) ? $datos['tipo'] : 'compra';
        $periodicidad = ($datos['periodicidad'] ?? 'mensual') === 'anual' ? 'anual' : 'mensual';
        $cliente = ! empty($datos['empresa_id']);
        [$plan, $paquetes, $calculo, $promocion, $error] = $this->cotizarConCodigo($datos['plan_id'] ?? null, (int) $datos['sedes'], $datos['paquetes'] ?? [],
            $periodicidad, $datos['codigo_promocion'] ?? null, soloPublicos: ! $cliente);
        if (! empty($datos['plan_id']) && $plan === null) {
            throw ValidationException::withMessages(['plan_id' => 'Ese plan ya no está disponible. Elige otro.']);
        }
        if ($error !== null) {
            throw ValidationException::withMessages(['codigo_promocion' => $error]);
        }
        if ($tipo === 'prueba' && $plan === null) {
            throw ValidationException::withMessages(['plan_id' => 'Elige el plan que quieres probar.']);
        }
        if ($calculo['incompatibles'] !== []) {
            throw ValidationException::withMessages(['paquetes' => 'No se puede sumar al plan elegido: '.implode(', ', $calculo['incompatibles']).'.']);
        }
        $peso = fn (int $c) => number_format($c / 100, 2, '.', '');

        $solicitud = DB::transaction(fn () => SolicitudCompra::create([
            'folio' => $this->folio(),
            'tipo' => $tipo,
            'periodicidad' => $calculo['periodicidad'],
            'periodo_subtotal' => $peso($calculo['periodo']['subtotal']),
            'periodo_iva' => $peso($calculo['periodo']['iva']),
            'periodo_total' => $peso($calculo['periodo']['total']),
            'descuento' => $peso($calculo['descuento_anual'] + $calculo['descuento_promocion']),
            'codigo_promocion' => $promocion?->codigo,
            'promocion_id' => $promocion?->id,
            'empresa_id' => $cliente ? (int) $datos['empresa_id'] : null,
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
            'detalle' => self::detalle($calculo),
            'estado' => 'nueva',
            'acepta_privacidad' => true,
            'privacidad_aceptada_en' => now(),
            'ip' => mb_substr($ip, 0, 45),
        ]));

        // Sin pasarela (datos de ejemplo): se registra sin avisos ni acuse
        return [$solicitud, $conPasarela ? $this->pasarelas->activa()->crearCobro($solicitud) : new ResultadoCobro('pendiente')];
    }

    /**
     * Desglose que se congela (solicitud y suscripción): renglones y descuentos con su precio de ese momento.
     *
     * @param  array<string, mixed>  $calculo
     * @return array<string, mixed>
     */
    public static function detalle(array $calculo): array
    {
        return [
            'sedes_cobradas' => $calculo['sedes_cobradas'], 'renglones' => $calculo['renglones'], 'mensual' => $calculo['mensual'],
            'periodicidad' => $calculo['periodicidad'], 'meses' => $calculo['meses'], 'descuento_anual' => $calculo['descuento_anual'],
            'descuento_promocion' => $calculo['descuento_promocion'], 'promocion' => $calculo['promocion'], 'periodo_regular' => $calculo['periodo_regular'],
        ];
    }

    private function folio(): string
    {
        do {
            $folio = 'SC-'.now()->format('ymd').'-'.Str::upper(Str::random(4));
        } while (SolicitudCompra::where('folio', $folio)->exists());

        return $folio;
    }
}
