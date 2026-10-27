<?php

namespace App\Models\Concerns;

use App\Support\EstadoActivo;
use Illuminate\Database\Eloquent\Builder;

/**
 * C-14: llaves y gafetes guardan su estado (DISPONIBLE, NO REGRESÓ o BAJA)
 * además de `activo`, que se conserva porque lo leen el lector, las
 * etiquetas, los filtros y los informes. Los dos siempre coinciden:
 *  - quien cambia `activo` a falso deja el estado en BAJA, y a verdadero, en
 *    DISPONIBLE (si estaba de baja);
 *  - quien cambia el estado a BAJA deja `activo` en falso, y a cualquier otro, en verdadero.
 */
trait TieneEstadoActivo
{
    public static function bootTieneEstadoActivo(): void
    {
        static::saving(function ($modelo): void {
            $estado = $modelo->estado ?? EstadoActivo::DISPONIBLE;
            if ($modelo->isDirty('estado')) {
                $modelo->activo = $estado !== EstadoActivo::BAJA;
            } elseif ($modelo->isDirty('activo')) {
                if (! $modelo->activo) {
                    $modelo->estado = EstadoActivo::BAJA;
                } elseif ($estado === EstadoActivo::BAJA) {
                    $modelo->estado = EstadoActivo::DISPONIBLE;
                }
            }
        });
    }

    public function noRegreso(): bool
    {
        return ($this->estado ?? null) === EstadoActivo::NO_REGRESO;
    }

    /** Activos y que no están marcados «No regresó». */
    public function scopeEnCaseta(Builder $consulta): void
    {
        $consulta->where($this->qualifyColumn('activo'), true)->where($this->qualifyColumn('estado'), '!=', EstadoActivo::NO_REGRESO);
    }
}
