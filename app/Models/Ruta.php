<?php

namespace App\Models;

use App\Models\Concerns\PerteneceAEmpresa;
use App\Models\Concerns\RegistraAutor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ruta de transporte de personal (SEGCAT: cat_rutas_transporte): llegada a
 * la sede o salida de la sede, con su turno, su empresa transportista y uno
 * o más horarios. hora_inicio, hora_fin y dias son un resumen de los horarios.
 */
class Ruta extends Model
{
    use PerteneceAEmpresa, RegistraAutor;

    public const SENTIDOS = ['llegada' => 'Llegada', 'salida' => 'Salida'];

    /** Código => etiqueta corta, en el orden de la semana (ISO: lunes = 1). */
    public const DIAS = ['LU' => 'Lun', 'MA' => 'Mar', 'MI' => 'Mié', 'JU' => 'Jue', 'VI' => 'Vie', 'SA' => 'Sáb', 'DO' => 'Dom'];

    public const DIAS_LARGOS = ['LU' => 'Lunes', 'MA' => 'Martes', 'MI' => 'Miércoles', 'JU' => 'Jueves', 'VI' => 'Viernes', 'SA' => 'Sábado', 'DO' => 'Domingo'];

    private const LETRAS = ['LU' => 'L', 'MA' => 'M', 'MI' => 'X', 'JU' => 'J', 'VI' => 'V', 'SA' => 'S', 'DO' => 'D'];

    private const LABORALES = ['LU', 'MA', 'MI', 'JU', 'VI'];

    private const FIN_DE_SEMANA = ['SA', 'DO'];

    protected $table = 'rutas';

    protected $attributes = ['activo' => true, 'sentido' => 'llegada'];

    protected $fillable = [
        'empresa_id', 'sede_id', 'sentido', 'nombre', 'turno_id', 'proveedor_id', 'costo_maximo_taxi',
        'hora_inicio', 'hora_fin', 'dias', 'activo',
    ];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'costo_maximo_taxi' => 'decimal:2'];
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    public function turno(): BelongsTo
    {
        return $this->belongsTo(Turno::class);
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function horarios(): HasMany
    {
        return $this->hasMany(RutaHorario::class)->orderBy('orden')->orderBy('id');
    }

    public function esLlegada(): bool
    {
        return $this->sentido === 'llegada';
    }

    // ------------------------------------------------------------ Días (texto)

    /**
     * "LU,MI" -> ["LU", "MI"], en el orden de la semana y sin códigos raros.
     *
     * @return list<string>
     */
    public static function separarDias(?string $dias): array
    {
        $lista = array_filter(array_map('trim', explode(',', (string) $dias)));

        return array_values(array_intersect(array_keys(self::DIAS), $lista));
    }

    /** "L-D", "L-V", "S-D" o letras sueltas ("M,J"): mini semana para la hoja de caseta. */
    public static function patronDias(?string $dias): string
    {
        $lista = self::separarDias($dias);

        return match (true) {
            $lista === array_keys(self::DIAS) => 'L-D',
            $lista === self::LABORALES => 'L-V',
            $lista === self::FIN_DE_SEMANA => 'S-D',
            default => implode(',', array_map(fn ($d) => self::LETRAS[$d], $lista)),
        };
    }

    /** "Todos los días", "Lunes a viernes", "Sábado y domingo" o "Lun, Mié, Vie". */
    public static function textoDias(?string $dias): string
    {
        $lista = self::separarDias($dias);

        return match (true) {
            $lista === [] => 'Sin días',
            $lista === array_keys(self::DIAS) => 'Todos los días',
            $lista === self::LABORALES => 'Lunes a viernes',
            $lista === self::FIN_DE_SEMANA => 'Sábado y domingo',
            count($lista) === 1 => self::DIAS_LARGOS[$lista[0]],
            default => implode(', ', array_map(fn ($d) => self::DIAS[$d], $lista)),
        };
    }

    /** Código del día de una fecha: 1 (lunes) -> "LU". */
    public static function codigoDia(int $diaIso): string
    {
        return array_keys(self::DIAS)[$diaIso - 1];
    }
}
