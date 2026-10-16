<?php

namespace App\Support;

use App\Models\Empresa;
use App\Services\Novedades\AjustesNovedades;

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
        // Mejoras Incidentes (ADR-0013): sus ajustes (nombre del menú, categoría del hallazgo) van en la misma tarjeta
        'novedades' => ['Bitácora de novedades', 'bi-headset', 'novedades', ['novedad_canalizada', 'hallazgo_recorrido_pc']],
        'pases_salida' => ['Pases de salida', 'bi-box-arrow-up-right', 'pases_salida', ['pase_salida_aprobacion', 'pase_salida_resultado', 'pase_salida_vencido', 'pase_salida_espera']],
        'transporte' => ['Bitácora de transporte', 'bi-taxi-front', 'transporte', ['vale_taxi']],
        'vouchers' => ['Vouchers de reposición', 'bi-receipt', 'vouchers', ['voucher_cobro']],
        'procedimientos' => ['Procedimientos', 'bi-book', 'procedimientos', ['procedimiento_publicado', 'procedimiento_recordatorio', 'procedimiento_revision']],
        'candidatos' => ['Recepción y candidatos', 'bi-people', 'candidatos', ['candidato_llegada', 'autorizacion_departamento', 'entrevista_departamento', 'evaluacion_departamento', 'plazo_candidato']],
        // Reservas de áreas (docs/tecnico/reservas.md)
        'reservas' => ['Reservas de áreas', 'bi-calendar-check', 'reservas', ['reserva_aprobacion', 'reserva_resultado', 'reserva_orden']],
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
     * Con $empresaId, el grupo de la bitácora lleva el nombre que esa empresa
     * le dio al módulo («Bitácora de incidentes», etc.).
     *
     * @return array<string, array{nombre: string, icono: string, modulo: ?string, avisos: array<string, array{0: string, 1: bool}>}>
     */
    public static function agrupados(?int $empresaId = null): array
    {
        $resultado = [];
        foreach (self::GRUPOS as $grupo => [$nombre, $icono, $modulo]) {
            $resultado[$grupo] = ['nombre' => $nombre, 'icono' => $icono, 'modulo' => $modulo, 'avisos' => []];
        }
        $resultado[self::OTROS] = ['nombre' => 'Otros avisos', 'icono' => 'bi-bell', 'modulo' => null, 'avisos' => []];

        foreach (Empresa::AVISOS as $clave => $definicion) {
            $resultado[self::grupoDe($clave)]['avisos'][$clave] = $definicion;
        }

        if ($empresaId !== null && ($bitacora = app(AjustesNovedades::class)->bitacora($empresaId)) !== 'Bitácora de novedades') {
            $resultado['novedades']['nombre'] = $bitacora;
            foreach ($resultado['novedades']['avisos'] as $clave => $definicion) {
                $resultado['novedades']['avisos'][$clave][0] = str_replace('Bitácora de novedades', $bitacora, $definicion[0]);
            }
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
