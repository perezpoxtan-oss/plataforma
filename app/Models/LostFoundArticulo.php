<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Artículo encontrado (Lost & Found). Nace EN_RESGUARDO con folio LF-000123
 * (consecutivo por empresa, nunca cambia). El estatus solo lo cambia el
 * "Cerrar / Entregar" de la pantalla de Lost & Found (con firma), nunca la
 * edición del ticket. SEGCAT: lost_found_articulos.
 */
class LostFoundArticulo extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

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
}
