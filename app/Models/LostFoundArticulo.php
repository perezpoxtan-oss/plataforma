<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use App\Models\Concerns\TieneIdentificador;
use App\Support\Lector\Identificable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Artículo encontrado (Lost & Found). Nace EN_RESGUARDO con folio LF-000123
 * (consecutivo por empresa, nunca cambia). El estatus solo lo cambia el
 * "Cerrar / Entregar" de la pantalla de Lost & Found (con firma), nunca la
 * edición del ticket. SEGCAT: lost_found_articulos.
 *
 * Es "identificable" (ADR-0005): la etiqueta de la bolsa lleva un QR con su
 * codigo_qr y también se encuentra tecleando o escaneando el folio.
 */
class LostFoundArticulo extends Model implements Identificable
{
    use PerteneceAEmpresa, RegistraAutor, TieneIdentificador;

    /** Se encuentra también tecleando o escaneando el folio (LF-000123). */
    protected string $columnaLegible = 'folio';

    /** Mismos códigos que SEGCAT (y que los umbrales). */
    public const TIPOS_VALOR = [
        'OTRO' => 'Otro',
        'ALTO_VALOR' => 'Alto Valor',
        'ELECTRONICO' => 'Electrónico',
        'ROPA' => 'Ropa',
        'PERECEDERO' => 'Perecedero',
    ];

    public const EN_RESGUARDO = 'EN_RESGUARDO';

    public const ESTATUS = [
        'EN_RESGUARDO' => 'En Resguardo',
        'DEVUELTO' => 'Devuelto al Huésped',
        'DONADO' => 'Donado a Colaborador',
        'DESTRUIDO' => 'Destruido',
        'ENTREGADO_BENEFICENCIA' => 'Entregado a Beneficencia',
    ];

    /** Cómo se cierra => estatus en que queda el artículo (SEGCAT: lf_articulo_proceso.php). */
    public const ESTATUS_POR_CIERRE = [
        'PERSONA' => 'DEVUELTO',
        'PAQUETERIA' => 'DEVUELTO',
        'DONADO' => 'DONADO',
        'DESTRUIDO' => 'DESTRUIDO',
        'BENEFICENCIA' => 'ENTREGADO_BENEFICENCIA',
    ];

    protected $table = 'lost_found_articulos';

    protected $attributes = ['estatus' => self::EN_RESGUARDO, 'tipo_valor' => 'OTRO'];

    protected $fillable = [
        'empresa_id', 'sede_id', 'novedad_id', 'objeto', 'tipo_valor', 'marca', 'color', 'area_especifica_id', 'lugar_detalle', 'ubicacion_bodega',
    ];

    protected function casts(): array
    {
        return ['cerrado_en' => 'datetime', 'numero' => 'integer'];
    }

    public function novedad(): BelongsTo
    {
        return $this->belongsTo(Novedad::class);
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class)->withTrashed();
    }

    public function areaEspecifica(): BelongsTo
    {
        return $this->belongsTo(Espacio::class, 'area_especifica_id');
    }

    public function reportesVinculados(): HasMany
    {
        return $this->hasMany(LostFoundReportePerdida::class, 'articulo_vinculado_id');
    }

    /** Casos de Robo que resultaron ser este hallazgo. */
    public function robosVinculados(): HasMany
    {
        return $this->hasMany(RoboDetalle::class, 'articulo_vinculado_id');
    }

    /** Cierre / entrega (uno por artículo: un artículo cerrado ya no se vuelve a cerrar). */
    public function entrega(): HasOne
    {
        return $this->hasOne(LostFoundEntrega::class, 'articulo_id')->latestOfMany();
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizado_por');
    }

    public function cerrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cerrado_por');
    }

    public function enResguardo(): bool
    {
        return $this->estatus === self::EN_RESGUARDO;
    }

    public function etiquetaEstatus(): string
    {
        return self::ESTATUS[$this->estatus] ?? $this->estatus;
    }

    public function etiquetaTipo(): string
    {
        return self::TIPOS_VALOR[$this->tipo_valor] ?? $this->tipo_valor;
    }

    /**
     * Semáforo de días en resguardo contra el umbral de su tipo de valor:
     * verde (en tiempo), amarillo (≥ 70 % del umbral), rojo (vencido).
     *
     * @param  array<string, int>  $umbrales
     * @return array{clase: string, texto: string, dias: int}|null
     */
    public function semaforo(array $umbrales): ?array
    {
        if (! $this->enResguardo() || $this->created_at === null) {
            return null;
        }
        $dias = (int) floor($this->created_at->diffInDays(now()));
        $umbral = $umbrales[$this->tipo_valor] ?? LostFoundUmbral::POR_OMISION['OTRO'];

        return match (true) {
            $dias >= $umbral => ['clase' => 'rojo', 'texto' => 'Vencido', 'dias' => $dias],
            $dias >= $umbral * 0.7 => ['clase' => 'amarillo', 'texto' => 'Por vencer', 'dias' => $dias],
            default => ['clase' => 'verde', 'texto' => 'En tiempo', 'dias' => $dias],
        };
    }

    // ------------------------------------------------------------ Lector universal

    public static function tipoLector(): string
    {
        return 'lost_found';
    }

    public static function permisoLector(): string
    {
        return 'lost_found.ver';
    }

    public function resumenLector(): array
    {
        $this->loadMissing('sede:id,nombre');

        return [
            'titulo' => $this->folio,
            'detalle' => implode(' · ', array_filter([$this->objeto, $this->etiquetaEstatus(), $this->ubicacion_bodega ? 'Bodega: '.$this->ubicacion_bodega : null, $this->sede?->nombre])),
            'activo' => $this->enResguardo(),
            'sede_id' => $this->sede_id,
        ];
    }

    public function urlLector(): string
    {
        return route('lost_found.articulos.show', $this->id);
    }
}
