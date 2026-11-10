<?php

namespace App\Services\Suscripciones;

use App\Models\Suscripcion;
use App\Models\SuscripcionExcepcion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Límites de la suscripción: sedes activas y usuarios activos.
 *
 *   sedes    = sedes contratadas + excepciones «sedes de más»
 *   usuarios = usuarios por sede del plan (congelado al contratar) × sedes contratadas
 *              + excepciones «usuarios de más»; plan con usuarios ilimitados = sin límite
 *
 * Una empresa SIN suscripción (las que ya existían, como la empresa de
 * prueba) no tiene límites: todo sigue como antes.
 */
class Limites
{
    /**
     * @return array{suscripcion: Suscripcion, sedes: int, usuarios: ?int, sedes_usadas: int, usuarios_usados: int}|null
     */
    public function de(?int $empresaId): ?array
    {
        if ($empresaId === null) {
            return null;
        }
        $s = Suscripcion::query()->where('empresa_id', $empresaId)->first();
        if ($s === null) {
            return null;
        }
        $extra = SuscripcionExcepcion::query()->where('suscripcion_id', $s->id)->where('activo', true)
            ->selectRaw('tipo, SUM(cantidad) as total')->groupBy('tipo')->pluck('total', 'tipo');
        $sedes = max(0, (int) $s->sedes_contratadas + (int) ($extra['sedes'] ?? 0));
        $porSede = $s->detalle['usuarios_por_sede'] ?? null;
        $usuarios = $porSede === null ? null : max(0, (int) $porSede * (int) $s->sedes_contratadas + (int) ($extra['usuarios'] ?? 0));

        return [
            'suscripcion' => $s,
            'sedes' => $sedes,
            'usuarios' => $usuarios,
            'sedes_usadas' => DB::table('sedes')->where('empresa_id', $empresaId)->where('activo', true)->whereNull('deleted_at')->count(),
            'usuarios_usados' => DB::table('users')->where('empresa_id', $empresaId)->where('activo', true)->count(),
        ];
    }

    /** Antes de dar de alta o reactivar una sede. */
    public function exigirSede(?int $empresaId): void
    {
        $l = $this->de($empresaId);
        if ($l !== null && $l['sedes_usadas'] >= $l['sedes']) {
            throw ValidationException::withMessages(['limite' => "Tu plan incluye {$l['sedes']} ".($l['sedes'] === 1 ? 'sede' : 'sedes')
                ." y ya tienes {$l['sedes_usadas']} ".($l['sedes_usadas'] === 1 ? 'activa' : 'activas')
                .'. Para agregar otra, amplía tu plan en Empresas → Tu suscripción, o da de baja una sede que ya no uses.']);
        }
    }

    /** Antes de dar de alta o reactivar un usuario. */
    public function exigirUsuario(?int $empresaId): void
    {
        $l = $this->de($empresaId);
        if ($l !== null && $l['usuarios'] !== null && $l['usuarios_usados'] >= $l['usuarios']) {
            throw ValidationException::withMessages(['limite' => "Tu plan incluye {$l['usuarios']} usuarios activos y ya tienes {$l['usuarios_usados']}"
                .'. Da de baja a quien ya no use la plataforma o amplía tu plan en Empresas → Tu suscripción.']);
        }
    }
}
