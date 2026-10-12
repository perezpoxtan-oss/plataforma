<?php

namespace App\Support;

/**
 * Gráficas sencillas dibujadas en el servidor como SVG (ADR-0017): barras
 * horizontales para categorías, columnas para el tiempo y líneas para
 * comparar contra el periodo anterior. Sin JavaScript ni librerías externas
 * (la política de seguridad solo permite scripts propios). Cada barra o
 * punto lleva su texto emergente (<title>) y cada gráfica su tabla de datos
 * para lectores de pantalla y para quien prefiere los números.
 */
final class GraficaSvg
{
    private const ANCHO = 640;

    /**
     * Barras horizontales: [etiqueta => valor].
     *
     * @param  array<string, float|int>  $datos
     */
    public static function barras(array $datos, string $titulo, string $unidad = '', int $maximo = 10): string
    {
        if ($datos === []) {
            return self::vacia($titulo);
        }
        $datos = self::recortar($datos, $maximo);
        $alto = 30;
        $etiquetas = 190;
        $tope = max(1, max(array_map('floatval', $datos)));
        $util = self::ANCHO - $etiquetas - 60;
        $svg = '';
        $i = 0;
        foreach ($datos as $etiqueta => $valor) {
            $y = $i * $alto;
            $w = max(2, round(((float) $valor / $tope) * $util, 1));
            $texto = self::numero($valor, $unidad);
            $svg .= '<g class="grafica-fila"><title>'.e($etiqueta.': '.$texto).'</title>'
                .'<text x="'.($etiquetas - 8).'" y="'.($y + 19).'" text-anchor="end" class="grafica-etiqueta">'.e(self::corto((string) $etiqueta, 26)).'</text>'
                .'<rect x="'.$etiquetas.'" y="'.($y + 6).'" width="'.$w.'" height="'.($alto - 12).'" rx="4" class="grafica-barra"></rect>'
                .'<text x="'.($etiquetas + $w + 6).'" y="'.($y + 19).'" class="grafica-valor">'.e($texto).'</text></g>';
            $i++;
        }
        $total = count($datos) * $alto;

        return self::envolver($svg, $total, $titulo, ['Concepto', 'Total'], array_map(fn ($k, $v) => [$k, self::numero($v, $unidad)], array_keys($datos), $datos));
    }

    /**
     * Columnas (una serie en el tiempo) o líneas (actual contra anterior).
     *
     * @param  list<string>  $etiquetas
     * @param  list<float|int>  $actual
     * @param  list<float|int>|null  $anterior  null = solo columnas
     */
    public static function tiempo(array $etiquetas, array $actual, ?array $anterior, string $titulo, string $unidad = ''): string
    {
        if ($etiquetas === []) {
            return self::vacia($titulo);
        }
        $alto = 190;
        $izq = 44;
        $abajo = 26;
        $util = self::ANCHO - $izq - 10;
        $tope = max(1, max(array_map('floatval', array_merge($actual, $anterior ?? []))));
        $tope = self::redondear($tope);
        $n = count($etiquetas);
        $paso = $util / $n;
        $y = fn ($v) => round(($alto - $abajo) - ((float) $v / $tope) * ($alto - $abajo - 10), 1);
        $x = fn (int $i) => round($izq + $paso * $i + $paso / 2, 1);

        // Rejilla discreta: 0, mitad y tope
        $svg = '';
        foreach ([0, $tope / 2, $tope] as $marca) {
            $svg .= '<line x1="'.$izq.'" x2="'.(self::ANCHO - 10).'" y1="'.$y($marca).'" y2="'.$y($marca).'" class="grafica-rejilla"></line>'
                .'<text x="'.($izq - 6).'" y="'.($y($marca) + 4).'" text-anchor="end" class="grafica-eje">'.e(self::numero($marca, '')).'</text>';
        }
        // Etiquetas del eje: como máximo ~12 para que no se encimen
        $cada = max(1, (int) ceil($n / 12));
        foreach ($etiquetas as $i => $etiqueta) {
            if ($i % $cada === 0) {
                $svg .= '<text x="'.$x($i).'" y="'.($alto - 8).'" text-anchor="middle" class="grafica-eje">'.e($etiqueta).'</text>';
            }
        }

        if ($anterior === null) {
            $ancho = max(3, min(28, $paso - 4));
            foreach ($actual as $i => $v) {
                $svg .= '<g><title>'.e($etiquetas[$i].': '.self::numero($v, $unidad)).'</title>'
                    .'<rect x="'.round($x($i) - $ancho / 2, 1).'" y="'.$y($v).'" width="'.$ancho.'" height="'.max(0, round(($alto - $abajo) - $y($v), 1)).'" rx="3" class="grafica-barra"></rect></g>';
            }
            $filas = array_map(fn ($e, $v) => [$e, self::numero($v, $unidad)], $etiquetas, $actual);

            return self::envolver($svg, $alto, $titulo, ['Periodo', 'Total'], $filas);
        }

        $linea = function (array $serie, string $clase) use ($x, $y) {
            $puntos = implode(' ', array_map(fn ($v, $i) => $x($i).','.$y($v), $serie, array_keys($serie)));

            return '<polyline points="'.$puntos.'" class="'.$clase.'" fill="none"></polyline>';
        };
        $svg .= $linea($anterior, 'grafica-linea-anterior').$linea($actual, 'grafica-linea-actual');
        foreach ($actual as $i => $v) {
            $svg .= '<g><title>'.e($etiquetas[$i].': '.self::numero($v, $unidad).' (antes: '.self::numero($anterior[$i] ?? 0, $unidad).')').'</title>'
                .'<circle cx="'.$x($i).'" cy="'.$y($v).'" r="4" class="grafica-punto"></circle>'
                .'<rect x="'.round($x($i) - $paso / 2, 1).'" y="0" width="'.round($paso, 1).'" height="'.($alto - $abajo).'" class="grafica-zona"></rect></g>';
        }
        $leyenda = '<div class="grafica-leyenda"><span class="actual">Este periodo</span><span class="anterior">Periodo anterior</span></div>';
        $filas = array_map(fn ($e, $v, $a) => [$e, self::numero($v, $unidad), self::numero($a, $unidad)], $etiquetas, $actual, array_pad($anterior, $n, 0));

        return $leyenda.self::envolver($svg, $alto, $titulo, ['Periodo', 'Este periodo', 'Periodo anterior'], $filas);
    }

