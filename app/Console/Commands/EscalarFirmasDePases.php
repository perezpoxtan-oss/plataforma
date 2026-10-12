<?php

namespace App\Console\Commands;

use App\Services\PasesSalida\EsperaPasesSalida;
use Illuminate\Console\Command;

/**
 * Firmas de pases de salida que tardan (ADR-0013): a las horas que la empresa
 * configura en el Circuito se recuerda al firmante y a las siguientes se avisa
 * al siguiente nivel del circuito o, si es el último, a quien configura los
 * pases. Por omisión está apagado (sin horas no hace nada). Lo llama
 * desplegar.sh en cada corrida del cron (cada 5 minutos): corre en proceso,
 * sin exec ni proc_open. Un recordatorio y un aviso por paso (recordatorio_en /
 * escalado_en).
 *   --si-toca  solo informa cuando recordó o escaló algo
 */
class EscalarFirmasDePases extends Command
{
    protected $signature = 'plataforma:escalar-pases {--si-toca : Solo informa cuando recordó o escaló algo}';

    protected $description = 'Recuerda y escala las firmas de pases de salida que llevan demasiado tiempo esperando';

    public function handle(EsperaPasesSalida $espera): int
    {
        $hecho = $espera->escalar();
        if ($hecho['recordatorios'] + $hecho['escalamientos'] > 0 || ! $this->option('si-toca')) {
            $this->info("Recordatorios enviados: {$hecho['recordatorios']}. Escalamientos: {$hecho['escalamientos']}.");
        }

        return self::SUCCESS;
    }
}
