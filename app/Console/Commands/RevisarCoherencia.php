<?php

namespace App\Console\Commands;

use App\Services\BaseDatos\RevisionCoherencia;
use Illuminate\Console\Command;

/**
 * Revisión de coherencia de la base (docs/tecnico/integridad-bd.md, «Un solo
 * valor verdad»): resumen de las rutas contra sus horarios, ficha del
 * candidato contra su postulación activa, empresa de los pasajeros de
 * transporte contra su movimiento y estados fuera de catálogo.
 *
 *   (sin opciones)  solo lectura: informa las diferencias; sale con 1 si hay
 *   --reparar       corrige desde la fuente y lo anota en la Bitácora de auditoría
 *   --si-toca       una vez al día, después de las 4:00 (desplegar.sh lo llama
 *                   en proceso, sin exec ni proc_open, junto con --reparar)
 */
class RevisarCoherencia extends Command
{
    protected $signature = 'plataforma:revisar-coherencia
        {--reparar : Corrige las diferencias desde su fuente}
        {--si-toca : Una vez al día, después de las 4:00}';

    protected $description = 'Revisa (y con --reparar corrige) los datos que la base guarda dos veces';

    public function handle(RevisionCoherencia $revision): int
    {
        $siToca = (bool) $this->option('si-toca');
        if ($siToca && ! $revision->toca()) {
            return self::SUCCESS;
        }
        $reparar = (bool) $this->option('reparar');
        $r = $revision->revisar($reparar);
        if ($siToca) {
            $revision->anotar($r);
        }

        $diferencias = RevisionCoherencia::diferencias($r);
        if ($diferencias === 0 && $r['fuera_de_catalogo'] === []) {
            if (! $siToca) {
                $this->info('Coherencia: sin diferencias.');
            }

            return self::SUCCESS;
        }

        foreach ($r['rutas'] as $d) {
            $this->line(sprintf('Ruta #%d «%s» (empresa %d): resumen %s, sus horarios dicen %s',
                $d['id'], $d['nombre'], $d['empresa_id'], $this->texto($d['actual']), $this->texto($d['esperado'])));
        }
        foreach ($r['candidatos'] as $d) {
            $this->line(sprintf('Candidato #%d (empresa %d): etapa «%s», su postulación activa #%d dice «%s»',
                $d['id'], $d['empresa_id'], $d['actual'] ?? '', $d['postulacion_id'], $d['esperado']));
        }
        foreach ($r['pasajeros'] as $d) {
            $this->line(sprintf('Pasajero de transporte #%d: empresa %s, su movimiento es de la empresa %d', $d['id'], $d['actual'] ?? 'vacía', $d['esperado']));
        }
        foreach ($r['fuera_de_catalogo'] as $columna => $filas) {
            $this->warn("Valores fuera de catálogo en {$columna}: {$filas} fila(s) (se corrigen a mano; ver docs/tecnico/integridad-bd.md)");
        }
        $this->info(sprintf('Coherencia: %d ruta(s), %d candidato(s), %d pasajero(s) con diferencias%s.',
            count($r['rutas']), count($r['candidatos']), count($r['pasajeros']), $reparar ? "; {$r['reparados']} reparado(s)" : ''));

        return $reparar || $diferencias === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function texto(array $resumen): string
    {
        return sprintf('%s–%s %s', substr((string) $resumen['hora_inicio'], 0, 5), substr((string) $resumen['hora_fin'], 0, 5), $resumen['dias'] ?? '');
    }
}