    /**
     * @param  list<string>  $columnas
     * @param  list<list<string|int|float>>  $filas
     */
    private static function envolver(string $svg, float|int $alto, string $titulo, array $columnas, array $filas): string
    {
        $tabla = '<details class="grafica-datos"><summary>Ver los datos de la gráfica</summary><div class="tabla-scroll"><table class="table table-sm mb-0"><caption class="visually-hidden">'.e($titulo).'</caption><thead><tr>';
        foreach ($columnas as $c) {
            $tabla .= '<th scope="col">'.e($c).'</th>';
        }
        $tabla .= '</tr></thead><tbody>';
        foreach ($filas as $fila) {
            $tabla .= '<tr>'.implode('', array_map(fn ($v) => '<td>'.e((string) $v).'</td>', $fila)).'</tr>';
        }
        $tabla .= '</tbody></table></div></details>';

        return '<figure class="grafica"><svg viewBox="0 0 '.self::ANCHO.' '.$alto.'" role="img" aria-label="'.e($titulo).'" preserveAspectRatio="xMinYMin meet">'
            .$svg.'</svg>'.$tabla.'</figure>';
    }

    private static function vacia(string $titulo): string
    {
        return '<figure class="grafica grafica-vacia" aria-label="'.e($titulo).'"><p class="text-muted small m-0"><i class="bi bi-bar-chart" aria-hidden="true"></i> Sin datos para graficar.</p></figure>';
    }

    /**
     * Los primeros N y el resto como «Otros» (nunca colores ni barras de más).
     *
     * @param  array<string, float|int>  $datos
     * @return array<string, float|int>
     */
    private static function recortar(array $datos, int $maximo): array
    {
        if (count($datos) <= $maximo) {
            return $datos;
        }
        $primeros = array_slice($datos, 0, $maximo - 1, true);
        $primeros['Otros'] = ($primeros['Otros'] ?? 0) + array_sum(array_slice($datos, $maximo - 1));

        return $primeros;
    }

    public static function numero(float|int|null $valor, string $unidad = ''): string
    {
        $valor = (float) $valor;
        $texto = $unidad === '$' ? '$'.number_format($valor, 2) : (floor($valor) === $valor ? number_format($valor) : number_format($valor, 1));

        return $unidad === '$' || $unidad === '' ? $texto : $texto.' '.$unidad;
    }

    private static function redondear(float $tope): float
    {
        $base = 10 ** floor(log10($tope));
        foreach ([1, 2, 2.5, 5, 10] as $m) {
            if ($tope <= $m * $base) {
                return max(1, $m * $base);
            }
        }

        return $tope;
    }

    private static function corto(string $texto, int $largo): string
    {
        return mb_strlen($texto) > $largo ? mb_substr($texto, 0, $largo - 1).'…' : $texto;
    }
}
