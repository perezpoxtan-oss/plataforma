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
 * o más horarios. hora_inicio, hora_fin y dias son un resumen de los horarios
 * que solo calcula resumenDeHorarios().
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

    // ------------------------------------------------------------ Resumen (un solo valor verdad)

    /**
     * ÚNICO lugar donde se calcula el resumen de la ruta (hora_inicio,
     * hora_fin, dias) a partir de sus horarios: el horario más temprano y la
     * unión de días en el orden de la semana. Lo usan el guardado
     * (AdministradorRutas) y la revisión de coherencia
     * (plataforma:revisar-coherencia). Ver docs/tecnico/integridad-bd.md.
     *
     * @param  iterable<RutaHorario|array<string, mixed>>  $horarios
     * @return array{hora_inicio: ?string, hora_fin: ?string, dias: ?string}
     */
    public static function resumenDeHorarios(iterable $horarios): array
    {
        $lista = collect($horarios)->map(fn ($h) => $h instanceof RutaHorario
            ? ['hora_inicio' => $h->getRawOriginal('hora_inicio') ?? $h->hora_inicio, 'hora_fin' => $h->getRawOriginal('hora_fin') ?? $h->hora_fin, 'dias' => $h->dias]
            : $h)->values();
        if ($lista->isEmpty()) {
            return ['hora_inicio' => null, 'hora_fin' => null, 'dias' => null];
        }
        $primero = $lista->sortBy(fn ($h) => self::hora($h['hora_inicio']))->first();
        $dias = $lista->flatMap(fn ($h) => self::separarDias(is_array($h['dias']) ? implode(',', $h['dias']) : $h['dias']))->unique()->all();

        return [
            'hora_inicio' => self::hora($primero['hora_inicio']),
            'hora_fin' => self::hora($primero['hora_fin']),
            'dias' => implode(',', array_values(array_intersect(array_keys(self::DIAS), $dias))),
        ];
    }

    /** Mismo formato para comparar lo guardado con lo calculado («6:00» = «06:00:00»). */
    public static function normalizarResumen(array $resumen): array
    {
        return [
            'hora_inicio' => self::hora($resumen['hora_inicio'] ?? null),
            'hora_fin' => self::hora($resumen['hora_fin'] ?? null),
            'dias' => ($resumen['dias'] ?? null) === null ? null : implode(',', self::separarDias($resumen['dias'])),
        ];
    }

    /**
     * Vuelve a calcular el resumen desde los horarios guardados y lo guarda si
     * cambió. true = había diferencia.
     */
    public function recalcularResumen(): bool
    {
        $esperado = self::resumenDeHorarios($this->horarios()->get());
        $actual = ['hora_inicio' => $this->getRawOriginal('hora_inicio'), 'hora_fin' => $this->getRawOriginal('hora_fin'), 'dias' => $this->getRawOriginal('dias')];
        if (self::normalizarResumen($actual) === self::normalizarResumen($esperado)) {
            return false;
        }
        $this->forceFill($esperado)->save();

        return true;
    }

    /** «6:00», «06:00» o «06:00:00» → «06:00:00»; vacío → null. */
    private static function hora(mixed $valor): ?string
    {
        $texto = trim((string) $valor);
        if ($texto === '') {
            return null;
        }
        if (! preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?/', $texto, $m)) {
            return $texto;
        }

        return sprintf('%02d:%02d:%02d', (int) $m[1], (int) $m[2], (int) ($m[3] ?? 0));
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
