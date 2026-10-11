<?php

namespace App\Console\Commands;

use App\Services\Autorizaciones\Escalamiento;
use Illuminate\Console\Command;

/**
 * Autorizaciones que tardan (Bitácora de accesos): a los «recordar_minutos»
 * de la empresa se reenvía el aviso a quien debe responder y a los
 * «escalar_minutos» se avisa a los suplentes, al Jefe de seguridad de la sede
 * o a todo Recursos Humanos. Lo llama desplegar.sh en cada corrida del cron
 * (cada 5 minutos), igual que los demás recordatorios: corre en proceso, sin
 * exec ni proc_open. Cada autorización recibe máximo un recordatorio y un
 * escalamiento (recordatorio_en / escalado_en). La bitácora también lo
 * dispara al refrescarse, por si el cron no corre.
 *   --si-toca  solo informa cuando hizo algo (para no llenar la bitácora del cron)
 */
class EscalarAutorizaciones extends Command
{
    protected $signature = 'plataforma:escalar-autorizaciones {--si-toca : Solo informa cuando recordó o escaló algo}';

    protected $description = 'Recuerda y escala las autorizaciones de caseta que llevan demasiado tiempo sin respuesta';

    public function handle(Escalamiento $escalamiento): int
    {
        $hecho = $escalamiento->escalar();
        if ($hecho['recordatorios'] + $hecho['escalamientos'] > 0 || ! $this->option('si-toca')) {
            $this->info("Recordatorios enviados: {$hecho['recordatorios']}. Escalamientos: {$hecho['escalamientos']}.");
        }

        return self::SUCCESS;
    }
}
