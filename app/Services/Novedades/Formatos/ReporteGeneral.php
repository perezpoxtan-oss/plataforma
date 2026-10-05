<?php

namespace App\Services\Novedades\Formatos;

use App\Models\Novedad;
use App\Models\NovedadReporteGeneral;
use App\Models\User;

/**
 * Reporte General (SEGCAT: INCIDENTE GENERAL, frag_incidente_general.php):
 * para cuando el guardia observa algo directamente en su área.
 */
class ReporteGeneral extends Formato
{
    public function relaciones(): array
    {
        return ['reporteGeneral'];
    }

    public function valores(Novedad $novedad): array
    {
        $r = $novedad->reporteGeneral;

        return [
            'ig_observados' => $r?->observados,
            'ig_actividad' => $r?->actividad,
            'ig_motivo' => $r?->motivo,
            'ig_acciones' => $r?->acciones_inmediatas,
        ];
    }

    public function validar(array $entrada, Novedad $novedad): array
    {
        $this->revisar($entrada, [
            'ig_observados' => ['nullable', 'string', 'max:255'],
            'ig_actividad' => ['nullable', 'string', 'max:255'],
            'ig_motivo' => ['nullable', 'string', 'max:255'],
            'ig_acciones' => ['nullable', 'string', 'max:5000'],
        ], [
            'ig_observados' => '¿Quién o quiénes fueron observados?',
            'ig_actividad' => '¿Qué actividad realizaban?',
            'ig_motivo' => '¿Por qué la realizaban?',
            'ig_acciones' => 'Acciones Inmediatas Tomadas por Seguridad',
        ]);

        return [
            'observados' => $this->texto($entrada['ig_observados'] ?? null, true),
            'actividad' => $this->texto($entrada['ig_actividad'] ?? null, true),
            'motivo' => $this->texto($entrada['ig_motivo'] ?? null, true),
            'acciones_inmediatas' => $this->texto($entrada['ig_acciones'] ?? null),
        ];
    }

    public function guardar(Novedad $novedad, array $datos, User $actor): void
    {
        NovedadReporteGeneral::updateOrCreate(['novedad_id' => $novedad->id], $datos);
    }
}
