<?php

namespace App\Support\Lector;

/**
 * Lo que entrega un lector (QR, NFC, RFID, código de barras) convertido a algo
 * que se pueda buscar.
 *
 * Un mismo chip puede llegar escrito de varias formas según el aparato:
 *   - Web NFC (Android) da el número de serie en hexadecimal con dos puntos:
 *     "04:a2:3b:1c:5d:80:00".
 *   - Los lectores USB/Bluetooth que "escriben como teclado" suelen mandar el
 *     mismo número en decimal ("0012345678"), en hexadecimal ("1C3BA204") y a
 *     veces con los bytes al revés, según cómo vengan configurados.
 *   - Un QR o una etiqueta NFC con texto trae la dirección de la plataforma
 *     ("https://…/e/abc123…") o directamente el código.
 *
 * Por eso al registrar una etiqueta se guarda normalizada y al buscar se
 * prueban todas sus formas equivalentes (candidatos()).
 */
final class Etiqueta
{
    /** Longitud máxima que se acepta de un lector (evita basura pegada). */
    public const MAXIMO = 200;

    /**
     * Mayúsculas y solo letras y números: "04:a2:3b" -> "04A23B".
     */
    public static function normalizar(?string $valor): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', mb_substr(trim((string) $valor), 0, self::MAXIMO)));
    }

    /**
     * Si lo leído es una dirección de la plataforma (QR o etiqueta NFC con
     * URL), devuelve el código que trae al final (/e/{codigo} o
     * /vehiculos/qr/{codigo}); si no, null.
     */
    public static function codigoDeUrl(string $valor): ?string
    {
        $valor = trim($valor);
        if (preg_match('#/(?:e|qr)/([A-Za-z0-9]{8,32})/?(?:[?\#].*)?$#', $valor, $m)) {
            return strtolower($m[1]);
        }

        return null;
    }

    /**
     * Todas las formas en que ese mismo chip pudo haberse registrado.
     *
     * @return list<string>
     */
    public static function candidatos(?string $valor): array
    {
        $base = self::normalizar($valor);
        if ($base === '') {
            return [];
        }
        $lista = [$base];

        if (ctype_digit($base) && strlen($base) <= 19) {
            // Decimal -> hexadecimal (normal y con los bytes al revés)
            $numero = (int) ltrim($base, '0');
            if ($numero > 0 && (string) $numero === ltrim($base, '0')) {
                $hex = strtoupper(dechex($numero));
                $hex = str_pad($hex, max(8, strlen($hex) + strlen($hex) % 2), '0', STR_PAD_LEFT);
                $lista[] = $hex;
                $lista[] = self::invertirBytes($hex);
                $lista[] = ltrim($base, '0');
            }
        } elseif (ctype_xdigit($base) && strlen($base) % 2 === 0 && strlen($base) >= 8 && strlen($base) <= 14) {
            // Hexadecimal -> decimal (10 dígitos, como lo imprimen las tarjetas de 125 kHz)
            foreach ([$base, self::invertirBytes($base)] as $hex) {
                $lista[] = $hex;
                $decimal = (string) hexdec($hex);
                $lista[] = $decimal;
                $lista[] = str_pad($decimal, 10, '0', STR_PAD_LEFT);
            }
        }

        return array_values(array_unique($lista));
    }

    private static function invertirBytes(string $hex): string
    {
        return implode('', array_reverse(str_split($hex, 2)));
    }
}
