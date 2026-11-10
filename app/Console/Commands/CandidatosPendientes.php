<?php

namespace App\Console\Commands;

use App\Services\Candidatos\PlazosCandidatos;
use Illuminate\Console\Command;

/**
 * Candidatos, fase 3: plazos, «no se presentó» propuesto y caducidad.
 *
 * Para cada empresa activa: recuerda (una vez al día, campana y correo) lo que
 * pasó de su plazo —solicitud sin revisar, evaluación del departamento sin
 * registrar, elegido sin documentos o sin contrato, candidato por atender sin
 * movimiento—, propone «Marcar como no se presentó» cuando la cita venció sin
 * registro en caseta, archiva las postulaciones viejas en Considerar o
 * Rechazado, avisa 15 días antes de borrar una ficha sin contratación y la
 * borra con sus documentos cuando llega el día. Los plazos son por empresa
 * (Candidatos → Documentos y plazos). Lo llama desplegar.sh en cada corrida
 * del cron, en proceso (sin exec ni proc_open); la lista de Candidatos lo
 * dispara también al abrirse, por si el cron no corre.
 *   --si-toca  solo informa cuando hizo algo (para no llenar la bitácora del cron)
 */
class CandidatosPendientes extends Command
{
    protected $signature = 'plataforma:candidatos-pendientes {--si-toca : Solo informa cuando recordó, archivó o borró algo}';

    protected $description = 'Recuerda los plazos de candidatos, propone «no se presentó» y aplica la caducidad (archivar y borrar)';

    public function handle(PlazosCandidatos $plazos): int
    {
        $hecho = $plazos->correr();
        if (array_sum($hecho) > 0 || ! $this->option('si-toca')) {
            $this->info("Recordatorios: {$hecho['recordatorios']}. No se presentó propuestos: {$hecho['no_se_presento']}. "
                ."Postulaciones archivadas: {$hecho['archivadas']}. Avisos de borrado: {$hecho['avisos_borrado']}. Fichas borradas: {$hecho['borradas']}.");
        }

        return self::SUCCESS;
    }
}
