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
 *    (ámbar y recordatorio a la caseta; rojo y aviso a quien anula). De
 *    fábrica 12 h y 24 h (C-24, DD-10); 0 = sin semáforo.
 *  - responsivas_horas_turno / responsivas_horas_escalar: hora esperada de
 *    regreso de los equipos «Turno» (horas después de la entrega) y horas
 *    extra para escalar. 0 = sin hora esperada (original).
 *  - procedimientos_acuse: cómo se firma «Leí y entendí» (firma = original;
 *    casilla; casilla_criticos = casilla salvo procedimientos con puntos
 *    críticos, que piden firma).
 *  - procedimientos_recordatorio_dias: cada cuántos días como máximo se
 *    recuerda a cada persona los procedimientos sin «Leí y entendí» (3 =
 *    original; antes AdministradorProcedimientos::RECORDATORIO_CADA_DIAS).
 *  - vobo_horas_amarillo / vobo_horas_rojo: reloj de lo que espera un visto
 *    bueno (vales de taxi y procedimientos en revisión): amarillo desde las
 *    24 h y rojo después de las 72 h (originales; antes constantes de
 *    RelojVoBo). Configuración → General.
 *  - motivo_visita_omision: motivo de visita que viene marcado en la
 *    caseta ('' = ninguno, de fábrica; C-04).
 *  - visita_a_huesped: si el hotel ofrece «Visita a huésped» (si, de
 *    fábrica; DD-23). Configuración → Caseta y accesos.
 *  - permanencia_visita_horas / permanencia_proveedor_horas /
 *    daypass_cierre_hora / salida_temporal_horas (C-25, B-05): reloj de
 *    permanencia por tipo (visita 4 h, proveedor y contratista 8 h, daypass
 *    hasta las 22:00 del día) y de la salida temporal (2 h). 0 = apagado.
 *  - agente_excepcion (C-25, DD-13): el agente puede aprobar por excepción
 *    si nadie responde, con motivo y foto (no, de fábrica).
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

    /**
     * Motivo de visita que viene marcado en la caseta (C-04, DD-23): de
     * fábrica ninguno, para que el guardia elija siempre. Si el motivo
     * elegido no aplica a la empresa (por ejemplo, Recursos Humanos sin el
     * módulo Candidatos), no se marca ninguno.
     */
    public const MOTIVOS_OMISION = [
        '' => 'Ninguno: la caseta elige el motivo cada vez (recomendado)',
        'general' => 'Visita general',
        'huesped' => 'Visita a huésped',
        'colaborador' => 'Visita a colaborador',
        'departamento' => 'Visita a departamento',
        'rh' => 'Recursos Humanos',
    ];

    /** «Visita a huésped» (hotel): se ofrece de fábrica y se puede apagar (DD-23). */
    public const VISITA_A_HUESPED = [
        'si' => 'Sí, ofrecer «Visita a huésped» en la caseta',
        'no' => 'No, no la ofrezcas',
    ];

    /** C-25 (DD-13): ¿el agente puede aprobar por excepción de noche? */
    public const AGENTE_EXCEPCION = [
        'no' => 'No: solo el supervisor o el jefe de seguridad autorizan por excepción',
        'si' => 'Sí: si nadie responde a tiempo, el agente puede aprobar con motivo y foto (se avisa de inmediato)',
    ];

    /** Opciones de los ajustes de texto. */
    private const OPCIONES = [
        'agente_excepcion' => self::AGENTE_EXCEPCION,
        'transporte_firmas' => self::FIRMAS_TRANSPORTE,
        'procedimientos_acuse' => self::ACUSES,
        'motivo_visita_omision' => self::MOTIVOS_OMISION,
        'visita_a_huesped' => self::VISITA_A_HUESPED,
    ];

    /** Valores por omisión: el comportamiento original. */
    public const OMISION = [
        'transporte_firmas' => 'ambas',
        // C-24 (DD-10): semáforo de llaves encendido de fábrica, 12 h y 24 h (0 = apagado)
        'llaves_horas_aviso' => 12,
        'llaves_horas_escalar' => 24,
        'responsivas_horas_turno' => 0,
        'responsivas_horas_escalar' => 0,
        'procedimientos_acuse' => 'firma',
        'procedimientos_recordatorio_dias' => 3,
        'vobo_horas_amarillo' => 24,
        'vobo_horas_rojo' => 72,
        'motivo_visita_omision' => '',
        'visita_a_huesped' => 'si',
        'permanencia_visita_horas' => 4,
        'permanencia_proveedor_horas' => 8,
        'daypass_cierre_hora' => 22,
        'salida_temporal_horas' => 2,
        'agente_excepcion' => 'no',
    ];

    /** Límites de los números (para validar y para el formulario). */
    public const MAX_HORAS = [
        'llaves_horas_aviso' => 72,
        'llaves_horas_escalar' => 168,
        'responsivas_horas_turno' => 24,
        'responsivas_horas_escalar' => 48,
        'procedimientos_recordatorio_dias' => 30,
        'vobo_horas_amarillo' => 168,
        'vobo_horas_rojo' => 720,
        'permanencia_visita_horas' => 72,
        'permanencia_proveedor_horas' => 72,
        'daypass_cierre_hora' => 23,
        'salida_temporal_horas' => 24,
    ];

    /** Mínimos de los números que no se pueden apagar (los demás admiten 0 = apagado). */
    public const MIN = [
        'procedimientos_recordatorio_dias' => 1,
        'vobo_horas_amarillo' => 1,
        'vobo_horas_rojo' => 2,
    ];

    /**
     * Grupos de ajustes por tarjeta de Configuración: cada formulario manda
     * solo los suyos y «Restablecer valor original» regresa solo esos.
     */
    public const GRUPOS = [
        'transporte' => ['transporte_firmas'],
        'llaves' => ['llaves_horas_aviso', 'llaves_horas_escalar'],
        'responsivas' => ['responsivas_horas_turno', 'responsivas_horas_escalar'],
        'procedimientos' => ['procedimientos_acuse', 'procedimientos_recordatorio_dias'],
        'vobo' => ['vobo_horas_amarillo', 'vobo_horas_rojo'],
        'motivos' => ['motivo_visita_omision', 'visita_a_huesped'],
        'permanencia' => ['permanencia_visita_horas', 'permanencia_proveedor_horas', 'daypass_cierre_hora', 'salida_temporal_horas'],
        'noche' => ['agente_excepcion'],
    ];

    /**
     * Ajustes vigentes de una empresa (sin empresa: los originales).
     *
     * @return array{transporte_firmas: string, llaves_horas_aviso: int, llaves_horas_escalar: int, responsivas_horas_turno: int, responsivas_horas_escalar: int, procedimientos_acuse: string, procedimientos_recordatorio_dias: int, vobo_horas_amarillo: int, vobo_horas_rojo: int, motivo_visita_omision: string, visita_a_huesped: string, permanencia_visita_horas: int, permanencia_proveedor_horas: int, daypass_cierre_hora: int, salida_temporal_horas: int, agente_excepcion: string}
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
                $ajustes[$clave] = is_numeric($valor) ? max(self::MIN[$clave] ?? 0, min(self::MAX_HORAS[$clave], (int) $valor)) : $omision;
            } else {
                $opciones = self::OPCIONES[$clave];
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
                $minimo = self::MIN[$clave] ?? 0;
                if (! is_numeric($valor) || (int) $valor != $valor || (int) $valor < $minimo || (int) $valor > self::MAX_HORAS[$clave]) {
                    $errores[$clave] = match ($clave) {
                        'procedimientos_recordatorio_dias' => 'Escribe un número entero de días, de 1 a '.self::MAX_HORAS[$clave].'.',
                        'vobo_horas_amarillo', 'vobo_horas_rojo' => 'Escribe un número entero de horas, de '.$minimo.' a '.self::MAX_HORAS[$clave].'.',
                        'daypass_cierre_hora' => 'Escribe la hora de cierre del 1 al 23 (0 = apagado).',
                        default => 'Escribe un número entero de horas, de 0 a '.self::MAX_HORAS[$clave].' (0 = apagado).',
                    };

                    continue;
                }
                $limpio[$clave] = (int) $valor;
            } else {
                $opciones = self::OPCIONES[$clave];
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
        if (! isset($errores['vobo_horas_amarillo'], $errores['vobo_horas_rojo'])
            && isset($limpio['vobo_horas_amarillo'], $limpio['vobo_horas_rojo'])
            && $limpio['vobo_horas_rojo'] <= $limpio['vobo_horas_amarillo']) {
            $errores['vobo_horas_rojo'] = 'Las horas para el rojo deben ser más que las del amarillo.';
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
