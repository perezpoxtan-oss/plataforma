<?php

namespace App\Support;

use App\Models\Empresa;
use Illuminate\Validation\ValidationException;

/**
 * Ajustes de caseta y activos por empresa (ADR-0014), en
 * empresas.preferencias['caseta']. Decisión del dueño: lo que una empresa
 * puede querer distinto es configurable, pero **sin configurar todo se
 * comporta como el diseño original**. Solo se guarda lo distinto de la
 * omisión.
 *
 *  - transporte_firmas: qué firmas se capturan en pantalla en los vales de
 *    taxi (ambas = original; guardia; taxista; ninguna). Lo que no se firma en
 *    pantalla se firma a mano en el vale impreso.
 *  - llaves_horas_aviso / llaves_horas_escalar: semáforo de llaves fuera
 *    (ámbar y recordatorio a la caseta; rojo y aviso a quien anula). 0 = sin
 *    semáforo (original).
 *  - responsivas_horas_turno / responsivas_horas_escalar: hora esperada de
 *    regreso de los equipos «Turno» (horas después de la entrega) y horas
 *    extra para escalar. 0 = sin hora esperada (original).
 *  - procedimientos_acuse: cómo se firma «Leí y entendí» (firma = original;
 *    casilla; casilla_criticos = casilla salvo procedimientos con puntos
 *    críticos, que piden firma).
 */
class AjustesCaseta
{
    public const CLAVE = 'caseta';

    public const FIRMAS_TRANSPORTE = [
        'ambas' => 'Guardia y taxista firman en pantalla (original)',
        'guardia' => 'Solo el guardia firma en pantalla; el taxista firma a mano en el vale impreso',
        'taxista' => 'Solo el taxista firma en pantalla; el guardia firma a mano en el vale impreso',
        'ninguna' => 'Nadie firma en pantalla; todos firman a mano en el vale impreso',
    ];

    public const ACUSES = [
        'firma' => 'Casilla «Leí y entendí» y firma dibujada en cada versión (original)',
        'casilla' => 'Solo la casilla «Leí y entendí» (queda tu nombre, la fecha y la hora)',
        'casilla_criticos' => 'Solo la casilla, salvo procedimientos con puntos críticos, que piden firma',
    ];

    /** Valores por omisión: el comportamiento original. */
    public const OMISION = [
        'transporte_firmas' => 'ambas',
        'llaves_horas_aviso' => 0,
        'llaves_horas_escalar' => 0,
        'responsivas_horas_turno' => 0,
        'responsivas_horas_escalar' => 0,
        'procedimientos_acuse' => 'firma',
    ];

    /** Límites de las horas (para validar y para el formulario). */
    public const MAX_HORAS = [
        'llaves_horas_aviso' => 72,
        'llaves_horas_escalar' => 168,
        'responsivas_horas_turno' => 24,
        'responsivas_horas_escalar' => 48,
    ];

    /**
     * Ajustes vigentes de una empresa (sin empresa: los originales).
     *
     * @return array{transporte_firmas: string, llaves_horas_aviso: int, llaves_horas_escalar: int, responsivas_horas_turno: int, responsivas_horas_escalar: int, procedimientos_acuse: string}
     */
    public static function de(Empresa|int|null $empresa): array
    {
        if (is_int($empresa)) {
            $empresa = Empresa::find($empresa);
        }
        $guardado = $empresa?->preferencias[self::CLAVE] ?? [];
        $ajustes = self::OMISION;
        foreach (self::OMISION as $clave => $omision) {
            $valor = is_array($guardado) ? ($guardado[$clave] ?? null) : null;
            if (is_int($omision)) {
                $ajustes[$clave] = is_numeric($valor) ? max(0, min(self::MAX_HORAS[$clave], (int) $valor)) : $omision;
            } else {
                $opciones = $clave === 'transporte_firmas' ? self::FIRMAS_TRANSPORTE : self::ACUSES;
                $ajustes[$clave] = is_string($valor) && isset($opciones[$valor]) ? $valor : $omision;
            }
        }

        return $ajustes;
    }

    public static function valor(Empresa|int|null $empresa, string $clave): int|string
    {
        return self::de($empresa)[$clave];
    }

    /** ¿Se firma en pantalla? $quien = guardia | taxista. */
    public static function firmaEnPantalla(Empresa|int|null $empresa, string $quien): bool
    {
        $modo = self::valor($empresa, 'transporte_firmas');

        return $modo === 'ambas' || $modo === $quien;
    }

    /**
     * Valida lo que llega del formulario de Configuración y devuelve solo lo
     * distinto de la omisión (lo que se guarda).
     *
     * @param  array<string, mixed>  $entrada
     * @return array<string, int|string>
     */
    public static function validar(array $entrada): array
    {
        $errores = [];
        $limpio = [];
        foreach (self::OMISION as $clave => $omision) {
            $valor = $entrada[$clave] ?? $omision;
            if (is_int($omision)) {
                if ($valor === '' || $valor === null) {
                    $valor = 0;
                }
                if (! is_numeric($valor) || (int) $valor != $valor || (int) $valor < 0 || (int) $valor > self::MAX_HORAS[$clave]) {
                    $errores[$clave] = 'Escribe un número entero de horas, de 0 a '.self::MAX_HORAS[$clave].' (0 = apagado).';

                    continue;
                }
                $limpio[$clave] = (int) $valor;
            } else {
                $opciones = $clave === 'transporte_firmas' ? self::FIRMAS_TRANSPORTE : self::ACUSES;
                if (! is_string($valor) || ! isset($opciones[$valor])) {
                    $errores[$clave] = 'Elige una de las opciones de la lista.';

                    continue;
                }
                $limpio[$clave] = $valor;
            }
        }
        if (! isset($errores['llaves_horas_escalar'], $errores['llaves_horas_aviso'])
            && ($limpio['llaves_horas_escalar'] ?? 0) > 0 && ($limpio['llaves_horas_aviso'] ?? 0) > 0
            && $limpio['llaves_horas_escalar'] <= $limpio['llaves_horas_aviso']) {
            $errores['llaves_horas_escalar'] = 'Las horas para avisar al jefe deben ser más que las del recordatorio a la caseta.';
        }
        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        return array_filter($limpio, fn ($v, $k) => $v !== self::OMISION[$k], ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Semáforo por horas: null (sin semáforo o en tiempo), 'ambar' o 'rojo'.
     * Con solo «escalar» configurado, pasa directo a rojo.
     */
    public static function nivel(float $horas, int $aviso, int $escalar): ?string
    {
        if ($escalar > 0 && $horas >= $escalar) {
            return 'rojo';
        }
        if ($aviso > 0 && $horas >= $aviso) {
            return 'ambar';
        }

        return null;
    }
}
