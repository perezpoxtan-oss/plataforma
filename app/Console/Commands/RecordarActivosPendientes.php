<?php

namespace App\Console\Commands;

use App\Services\PrestamoLlaves\AdministradorPrestamosLlaves;
use App\Services\Responsivas\AdministradorResponsivas;
use Illuminate\Console\Command;

/**
 * ADR-0014: avisos en la campana de las llaves que llevan demasiadas horas
 * fuera y de los equipos «Turno» que no regresaron a su hora (horas por
 * empresa en Configuración → Caseta y activos; sin horas, no hace nada).
 * Lo llama desplegar.sh en cada corrida del cron (cada 5 minutos), en
 * proceso, sin exec ni proc_open. Cada préstamo o responsiva avisa una vez
 * en ámbar (a la caseta) y una en rojo (al jefe o supervisor).
 *   --si-toca  igual que sin la opción (cada aviso sale una sola vez); solo
 *              escribe en el registro cuando hubo avisos
 */
class RecordarActivosPendientes extends Command
{
    protected $signature = 'plataforma:activos-pendientes {--si-toca : Solo escribe en el registro cuando hubo avisos}';

    protected $description = 'Avisa en la campana las llaves fuera de tiempo y los equipos de turno sin regresar';

    public function handle(AdministradorPrestamosLlaves $llaves, AdministradorResponsivas $responsivas): int
    {
        $prestamos = $llaves->recordatorios();
        $turnos = $responsivas->recordatoriosTurno();
        if ($prestamos + $turnos > 0 || ! $this->option('si-toca')) {
            $this->info("Llaves fuera de tiempo avisadas: {$prestamos}. Responsivas con equipos de turno sin regresar avisadas: {$turnos}.");
        }

        return self::SUCCESS;
    }
}
