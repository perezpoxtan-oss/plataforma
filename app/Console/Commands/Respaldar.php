<?php

namespace App\Console\Commands;

use App\Services\Respaldos\Respaldos;
use Illuminate\Console\Command;
use Throwable;

/**
 * Respaldo de la base de datos. Lo llama desplegar.sh (cron cada 5 minutos):
 *   --si-toca                 crea el respaldo diario solo si aún no hay uno de hoy (después de las 3:00)
 *   --motivo=antes-de-actualizar  antes de migrar una versión nueva
 */
class Respaldar extends Command
{
    protected $signature = 'plataforma:respaldar
        {--motivo=manual : diario, antes-de-actualizar o manual}
        {--si-toca : Solo el respaldo diario, si todavía no se ha hecho hoy}';

    protected $description = 'Respalda la base de datos en storage/app/private/respaldos (.sql.gz)';

    public function handle(Respaldos $respaldos): int
    {
        $motivo = $this->option('si-toca') ? 'diario' : (string) $this->option('motivo');

        if ($this->option('si-toca') && ! $respaldos->tocaDiario()) {
            return self::SUCCESS; // silencioso: ya existe el de hoy
        }

        try {
            $r = $respaldos->crear($motivo);
        } catch (Throwable $e) {
            $this->error('No se pudo respaldar: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Respaldo %s creado (%s KB).', $r['archivo'], number_format($r['bytes'] / 1024, 1)));

        return self::SUCCESS;
    }
}
