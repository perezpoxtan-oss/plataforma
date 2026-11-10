<?php

namespace App\Console\Commands;

use App\Services\PasesSalida\AdministradorPasesSalida;
use Illuminate\Console\Command;

/**
 * Recordatorio diario de los pases de salida vencidos (debieron regresar y no
 * han vuelto). Lo llama desplegar.sh en cada corrida del cron (cada 5
 * minutos), igual que el respaldo diario: corre en proceso, sin exec ni
 * proc_open. Cada pase recibe máximo un recordatorio por día local de su sede.
 *   --si-toca  solo después de las 8:00 de cada sede (para no avisar de madrugada)
 */
class RecordarPasesVencidos extends Command
{
    protected $signature = 'plataforma:pases-vencidos {--si-toca : Solo después de las 8:00 de la sede, una vez al día}';

    protected $description = 'Envía por correo el recordatorio diario de los pases de salida vencidos';

    public function handle(AdministradorPasesSalida $pases): int
    {
        $enviados = $pases->recordatoriosVencidos((bool) $this->option('si-toca'));
        if ($enviados > 0 || ! $this->option('si-toca')) {
            $this->info("Recordatorios de pases vencidos enviados: {$enviados}.");
        }

        return self::SUCCESS;
    }
}
