<?php

namespace App\Http\Controllers\Publico\EquiposAcceso;

use App\Http\Controllers\Controller;
use App\Models\EquipoAcceso;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Lo común a las llamadas de los equipos (sin sesión): tamaño máximo,
 * identificar al equipo, revisar su IP, anotar el contacto y trabajar
 * dentro de SU empresa (un equipo jamás escribe en otra). Las respuestas
 * nunca llevan datos personales.
 */
abstract class LlamadaDeEquipo extends Controller
{
    /** Bytes máximos del cuerpo de una llamada. */
    protected const MAX_BYTES = 524288;

    /** Cada cuánto se anota el contacto (segundos): ZKTeco pregunta cada 30 s. */
    private const ANOTAR_CADA = 60;

    protected function demasiadoGrande(Request $request): bool
    {
        $declarado = (int) $request->header('Content-Length', '0');

        return $declarado > static::MAX_BYTES || strlen((string) $request->getContent()) > static::MAX_BYTES;
    }

    protected function texto(string $cuerpo, int $estado = 200): Response
    {
        return response($cuerpo, $estado, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /** Rechazo sin pistas: no dice si el equipo existe. */
    protected function rechazo(Request $request, string $porQue, int $estado = 401): Response
    {
        Log::warning('Equipo de acceso rechazado', ['motivo' => $porQue, 'ip' => $request->ip(), 'ruta' => $request->path()]);

        return $this->texto('ERROR', $estado);
    }

    /** ¿El equipo puede llamar desde esta IP? */
    protected function ipValida(EquipoAcceso $equipo, Request $request): bool
    {
        return $equipo->ipPermitida($request->ip());
    }

    /** Anota que el equipo llamó (a lo más una vez por minuto). */
    protected function anotarContacto(EquipoAcceso $equipo, Request $request, array $extra = []): void
    {
        $ip = (string) $request->ip();
        if ($extra === [] && $equipo->ultimo_contacto_at !== null && $equipo->ultimo_contacto_at->gt(now()->subSeconds(self::ANOTAR_CADA)) && $equipo->ultima_ip === $ip) {
            return;
        }
        EquipoAcceso::withoutGlobalScopes()->whereKey($equipo->id)->update(['ultimo_contacto_at' => now(), 'ultima_ip' => mb_substr($ip, 0, 45), 'updated_at' => now()] + $extra);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    protected function enSuEmpresa(EquipoAcceso $equipo, callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa((int) $equipo->empresa_id, $fn);
    }
}
