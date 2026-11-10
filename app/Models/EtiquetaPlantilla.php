<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Plantilla de etiqueta QR (Ronda 7, gestor de impresión; ver
 * docs/tecnico/etiquetas-qr.md): medidas en milímetros para un rollo
 * térmico (una etiqueta por página) o una hoja carta/A4 con planilla de
 * N × M, qué datos lleva y el tamaño del QR. De toda la empresa
 * (sede_id nulo) o de una sede.
 */
class EtiquetaPlantilla extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    protected $table = 'etiquetas_plantillas';

    public const FORMATOS = [
        'rollo' => 'Rollo térmico (una etiqueta por página)',
        'hoja' => 'Hoja carta o A4 con planilla de etiquetas',
    ];

    /** papel => [nombre, ancho mm, alto mm] */
    public const PAPELES = [
        'carta' => ['Carta (216 × 279 mm)', 215.9, 279.4],
        'a4' => ['A4 (210 × 297 mm)', 210.0, 297.0],
    ];

    public const ORIENTACIONES = [
        'horizontal' => 'Horizontal: QR a la izquierda y texto a la derecha',
        'vertical' => 'Vertical: QR arriba y texto abajo',
    ];

    /** Datos que se pueden mostrar => etiqueta */
    public const DATOS = [
        'mostrar_titulo' => 'Nombre o título (nomenclatura, placas, serie…)',
        'mostrar_codigo' => 'Código legible (para teclearlo si el QR no se lee)',
        'mostrar_tipo' => 'Tipo de registro (Llave, Gafete, Vehículo…)',
        'mostrar_ubicacion' => 'Departamento / sede',
        'mostrar_fecha' => 'Fecha de impresión',
        'mostrar_logo' => 'Logo de la empresa (o su nombre si no tiene logo)',
    ];

    protected $fillable = [
        'empresa_id', 'sede_id', 'clave', 'nombre', 'formato', 'papel', 'ancho_mm', 'alto_mm',
        'margen_superior_mm', 'margen_izquierdo_mm', 'separacion_horizontal_mm', 'separacion_vertical_mm',
        'columnas', 'filas', 'orientacion', 'qr_mm',
        'mostrar_titulo', 'mostrar_codigo', 'mostrar_fecha', 'mostrar_ubicacion', 'mostrar_tipo', 'mostrar_logo', 'activo',
    ];

    protected function casts(): array
    {
        return [
            'ancho_mm' => 'float', 'alto_mm' => 'float', 'margen_superior_mm' => 'float', 'margen_izquierdo_mm' => 'float',
            'separacion_horizontal_mm' => 'float', 'separacion_vertical_mm' => 'float', 'qr_mm' => 'float',
            'columnas' => 'integer', 'filas' => 'integer',
            'mostrar_titulo' => 'boolean', 'mostrar_codigo' => 'boolean', 'mostrar_fecha' => 'boolean',
            'mostrar_ubicacion' => 'boolean', 'mostrar_tipo' => 'boolean', 'mostrar_logo' => 'boolean', 'activo' => 'boolean',
        ];
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    public function esHoja(): bool
    {
        return $this->formato === 'hoja';
    }

    /** Etiquetas por página: 1 en rollo; columnas × filas en hoja. */
    public function porPagina(): int
    {
        return $this->esHoja() ? max(1, $this->columnas * $this->filas) : 1;
    }

    /**
     * Medida de la página en mm (para @page): la hoja elegida, o la etiqueta
     * más su separación en un rollo.
     *
     * @return array{0: float, 1: float}
     */
    public function pagina(): array
    {
        if ($this->esHoja()) {
            $papel = self::PAPELES[$this->papel] ?? self::PAPELES['carta'];

            return [$papel[1], $papel[2]];
        }

        return [$this->ancho_mm, $this->alto_mm + $this->separacion_vertical_mm];
    }

    /** «50 × 25 mm · rollo térmico» / «86 × 54 mm · hoja carta, 2 × 4 = 8 por hoja» */
    public function resumen(): string
    {
        $medida = $this->mm($this->ancho_mm).' × '.$this->mm($this->alto_mm).' mm';

        return $this->esHoja()
            ? $medida.' · hoja '.($this->papel === 'a4' ? 'A4' : 'carta').', '.$this->columnas.' × '.$this->filas.' = '.$this->porPagina().' por hoja'
            : $medida.' · rollo térmico, una por página';
    }

    /** 50.0 → «50»; 25.4 → «25.4» */
    public function mm(?float $valor): string
    {
        return rtrim(rtrim(number_format((float) $valor, 1, '.', ''), '0'), '.');
    }
}
