<?php

namespace App\Services\Novedades\Formatos;

use App\Models\Empresa;
use App\Models\Novedad;
use App\Models\NovedadReporteGeneral;
use App\Models\User;
use App\Services\Novedades\AjustesNovedades;
use Illuminate\Validation\Rule;

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
            'ig_subtipo' => $r?->subtipo, // C-32
            'ig_observados' => $r?->observados,
            'ig_actividad' => $r?->actividad,
            'ig_motivo' => $r?->motivo,
            'ig_acciones' => $r?->acciones_inmediatas,
        ];
    }

    public function validar(array $entrada, Novedad $novedad): array
    {
        // C-32 (D-17): el subtipo sale de la lista de la empresa (o se conserva el que ya tenía)
        $permitidos = app(AjustesNovedades::class)->subtiposReporteGeneral(Empresa::find($novedad->empresa_id));
        $actual = $novedad->reporteGeneral?->subtipo;
        $this->revisar($entrada, [
            'ig_subtipo' => ['nullable', 'string', Rule::in(array_values(array_filter([...$permitidos, $actual])))],
            'ig_observados' => ['nullable', 'string', 'max:255'],
            'ig_actividad' => ['nullable', 'string', 'max:255'],
            'ig_motivo' => ['nullable', 'string', 'max:255'],
            'ig_acciones' => ['nullable', 'string', 'max:5000'],
        ], [
            'ig_subtipo' => 'Tipo de reporte',
            'ig_observados' => '¿Quién o quiénes fueron observados?',
            'ig_actividad' => '¿Qué actividad realizaban?',
            'ig_motivo' => '¿Por qué la realizaban?',
            'ig_acciones' => 'Acciones Inmediatas Tomadas por Seguridad',
        ]);

        return [
            'subtipo' => $this->texto($entrada['ig_subtipo'] ?? null),
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
