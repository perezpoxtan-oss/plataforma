<?php

namespace App\Console\Commands;

use App\Services\Traslado\Paquetes;
use Illuminate\Console\Command;
use Throwable;

/**
 * Paquetes de traslado y exportaciones de empresa (docs/tecnico/traslado.md).
 * Lo llama desplegar.sh cada 5 minutos, en proceso y sin exec:
 *   --si-toca     termina los paquetes que quedaron a medias (se cerró la
 *                 pantalla o una parte era grande) y arranca el semanal si
 *                 está encendido y ya pasaron 7 días;
 *   --generar     arma un paquete de traslado completo de una vez (consola).
 */
class Traslado extends Command
{
    protected $signature = 'plataforma:traslado
        {--si-toca : Avanza lo pendiente y arranca el paquete semanal si toca}
        {--generar : Genera ahora un paquete de traslado completo}
        {--segundos=120 : Tiempo máximo de esta corrida con --si-toca}';

    protected $description = 'Avanza o genera paquetes de traslado y exportaciones de empresa';

    public function handle(Paquetes $paquetes): int
    {
        try {
            if ($this->option('generar')) {
                $t = $paquetes->iniciarTraslado(null, 'consola');
                $r = $paquetes->completar($t['id']);
                if ($r['estado'] !== 'listo') {
                    $this->error('No se pudo generar el paquete: '.($r['error'] ?? 'error desconocido'));

                    return self::FAILURE;
                }
                $this->info(sprintf('Paquete %s generado (%s).', $r['paquete'], Paquetes::legible($r['bytes'])));

                return self::SUCCESS;
            }

            $limite = microtime(true) + max(10, (int) $this->option('segundos'));
            if ($this->option('si-toca') && $paquetes->tocaSemanal()) {
                try {
                    $paquetes->iniciarTraslado(null, 'semanal');
                } catch (Throwable $e) {
                    $this->warn('No se arrancó el paquete semanal: '.$e->getMessage()); // p. ej. disco insuficiente
                }
            }
            foreach ($paquetes->trabajos()->where('estado', 'en_proceso') as $t) {
                $restante = $limite - microtime(true);
                if ($restante <= 1) {
                    break;
                }
                $r = $paquetes->avanzar($t['id'], $restante, false);
                if ($r['estado'] === 'listo' && $r['paquete']) {
                    $this->info(sprintf('Paquete %s terminado (%s).', $r['paquete'], Paquetes::legible($r['bytes'])));
                } elseif ($r['estado'] === 'error') {
                    $this->error('Un paquete falló: '.$r['error']);
                }
            }
        } catch (Throwable $e) {
            $this->error('No se pudo avanzar los paquetes: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
