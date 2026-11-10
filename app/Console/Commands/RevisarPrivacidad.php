<?php

namespace App\Console\Commands;

use App\Services\Privacidad\ConservacionDatos;
use Illuminate\Console\Command;

/**
 * Privacidad (docs/tecnico/privacidad.md): quita los datos personales que
 * pasaron sus días de conservación en las empresas que tienen la tarea
 * ENCENDIDA y revisa los plazos de las solicitudes ARCO (recordatorio y
 * aviso de vencidas). Lo llama desplegar.sh en cada corrida del cron, en
 * proceso, sin exec ni proc_open.
 *   --si-toca  una vez al día, después de las 2:00 (y otra vez si quedó trabajo pendiente)
 */
class RevisarPrivacidad extends Command
{
    protected $signature = 'plataforma:privacidad {--si-toca : Una vez al día, después de las 2:00}';

    protected $description = 'Conservación de datos personales y plazos de las solicitudes ARCO';

    public function handle(ConservacionDatos $conservacion): int
    {
        $r = $conservacion->ejecutar((bool) $this->option('si-toca'));
        $algo = $r['fotos_persona'] + $r['fotos_identificacion'] + $r['visitantes'] + $r['arco_recordatorios'] + $r['arco_vencidas'];
        if ($algo > 0 || ! $this->option('si-toca')) {
            $this->info("Privacidad: {$r['fotos_persona']} fotos de personas, {$r['fotos_identificacion']} de identificaciones y {$r['visitantes']} registros de visitantes en {$r['empresas']} empresa(s); ARCO: {$r['arco_recordatorios']} recordatorios, {$r['arco_vencidas']} vencidas.");
        }

        return self::SUCCESS;
    }
}
