<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reporte de pérdida: lo que un huésped dice que le falta. Folio RP-000123
 * (distinto del LF- de los artículos encontrados). Se "vincula" con un
 * artículo encontrado; vincular no cierra el artículo: la entrega sigue
 * pasando por "Cerrar / Entregar". SEGCAT: lost_found_reportes_perdida.
 */
class LostFoundReportePerdida extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    public const BUSCANDO = 'BUSCANDO';

    public const VINCULADO = 'VINCULADO';

    public const ESTATUS = [
        'BUSCANDO' => 'Buscando',
        'VINCULADO' => 'Vinculado con un hallazgo',
        'CERRADO_SIN_HALLAZGO' => 'Cerrado sin hallazgo',
    ];

    protected $table = 'lost_found_reportes_perdida';

    protected $attributes = ['estatus' => self::BUSCANDO, 'tipo_valor' => 'OTRO'];

    protected $fillable = [
        'empresa_id', 'sede_id', 'novedad_id', 'objeto', 'tipo_valor', 'marca', 'color', 'descripcion', 'nombre_huesped',
        'area_especifica_id', 'fecha_aproximada', 'telefono', 'correo',
    ];

    protected function casts(): array
    {
        return ['fecha_aproximada' => 'date', 'vinculado_en' => 'datetime', 'numero' => 'integer'];
    }

    public function novedad(): BelongsTo
    {
        return $this->belongsTo(Novedad::class);
    }

    public function areaEspecifica(): BelongsTo
    {
        return $this->belongsTo(Espacio::class, 'area_especifica_id');
    }

    public function articuloVinculado(): BelongsTo
    {
        return $this->belongsTo(LostFoundArticulo::class, 'articulo_vinculado_id');
    }

    public function etiquetaEstatus(): string
    {
        return self::ESTATUS[$this->estatus] ?? $this->estatus;
    }
}
