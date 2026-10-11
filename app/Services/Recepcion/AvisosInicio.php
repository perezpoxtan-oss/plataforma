<?php

namespace App\Services\Recepcion;

use App\Models\User;
use App\Services\Autorizaciones\Autorizaciones;
use App\Services\Candidatos\Entrevistas;

/**
 * Avisos de Recepción en la pantalla de Inicio (mismo formato que los demás
 * pendientes): candidatos esperando a Recursos Humanos y lo que espera la
 * autorización del usuario (como responsable o delegado).
 */
class AvisosInicio
{
    public function __construct(
        private readonly PanelRecepcion $panel,
        private readonly Autorizaciones $autorizaciones,
    ) {}

    /**
     * @return list<array<string, string>>
     */
    public function para(User $actor): array
    {
        $avisos = [];

        if ($actor->can('recepcion_rh.ver') && $actor->can('candidatos.editar')) {
            $esperando = count(array_filter($this->panel->enEspera($actor), fn ($f) => $f['espera'] !== 'atendido'));
            if ($esperando > 0) {
                $avisos[] = [
                    'icono' => 'bi-person-check',
                    'titulo' => $esperando === 1 ? '1 persona espera a Recursos Humanos' : "{$esperando} personas esperan a Recursos Humanos",
                    'texto' => 'La caseta ya las registró. Dile «Que pase» a quien espera en caseta y atiéndelas desde Recepción.',
                    'ruta' => route('recepcion.index'),
                    'boton' => 'Ir a Recepción',
                ];
            }
        }

        $porResponder = $this->autorizaciones->pendientesPara($actor)->count();
        if ($porResponder > 0) {
            $avisos[] = [
                'icono' => 'bi-patch-check',
                'titulo' => $porResponder === 1 ? '1 solicitud espera tu autorización' : "{$porResponder} solicitudes esperan tu autorización",
                'texto' => 'Una visita de tu departamento espera tu respuesta. Mientras no contestes, la persona sigue esperando.',
                'ruta' => route('autorizaciones.index'),
                'boton' => 'Responder',
            ];
        }

        // Candidatos fase 2: entrevistas que Recursos Humanos le canalizó
        if ($actor->can('candidatos.evaluar')) {
            $entrevistas = app(Entrevistas::class)->pendientes($actor)->count();
            if ($entrevistas > 0) {
                $avisos[] = [
                    'icono' => 'bi-calendar-event',
                    'titulo' => $entrevistas === 1 ? '1 entrevista espera tu evaluación' : "{$entrevistas} entrevistas esperan tu evaluación",
                    'texto' => 'Recursos Humanos te canalizó candidatos: entrevístalos, califícalos y elige.',
                    'ruta' => route('entrevistas.index'),
                    'boton' => 'Entrevistar',
                ];
            }
        }

        return $avisos;
    }
}
