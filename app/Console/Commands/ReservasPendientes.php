<?php

namespace App\Console\Commands;

use App\Services\Reservas\EsperaReservas;
use Illuminate\Console\Command;

/**
 * Reservas de áreas: aprobaciones que tardan (docs/tecnico/reservas.md). A
 * las horas de «recordar» de la empresa se reenvía el aviso al responsable y
 * a su suplente; a las de «escalar», al responsable general de reservas. Lo
 * llama desplegar.sh en cada corrida del cron: corre en proceso, sin exec ni
 * proc_open. Un recordatorio y un aviso por aprobación (recordatorio_en /
 * escalado_en).
 *   --si-toca  solo informa cuando recordó o escaló algo
 */
class ReservasPendientes extends Command
{
    protected $signature = 'plataforma:reservas-pendientes {--si-toca : Solo informa cuando recordó o escaló algo}';

    protected $description = 'Recuerda y escala las aprobaciones de reservas de áreas que llevan demasiado tiempo esperando';

    public function handle(EsperaReservas $espera): int
    {
        $hecho = $espera->escalar();
        if ($hecho['recordatorios'] + $hecho['escalamientos'] > 0 || ! $this->option('si-toca')) {
            $this->info("Recordatorios enviados: {$hecho['recordatorios']}. Escalamientos: {$hecho['escalamientos']}.");
        }

        return self::SUCCESS;
    }
}
