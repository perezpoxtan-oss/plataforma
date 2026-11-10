<?php

namespace App\Services\Informes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Consultas agregadas de los informes (ADR-0017): todo se cuenta en la base
 * (COUNT, SUM, GROUP BY y SUM(CASE…) por intervalos de fecha), sin cargar
 * registros en memoria y sin funciones de fecha propias de MariaDB o SQLite.
 */
final class Indicadores
{
    /**
     * Totales por cubeta de tiempo en una sola consulta.
     *
     * @param  Builder<Model>  $consulta
     * @param  list<array{0: string, 1: string, 2: string}>  $cubetas
     * @param  string|null  $suma  columna a sumar (null = contar registros)
     * @return list<float|int>
     */
    public static function serie(Builder $consulta, string $columnaFecha, array $cubetas, ?string $suma = null): array
    {
        if ($cubetas === []) {
            return [];
        }
        $partes = [];
        $valores = [];
        foreach ($cubetas as $i => [, $inicio, $fin]) {
            $partes[] = 'SUM(CASE WHEN '.$columnaFecha.' >= ? AND '.$columnaFecha.' < ? THEN '.($suma ?? '1').' ELSE 0 END) AS c'.$i;
            array_push($valores, $inicio, $fin);
        }
        $fila = (array) ((clone $consulta)->toBase()->selectRaw(implode(', ', $partes), $valores)->first() ?? []);

        return array_map(fn ($i) => $suma === null ? (int) ($fila['c'.$i] ?? 0) : round((float) ($fila['c'.$i] ?? 0), 2), array_keys($cubetas));
    }

    /**
     * Conteo agrupado por una columna: [etiqueta => total], de mayor a menor.
     *
     * @param  Builder<Model>  $consulta
     * @param  array<string, string>  $etiquetas  valor guardado => texto en pantalla
     * @return array<string, int>
     */
    public static function por(Builder $consulta, string $columna, array $etiquetas = [], ?int $limite = null, string $sinDato = 'Sin especificar'): array
    {
        $filas = (clone $consulta)->toBase()
            ->selectRaw($columna.' as clave, COUNT(*) as total')
            ->groupBy($columna)
            ->orderByDesc('total')
            ->when($limite !== null, fn ($q) => $q->limit($limite))
            ->get();

        $resultado = [];
        foreach ($filas as $f) {
            $clave = $f->clave === null || $f->clave === '' ? $sinDato : (string) $f->clave;
            $texto = $etiquetas[$clave] ?? $clave;
            $resultado[$texto] = ($resultado[$texto] ?? 0) + (int) $f->total;
        }
        arsort($resultado);

        return $resultado;
    }

    /**
     * Varios conteos condicionales en una sola consulta: [nombre => SQL de la condición].
     *
     * @param  Builder<Model>  $consulta
     * @param  array<string, string>  $condiciones
     * @return array<string, int>
     */
    public static function conteos(Builder $consulta, array $condiciones): array
    {
        $partes = [];
        foreach (array_values($condiciones) as $i => $sql) {
            $partes[] = 'SUM(CASE WHEN '.$sql.' THEN 1 ELSE 0 END) AS k'.$i;
        }
        $fila = (array) ((clone $consulta)->toBase()->selectRaw(implode(', ', $partes))->first() ?? []);
        $resultado = [];
        foreach (array_keys($condiciones) as $i => $nombre) {
            $resultado[$nombre] = (int) ($fila['k'.$i] ?? 0);
        }

        return $resultado;
    }

    /** Cambio porcentual contra el periodo anterior (null si antes no hubo nada). */
    public static function cambio(float|int $actual, float|int $anterior): ?float
    {
        if ((float) $anterior === 0.0) {
            return null;
        }

        return round((($actual - $anterior) / $anterior) * 100, 1);
    }

    /**
     * Cifra con su comparación: valor, anterior, cambio y dirección.
     *
     * @return array{valor: float|int, anterior: float|int, cambio: ?float, direccion: string}
     */
    public static function comparar(float|int $actual, float|int $anterior): array
    {
        return [
            'valor' => $actual,
            'anterior' => $anterior,
            'cambio' => self::cambio($actual, $anterior),
            'direccion' => $actual > $anterior ? 'sube' : ($actual < $anterior ? 'baja' : 'igual'),
        ];
    }

    /** «Subir es bueno» (p. ej. contratados), «subir es malo» (p. ej. incidentes) o neutro (volumen de operación). */
    public const SUBIR_ES_BUENO = 'bueno';

    public const SUBIR_ES_MALO = 'malo';

    public const NEUTRO = 'neutro';

    /**
     * Comparación con su lectura (decisión del dueño, mapa de procesos): si el
     * indicador sabe qué es mejor, la variación se lee como «mejoró 12 %» o
     * «empeoró 8 %» (estado mejora/empeora → verde/rojo); si es neutro, como
     * «subió» o «bajó» sin color. La flecha siempre dice hacia dónde se movió
     * la cifra; el color y el texto, si eso es bueno o malo.
     *
     * @return array{valor: float|int, anterior: float|int, cambio: ?float, direccion: string, estado: string, texto: string}
     */
    public static function valorar(float|int $actual, float|int $anterior, ?string $sentido): array
    {
        $c = self::comparar($actual, $anterior);
        $sentido = in_array($sentido, [self::SUBIR_ES_BUENO, self::SUBIR_ES_MALO], true) ? $sentido : self::NEUTRO;

        if ($c['direccion'] === 'igual') {
            return $c + ['estado' => 'igual', 'texto' => 'Sin cambio'];
        }
        $estado = $sentido === self::NEUTRO ? 'neutro'
            : (($c['direccion'] === 'sube') === ($sentido === self::SUBIR_ES_BUENO) ? 'mejora' : 'empeora');
        $verbo = ['mejora' => 'mejoró', 'empeora' => 'empeoró', 'neutro' => $c['direccion'] === 'sube' ? 'subió' : 'bajó'][$estado];
        // Antes no hubo nada: no hay porcentaje que calcular
        $porcentaje = $c['cambio'] === null ? '' : ' '.self::porcentaje(abs($c['cambio']));

        return $c + ['estado' => $estado, 'texto' => $verbo.$porcentaje];
    }

    /** 12 → «12 %», 12.5 → «12.5 %». */
    public static function porcentaje(float $valor): string
    {
        return (floor($valor) === $valor ? number_format($valor) : number_format($valor, 1)).' %';
    }

    /** Recorta un texto libre para listados e impresiones. */
    public static function corto(?string $texto, int $largo = 90): string
    {
        $texto = trim(preg_replace('/\s+/u', ' ', (string) $texto));

        return mb_strlen($texto) > $largo ? mb_substr($texto, 0, $largo - 1).'…' : $texto;
    }
}
