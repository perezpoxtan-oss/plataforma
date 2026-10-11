<?php

namespace Tests\Feature\Seguridad;

use App\Models\Candidato;
use App\Models\User;
use App\Services\Recepcion\AjustesRecepcion;
use Illuminate\Testing\TestResponse;

/**
 * Candidatos, fase 2: ayudas para las pruebas (calificar criterios,
 * evaluación de RR. HH. y canalizar al departamento por las pantallas).
 */
trait AyudasEntrevistas
{
    /**
     * Calificaciones de cada criterio (los de siempre, salvo que se indiquen otros).
     *
     * @param  int|list<int>  $estrellas
     * @param  list<string>|null  $criterios
     * @return array<string, string>
     */
    protected function calificaciones(int|array $estrellas = 4, ?array $criterios = null): array
    {
        $estrellas = (array) $estrellas;
        $r = [];
        foreach (array_values($criterios ?? AjustesRecepcion::CRITERIOS_DEFECTO) as $i => $nombre) {
            $r[AjustesRecepcion::claveCriterio($nombre)] = (string) $estrellas[$i % count($estrellas)];
        }

        return $r;
    }

    /** RR. HH. evalúa su entrevista (por la ficha). */
    protected function evaluarRh(User $rh, Candidato $c, string $resultado = 'canalizar', ?string $comentario = null, int|array $estrellas = 4): TestResponse
    {
        return $this->actingAs($rh)->post("/candidatos/{$c->id}/evaluacion-rh", ['_dialogo' => 'evaluacion', 'criterios' => $this->calificaciones($estrellas),
            'resultado' => $resultado, 'comentario' => $comentario]);
    }

    /**
     * RR. HH. canaliza (o reprograma / segunda entrevista) con cita o «ahora».
     *
     * @param  array<string, mixed>  $extra
     */
    protected function canalizar(User $rh, Candidato $c, User $entrevistador, int $departamentoId, ?string $cita = null, array $extra = []): TestResponse
    {
        $cuando = $cita === null ? ['cuando' => 'ahora'] : ['cuando' => 'cita', 'fecha' => substr($cita, 0, 10), 'hora' => substr($cita, 11, 5)];

        return $this->actingAs($rh)->post("/candidatos/{$c->id}/canalizar", $extra + $cuando + ['_dialogo' => 'canalizar',
            'departamento_id' => $departamentoId, 'entrevistador_id' => $entrevistador->id, 'lugar' => 'Sala de juntas']);
    }

    /** El entrevistador evalúa por la pantalla Entrevistar. */
    protected function evaluarDepartamento(User $jefe, int $postulacionId, string $resultado, ?string $comentario = null, int|array $estrellas = 4): TestResponse
    {
        return $this->actingAs($jefe)->post("/entrevistas/{$postulacionId}", ['_dialogo' => 'entrevistar', 'criterios' => $this->calificaciones($estrellas),
            'resultado' => $resultado, 'comentario' => $comentario]);
    }
}
