<?php

namespace App\Models;

use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Promoción o código de descuento (Catálogo comercial → Promociones):
 * porcentaje o monto fijo al mes, vigencia, planes y periodicidad a los que
 * aplica, límite de usos y «primeros N meses». Sin código solo se aplica a
 * mano a un cliente. Global de la plataforma.
 */
class Promocion extends Model
{
    use RegistraAutor;

    public const PORCENTAJE = 'porcentaje';

    public const MONTO = 'monto';

    public const TIPOS = [self::PORCENTAJE => 'Porcentaje', self::MONTO => 'Monto fijo al mes'];

    public const PERIODICIDADES = ['ambas' => 'Mensual y anual', 'mensual' => 'Solo mensual', 'anual' => 'Solo anual'];

    protected $table = 'promociones';

    protected $attributes = ['tipo' => self::PORCENTAJE, 'valor' => 0, 'periodicidad' => 'ambas', 'usos' => 0, 'activo' => true];

    protected $fillable = ['nombre', 'codigo', 'tipo', 'valor', 'periodicidad', 'meses', 'vigente_desde', 'vigente_hasta', 'usos_maximos', 'activo'];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2', 'meses' => 'integer', 'vigente_desde' => 'date', 'vigente_hasta' => 'date',
            'usos_maximos' => 'integer', 'usos' => 'integer', 'activo' => 'boolean',
        ];
    }

    /** Planes a los que aplica (vacío = a todos). */
    public function planes(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'promocion_plan');
    }

    /** @param  Builder<Promocion>  $q */
    public function scopeActivas(Builder $q): Builder
    {
        return $q->where('promociones.activo', true);
    }

    /**
     * Por qué NO se puede usar (null = sí se puede).
     */
    public function motivoNoAplica(?Plan $plan, string $periodicidad, ?Carbon $hoy = null): ?string
    {
        $hoy = ($hoy ?? now())->copy()->startOfDay();
        if (! $this->activo) {
            return 'Esa promoción ya no está disponible.';
        }
        if ($this->vigente_desde !== null && $hoy->lt($this->vigente_desde)) {
            return 'Esa promoción todavía no empieza: vale desde el '.$this->vigente_desde->format('d/m/Y').'.';
        }
        if ($this->vigente_hasta !== null && $hoy->gt($this->vigente_hasta)) {
            return 'Esa promoción ya venció.';
        }
        if ($this->usos_maximos !== null && $this->usos >= $this->usos_maximos) {
            return 'Esa promoción ya se agotó.';
        }
        if ($this->periodicidad !== 'ambas' && $this->periodicidad !== $periodicidad) {
            return 'Esa promoción solo aplica al pago '.($this->periodicidad === 'anual' ? 'anual' : 'mensual').'.';
        }
        $planes = $this->relationLoaded('planes') ? $this->planes : $this->planes()->get();
        if ($planes->isNotEmpty() && ($plan === null || ! $planes->contains('id', $plan->id))) {
            return 'Esa promoción solo aplica a '.$planes->pluck('nombre')->join(', ', ' y ').'.';
        }

        return null;
    }

    /** «15 %» o «$200 al mes», con «los primeros 3 meses» si aplica. */
    public function resumen(): string
    {
        $valor = $this->tipo === self::PORCENTAJE
            ? rtrim(rtrim(number_format((float) $this->valor, 2), '0'), '.').' %'
            : '$'.number_format((float) $this->valor, 2).' al mes';

        return $valor.' de descuento'.($this->meses !== null ? ' los primeros '.$this->meses.' '.($this->meses === 1 ? 'mes' : 'meses') : '');
    }

    /**
     * Lo que necesita la Calculadora (servidor y navegador): valor en centésimas de punto o en centavos.
     *
     * @return array{id: int, nombre: string, codigo: ?string, tipo: string, valor: int, meses: ?int}
     */
    public function descriptor(): array
    {
        return [
            'id' => $this->id, 'nombre' => $this->nombre, 'codigo' => $this->codigo, 'tipo' => $this->tipo,
            'valor' => (int) round(((float) $this->valor) * 100), 'meses' => $this->meses,
        ];
    }

    /**
     * Para la calculadora del navegador: el descriptor más a qué planes y periodicidad aplica
     * (el navegador deja de aplicarla si cambian el plan o la forma de pago; el servidor siempre la vuelve a validar).
     *
     * @return array<string, mixed>
     */
    public function paraNavegador(): array
    {
        return $this->descriptor() + [
            'planes' => $this->planes()->pluck('planes.id')->map(fn ($id) => (int) $id)->values()->all(),
            'periodicidad' => $this->periodicidad,
        ];
    }

    public static function normalizarCodigo(?string $codigo): string
    {
        return mb_strtoupper(preg_replace('/\s+/', '', trim((string) $codigo)) ?? '');
    }
}
