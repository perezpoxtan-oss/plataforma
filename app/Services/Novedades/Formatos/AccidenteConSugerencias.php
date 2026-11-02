<?php

namespace App\Services\Novedades\Formatos;

use App\Models\Novedad;

/**
 * C-33 (D-14, DD-16): el formato de Accidente NO cambia (mismos campos,
 * orden, textos, validación y vista: los de Accidente.php). Esta clase solo
 * agrega valores SUGERIDOS para un caso nuevo, que el formulario ya acepta
 * como valor inicial:
 *
 *  - Fecha y hora del accidente: las de «¿Cuándo sucedió?» del ticket (o
 *    la hora en que se reportó), para huésped y para colaborador.
 *  - No. Habitación: la habitación del catálogo del ticket, si tiene.
 *  - Lugar o área del accidente: la ubicación del ticket.
 *
 * Son sugerencias: quien atiende las cambia si no son. Un caso que ya tiene
 * su formato guardado se muestra tal cual (nada se sugiere encima).
 */
class AccidenteConSugerencias extends Accidente
{
    public function valores(Novedad $novedad): array
    {
        $valores = parent::valores($novedad);
        if ($novedad->accidenteHuesped !== null || $novedad->accidenteColaborador !== null) {
            return $valores;
        }

        $momento = $novedad->localParaCampo($novedad->ocurrio_en ?? $novedad->created_at); // «2026-11-01T22:15»
        [$fecha, $hora] = $momento !== '' ? explode('T', $momento) : [null, null];
        $habitacion = $novedad->area_especifica_id !== null
            ? ($novedad->relationLoaded('areaEspecifica') ? $novedad->areaEspecifica : $novedad->areaEspecifica()->first(['id', 'nombre']))?->nombre
            : null;
        $sugerir = fn (string $campo, ?string $valor) => ($valores[$campo] ?? null) === null || $valores[$campo] === '' ? $valor : $valores[$campo];

        $valores['h_fecha_accidente'] = $sugerir('h_fecha_accidente', $fecha);
        $valores['h_hora_accidente'] = $sugerir('h_hora_accidente', $hora);
        $valores['c_fecha_accidente'] = $sugerir('c_fecha_accidente', $fecha);
        $valores['c_hora_accidente'] = $sugerir('c_hora_accidente', $hora);
        $valores['h_hab'] = $sugerir('h_hab', $habitacion !== null ? mb_substr($habitacion, 0, 20) : null);
        $valores['h_lugar'] = $sugerir('h_lugar', $novedad->ubicacion !== null && $novedad->ubicacion !== '' ? mb_substr($novedad->ubicacion, 0, 255) : null);

        return $valores;
    }
}
