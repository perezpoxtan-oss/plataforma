<?php

namespace Tests\Feature\Seguridad;

use App\Http\Controllers\Padrones\RutaController;
use App\Models\Paradero;
use App\Models\RutaHorario;
use App\Models\RutaParada;
use Tests\TestCase;

/**
 * Hoja de horarios: las columnas de paraderos siguen el recorrido de las
 * rutas (no el orden en que aparecen en la hoja).
 */
class RutasOrdenParaderosTest extends TestCase
{
    /** @param list<array{0:int,1:string}> $paradas [paradero_id, nombre] en orden */
    private function horario(array $paradas): RutaHorario
    {
        $h = new RutaHorario;
        $h->setRelation('paradas', collect($paradas)->values()->map(function ($p, $i) {
            $parada = new RutaParada(['paradero_id' => $p[0], 'orden' => $i + 1]);
            $parada->setRelation('paradero', new Paradero(['nombre' => $p[1]]));

            return $parada;
        }));

        return $h;
    }

    public function test_un_paradero_que_aparece_despues_en_la_hoja_va_en_su_lugar_del_recorrido(): void
    {
        // La ruta de las 6:00 no pasa por A; la de las 7:00 sí, antes que B.
        $filas = collect([
            $this->horario([[2, 'B'], [3, 'C']]),
            $this->horario([[1, 'A'], [2, 'B'], [3, 'C']]),
        ]);

        $this->assertSame([1 => 'A', 2 => 'B', 3 => 'C'], RutaController::columnasEnOrdenDeRecorrido($filas));
    }

    public function test_recorridos_que_se_cruzan_respetan_el_orden_de_cada_ruta(): void
    {
        $filas = collect([
            $this->horario([[1, 'CENTRO'], [3, 'PLAYA']]),
            $this->horario([[2, 'NORTE'], [1, 'CENTRO'], [4, 'AEROPUERTO']]),
            $this->horario([[3, 'PLAYA'], [4, 'AEROPUERTO']]),
        ]);
        $orden = array_keys(RutaController::columnasEnOrdenDeRecorrido($filas));

        foreach ([[1, 3], [2, 1], [1, 4], [3, 4]] as [$antes, $despues]) {
            $this->assertLessThan(array_search($despues, $orden, true), array_search($antes, $orden, true));
        }
    }

    public function test_dos_rutas_en_sentido_contrario_no_traban_la_hoja(): void
    {
        $filas = collect([
            $this->horario([[1, 'A'], [2, 'B'], [3, 'C']]),
            $this->horario([[3, 'C'], [2, 'B'], [1, 'A']]),
        ]);

        $this->assertCount(3, RutaController::columnasEnOrdenDeRecorrido($filas));
    }
}
