<?php

namespace App\Support;

use App\Models\Empresa;

/**
 * Configuración ordenada por módulo (ADR-0016): a qué módulo pertenece cada
 * aviso de Empresa::AVISOS, para mostrarlos juntos con un interruptor general
 * por módulo («Avisos de Pases de salida: encendidos / apagados»).
 *
 * Un aviso que no esté aquí (p. ej. uno nuevo de otro módulo) cae en
 * «Otros avisos»: sigue funcionando igual y se puede encender o apagar.
 * Los interruptores generales empiezan encendidos (lo de siempre).
 */
final class GruposAvisos
{
    public const OTROS = 'otros';

    /** grupo => [nombre, icono, módulo del catálogo (para «Ir a…»), avisos] */
    public const GRUPOS = [
        'cuentas' => ['Cuentas y seguridad', 'bi-shield-lock', 'usuarios', ['cuenta_bloqueada', 'movimientos_sensibles']],
        'colaboradores' => ['Colaboradores', 'bi-person-badge', 'colaboradores', ['alta_provisional']],
        'padrones' => ['Padrones: altas por verificar', 'bi-patch-question', null, ['alta_por_verificar']],
        'pases_salida' => ['Pases de salida', 'bi-box-arrow-up-right', 'pases_salida', ['pase_salida_aprobacion', 'pase_salida_resultado', 'pase_salida_vencido']],
        'transporte' => ['Bitácora de transporte', 'bi-taxi-front', 'transporte', ['vale_taxi']],
        'vouchers' => ['Vouchers de reposición', 'bi-receipt', 'vouchers', ['voucher_cobro']],
        'procedimientos' => ['Procedimientos', 'bi-book', 'procedimientos', ['procedimiento_publicado', 'procedimiento_recordatorio']],
        'candidatos' => ['Recepción y candidatos', 'bi-people', 'candidatos', ['candidato_llegada', 'autorizacion_departamento', 'entrevista_departamento', 'evaluacion_departamento', 'plazo_candidato']],
    ];

    public static function grupoDe(string $aviso): string
    {
        foreach (self::GRUPOS as $grupo => [, , , $avisos]) {
            if (in_array($aviso, $avisos, true)) {
                return $grupo;
            }
        }

        return self::OTROS;
    }

    /**
     * Avisos de Empresa::AVISOS agrupados por módulo, en el orden de GRUPOS
     * (y «Otros avisos» al final, solo si hay alguno).
     *
     * @return array<string, array{nombre: string, icono: string, modulo: ?string, avisos: array<string, array{0: string, 1: bool}>}>
     */
    public static function agrupados(): array
    {
        $resultado = [];
        foreach (self::GRUPOS as $grupo => [$nombre, $icono, $modulo]) {
            $resultado[$grupo] = ['nombre' => $nombre, 'icono' => $icono, 'modulo' => $modulo, 'avisos' => []];
        }
        $resultado[self::OTROS] = ['nombre' => 'Otros avisos', 'icono' => 'bi-bell', 'modulo' => null, 'avisos' => []];

        foreach (Empresa::AVISOS as $clave => $definicion) {
            $resultado[self::grupoDe($clave)]['avisos'][$clave] = $definicion;
        }

        return array_filter($resultado, fn ($g) => $g['avisos'] !== []);
    }

    /**
     * ¿Está encendido el interruptor general del módulo? (encendido si nunca se tocó)
     */
    public static function encendido(Empresa $empresa, string $grupo): bool
    {
        return (bool) ($empresa->preferencias['avisos_modulos'][$grupo] ?? true);
    }
}
