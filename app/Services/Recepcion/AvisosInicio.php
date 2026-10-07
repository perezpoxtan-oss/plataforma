<?php

namespace App\Services\Recepcion;

use App\Models\User;
use App\Services\Autorizaciones\Autorizaciones;

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
                    'texto' => 'La caseta ya las registró. Atiéndelas o mándales el QR del kiosco para que llenen su CV.',
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
                'texto' => 'Una visita o un candidato de tu departamento espera tu respuesta. Mientras no contestes, la persona sigue esperando.',
                'ruta' => route('autorizaciones.index'),
                'boton' => 'Responder',
            ];
        }

        return $avisos;
    }
}
