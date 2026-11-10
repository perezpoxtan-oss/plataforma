<?php

namespace App\Support;

/**
 * Lectura defensiva de parámetros de la petición.
 *
 * Seguridad: un parámetro que se espera como texto puede llegar como arreglo
 * (?q[]=x o q[a][b]=x). Convertirlo con (string) lanza "Array to string
 * conversion" y la pantalla responde con error 500. Aquí, lo que no sea un
 * valor simple se trata como vacío.
 */
final class Entrada
{
    public static function texto(mixed $valor, string $siNoHay = ''): string
    {
        return is_scalar($valor) ? (string) $valor : $siNoHay;
    }
}
