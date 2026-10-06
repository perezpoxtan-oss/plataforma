<?php

namespace App\Console\Commands;

use App\Services\Procedimientos\AdministradorProcedimientos;
use Illuminate\Console\Command;

/**
 * Recordatorio de los procedimientos que alguien aún no firma de enterado
 * («Leí y entendí»). Lo llama desplegar.sh en cada corrida del cron (cada 5
 * minutos), junto al de pases vencidos: corre en proceso, sin exec ni
 * proc_open. Cada usuario recibe uno cada 3 días como máximo.
 *   --si-toca  solo después de las 8:00 de la empresa (para no avisar de madrugada)
 */
class RecordarProcedimientosPendientes extends Command
{
    protected $signature = 'plataforma:procedimientos-pendientes {--si-toca : Solo después de las 8:00 de la empresa}';

    protected $description = 'Envía por correo el recordatorio de procedimientos por leer y firmar';

    public function handle(AdministradorProcedimientos $procedimientos): int
    {
        $enviados = $procedimientos->recordatorios((bool) $this->option('si-toca'));
        if ($enviados > 0 || ! $this->option('si-toca')) {
            $this->info("Recordatorios de procedimientos enviados: {$enviados}.");
        }

        return self::SUCCESS;
    }
}
