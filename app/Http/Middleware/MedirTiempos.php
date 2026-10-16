<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Diagnóstico de velocidad (docs/tecnico/velocidad.md). Mide en cada visita:
 *  - espera:   desde que el servidor web recibió la petición hasta que PHP empezó
 *              (cola del hospedaje o arranque de PHP: no depende de la plataforma)
 *  - arranque: carga del código y de la configuración
 *  - bd:       tiempo total de las consultas a la base de datos (y cuántas fueron)
 *  - codigo:   el resto (programa, pantallas, sesión)
 *
 * Al Super Administrador se lo muestra en el navegador con la cabecera estándar
 * «Server-Timing» (Herramientas de desarrollador → Network → Timing). Las visitas
 * que pasan de config('plataforma.velocidad.lento_ms') se anotan en
 * storage/logs/lentas-AAAA-MM-DD.log con sus consultas más lentas (sin valores).
 */
class MedirTiempos
{
    public function handle(Request $request, Closure $next): Response
    {
        $inicio = microtime(true);
        $consultas = 0;
        $msBd = 0.0;
        $lentas = [];
        DB::listen(function (QueryExecuted $q) use (&$consultas, &$msBd, &$lentas): void {
            $consultas++;
            $msBd += $q->time;
            $lentas[] = [$q->time, $q->sql];
            if (count($lentas) > 20) {
                usort($lentas, fn ($a, $b) => $b[0] <=> $a[0]);
                $lentas = array_slice($lentas, 0, 5);
            }
        });

        $respuesta = $next($request);

        $fin = microtime(true);
        $laravel = defined('LARAVEL_START') ? (float) LARAVEL_START : $inicio;
        $servidor = (float) ($request->server('REQUEST_TIME_FLOAT') ?? $laravel);
        $tiempos = [
            'espera' => max(0.0, ($laravel - $servidor) * 1000),
            'arranque' => max(0.0, ($inicio - $laravel) * 1000),
            'bd' => $msBd,
            'codigo' => max(0.0, ($fin - $inicio) * 1000 - $msBd),
            'total' => max(0.0, ($fin - $servidor) * 1000),
        ];
        usort($lentas, fn ($a, $b) => $b[0] <=> $a[0]);
        $lentas = array_slice($lentas, 0, 5);

        if ($request->user()?->es_superadmin) {
            $respuesta->headers->set('Server-Timing', $this->cabecera($tiempos, $consultas, $lentas));
        }

        if ($tiempos['total'] >= (int) config('plataforma.velocidad.lento_ms', 1500)) {
            $this->anotar($request, $respuesta, $tiempos, $consultas, $lentas);
        }

        return $respuesta;
    }

    /**
     * @param  array<string, float>  $t
     * @param  list<array{0: float, 1: string}>  $lentas
     */
    private function cabecera(array $t, int $consultas, array $lentas): string
    {
        $partes = [
            sprintf('espera;dur=%.1f;desc="Espera del servidor antes de PHP"', $t['espera']),
            sprintf('arranque;dur=%.1f;desc="Arranque de la plataforma"', $t['arranque']),
            sprintf('bd;dur=%.1f;desc="Base de datos (%d consultas)"', $t['bd'], $consultas),
            sprintf('codigo;dur=%.1f;desc="Programa, pantalla y sesion"', $t['codigo']),
            sprintf('total;dur=%.1f;desc="Total en el servidor"', $t['total']),
        ];
        foreach (array_slice($lentas, 0, 3) as $i => [$ms, $sql]) {
            $partes[] = sprintf('consulta%d;dur=%.1f;desc="%s"', $i + 1, $ms, $this->resumen($sql, 90));
        }

        return implode(', ', $partes);
    }

    /**
     * @param  array<string, float>  $t
     * @param  list<array{0: float, 1: string}>  $lentas
     */
    private function anotar(Request $request, Response $respuesta, array $t, int $consultas, array $lentas): void
    {
        try {
            Log::build([
                'driver' => 'daily',
                'path' => storage_path('logs/lentas.log'),
                'level' => 'info',
                'days' => 14,
            ])->info(sprintf(
                '%s %s → %d | total %.0f ms | espera %.0f | arranque %.0f | bd %.0f (%d consultas) | código %.0f | memoria %.1f MB',
                $request->method(), $request->route()?->getName() ?? '/'.ltrim($request->path(), '/'), $respuesta->getStatusCode(),
                $t['total'], $t['espera'], $t['arranque'], $t['bd'], $consultas, $t['codigo'], memory_get_peak_usage(true) / 1048576,
            ), ['consultas_lentas' => array_map(fn ($c) => sprintf('%.0f ms  %s', $c[0], $this->resumen($c[1], 400)), $lentas)]);
        } catch (\Throwable) {
            // Medir nunca debe romper una pantalla
        }
    }

    private function resumen(string $sql, int $largo): string
    {
        $sql = (string) preg_replace('/\s+/', ' ', $sql);
        $sql = str_replace(['"', '\\'], ["'", ''], $sql);

        return mb_strlen($sql) > $largo ? mb_substr($sql, 0, $largo - 1).'…' : $sql;
    }
}
