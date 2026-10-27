<?php

namespace App\Console\Commands;

use App\Services\Relojes\MotorRelojes;
use Illuminate\Console\Command;

/**
 * Motor «Reloj y suplente» (C-22): recuerda y pasa al suplente todo lo que
 * espera respuesta en cada empresa y en la plataforma. Corre en proceso (sin
 * exec ni proc_open) en la misma corrida del despliegue que los demás
 * recordatorios, y además al consultar la campana, por si ese cron no corre.
 * Cada asunto recibe a lo mucho un recordatorio y un paso al suplente.
 *   --si-toca  solo informa cuando hizo algo
 */
class CorrerRelojes extends Command
{
    protected $signature = 'plataforma:relojes {--si-toca : Solo informa cuando recordó o pasó algo al suplente}';

    protected $description = 'Recuerda y pasa al suplente lo que lleva demasiado tiempo esperando respuesta';

    public function handle(MotorRelojes $motor): int
    {
        $hecho = $motor->correr();
        if ($hecho['recordatorios'] + $hecho['escalamientos'] > 0 || ! $this->option('si-toca')) {
            $this->info("Recordatorios: {$hecho['recordatorios']}. Pasaron al suplente: {$hecho['escalamientos']}.");
        }

        return self::SUCCESS;
    }
}
