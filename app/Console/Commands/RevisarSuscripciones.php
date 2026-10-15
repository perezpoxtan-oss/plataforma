<?php

namespace App\Console\Commands;

use App\Services\Suscripciones\RevisionDiaria;
use Illuminate\Console\Command;

/**
 * Revisión diaria de las suscripciones: guarda el estado según las fechas
 * (por vencer, en gracia, solo consulta) y manda los avisos al Administrador
 * del cliente y al Super Administrador. Lo llama desplegar.sh en cada corrida
 * del cron (cada 5 minutos), como los demás recordatorios: corre en proceso,
 * sin exec ni proc_open. Las empresas sin suscripción no se tocan.
 *   --si-toca  una vez al día, después de las 8:00
 */
class RevisarSuscripciones extends Command
{
    protected $signature = 'plataforma:suscripciones {--si-toca : Una vez al día, después de las 8:00}';

    protected $description = 'Revisa el vencimiento de las suscripciones y manda los avisos';

    public function handle(RevisionDiaria $revision): int
    {
        $r = $revision->ejecutar((bool) $this->option('si-toca'));
        if ($r['estados'] > 0 || $r['avisos'] > 0 || ! $this->option('si-toca')) {
            $this->info("Suscripciones: {$r['estados']} cambios de estado, {$r['avisos']} avisos.");
        }

        return self::SUCCESS;
    }
}
