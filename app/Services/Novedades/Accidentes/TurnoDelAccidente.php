<?php

namespace App\Services\Novedades\Accidentes;

use App\Models\AccidenteColaborador;
use App\Models\Colaborador;
use App\Models\Novedad;

/**
 * C-33 (D-14, DD-16): el turno del colaborador accidentado se toma en el
 * servidor de su ficha (Estructura → Turnos), igual que ya se hacía con su
 * departamento y su puesto. El formulario no cambia: si quien atiende
 * escribió un turno, se respeta; si lo dejó vacío y la ficha tiene turno, se
 * guarda el de la ficha.
 */
class TurnoDelAccidente
{
    public function completar(Novedad $novedad): ?string
    {
        if ($novedad->categoria !== 'accidente') {
            return null;
        }
        $registro = AccidenteColaborador::where('novedad_id', $novedad->id)->first();
        if ($registro === null || trim((string) $registro->turno) !== '' || $registro->colaborador_id === null) {
            return null;
        }
        $turno = Colaborador::with('turno:id,nombre')->find($registro->colaborador_id)?->turno?->nombre;
        if ($turno === null || trim($turno) === '') {
            return null;
        }
        $registro->forceFill(['turno' => mb_strtoupper(mb_substr(trim($turno), 0, 50))])->save();

        return $registro->turno;
    }
}
