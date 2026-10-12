<?php

namespace App\Console\Commands;

use App\Services\Novedades\TurnosNovedades;
use Illuminate\Console\Command;

/**
 * Tickets «Pendiente de turno» (Bitácora de novedades, ADR-0013): al empezar
 * el turno que los recibe se avisa a su dueño o al personal de Seguridad de la
 * sede, y si ese turno termina sin atenderlos se avisa al jefe de seguridad.
 * Lo llama desplegar.sh en cada corrida del cron (cada 5 minutos), igual que
 * los demás recordatorios: corre en proceso, sin exec ni proc_open. Un aviso
 * por nivel (turno_avisado_en / turno_escalado_en). La bitácora también lo
 * dispara al pintarse, por si el cron no corre.
 *   --si-toca  solo informa cuando avisó algo (para no llenar la bitácora del cron)
 */
class NovedadesPendientesDeTurno extends Command
{
    protected $signature = 'plataforma:novedades-pendientes {--si-toca : Solo informa cuando avisó o escaló algo}';

    protected $description = 'Avisa al turno que recibe los tickets «Pendiente de turno» y al jefe si nadie los atendió';

    public function handle(TurnosNovedades $turnos): int
    {
        $hecho = $turnos->revisar();
        if ($hecho['avisos'] + $hecho['escalamientos'] > 0 || ! $this->option('si-toca')) {
            $this->info("Avisos al turno: {$hecho['avisos']}. Escalamientos al jefe: {$hecho['escalamientos']}.");
        }

        return self::SUCCESS;
    }
}
