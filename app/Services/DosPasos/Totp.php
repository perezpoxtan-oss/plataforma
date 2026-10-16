<?php

namespace App\Services\DosPasos;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Contraseñas de un solo uso basadas en el tiempo (TOTP, RFC 6238) sobre
 * HOTP (RFC 4226) con HMAC-SHA1, pasos de 30 segundos y 6 dígitos: lo que
 * usan Google Authenticator, Microsoft Authenticator y compatibles.
 *
 * Escrita aquí (sin dependencias nuevas). La prueba DosPasosTest la revisa
 * con los vectores del apéndice B del RFC 6238.
 */
final class Totp
{
    public const PERIODO = 30;

    public const DIGITOS = 6;

    /** Pasos de tolerancia hacia atrás y hacia adelante (relojes un poco desfasados). */
    public const VENTANA = 1;

    private const ALFABETO = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Clave nueva: 20 bytes al azar (160 bits, lo que recomienda el RFC 4226) en base32. */
    public static function nuevoSecreto(): string
    {
        return self::base32(random_bytes(20));
    }

    public static function base32(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $salida = '';
        foreach (str_split($bits, 5) as $grupo) {
            $salida .= self::ALFABETO[bindec(str_pad($grupo, 5, '0'))];
        }

        return $salida;
    }

    public static function deBase32(string $texto): string
    {
        $texto = strtoupper(preg_replace('/[\s=-]/', '', $texto) ?? '');
        $bits = '';
        foreach (str_split($texto) as $c) {
            $valor = strpos(self::ALFABETO, $c);
            if ($valor === false) {
                throw new InvalidArgumentException('Clave en base32 no válida.');
            }
            $bits .= str_pad(decbin($valor), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $bytes .= chr(bindec($byte));
            }
        }

        return $bytes;
    }

    /** Paso de tiempo de un momento (segundos Unix). */
    public static function paso(?int $momento = null): int
    {
        // now() y no time(): las pruebas viajan en el tiempo (y la hora es la del servidor, en UTC)
        return intdiv($momento ?? Carbon::now()->getTimestamp(), self::PERIODO);
    }

    /** Código HOTP de una clave (bytes, no base32) para un contador. */
    public static function hotp(string $clave, int $contador, int $digitos = self::DIGITOS): string
    {
        $mensaje = pack('N2', ($contador >> 32) & 0xFFFFFFFF, $contador & 0xFFFFFFFF);
        $hmac = hash_hmac('sha1', $mensaje, $clave, true);
        $desplazamiento = ord($hmac[19]) & 0x0F;
        $numero = ((ord($hmac[$desplazamiento]) & 0x7F) << 24)
            | ((ord($hmac[$desplazamiento + 1]) & 0xFF) << 16)
            | ((ord($hmac[$desplazamiento + 2]) & 0xFF) << 8)
            | (ord($hmac[$desplazamiento + 3]) & 0xFF);

        return str_pad((string) ($numero % (10 ** $digitos)), $digitos, '0', STR_PAD_LEFT);
    }

    /** Código vigente de una clave en base32. */
    public static function codigo(string $secretoBase32, ?int $momento = null, int $digitos = self::DIGITOS): string
    {
        return self::hotp(self::deBase32($secretoBase32), self::paso($momento), $digitos);
    }

    /**
     * Revisa un código con la ventana de ±1 paso. Devuelve el paso que
     * coincidió, o null. Un paso igual o anterior a $ultimoPaso se rechaza:
     * el mismo código (o uno más viejo) no sirve dos veces.
     */
    public static function verificar(string $secretoBase32, string $codigo, ?int $ultimoPaso = null, ?int $momento = null): ?int
    {
        $codigo = preg_replace('/\s+/', '', $codigo) ?? '';
        if (! preg_match('/^\d{'.self::DIGITOS.'}$/', $codigo)) {
            return null;
        }
        try {
            $clave = self::deBase32($secretoBase32);
        } catch (InvalidArgumentException) {
            return null;
        }
        $actual = self::paso($momento);
        for ($d = -self::VENTANA; $d <= self::VENTANA; $d++) {
            $paso = $actual + $d;
            if ($ultimoPaso !== null && $paso <= $ultimoPaso) {
                continue;
            }
            if (hash_equals(self::hotp($clave, $paso), $codigo)) {
                return $paso;
            }
        }

        return null;
    }

    /** Dirección otpauth:// que leen las apps (la que va dentro del código QR). */
    public static function uri(string $secretoBase32, string $cuenta, string $emisor): string
    {
        $emisor = trim(str_replace(':', '', $emisor)) ?: 'Plataforma';
        $etiqueta = rawurlencode($emisor).':'.rawurlencode(str_replace(':', '', $cuenta));

        return 'otpauth://totp/'.$etiqueta.'?'.http_build_query([
            'secret' => $secretoBase32, 'issuer' => $emisor, 'algorithm' => 'SHA1', 'digits' => self::DIGITOS, 'period' => self::PERIODO,
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
