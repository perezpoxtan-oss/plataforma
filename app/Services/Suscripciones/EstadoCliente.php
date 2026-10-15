<?php

namespace App\Services\Suscripciones;

use App\Models\Suscripcion;
use App\Models\User;
use App\Services\Comercial\AjustesComerciales;

/**
 * La suscripción vista por un usuario de empresa: si está en solo consulta y
 * la franja que se muestra arriba de cada pantalla. El Super Administrador y
 * las empresas sin suscripción nunca quedan en solo consulta.
 */
class EstadoCliente
{
    public function __construct(private readonly AjustesComerciales $ajustes) {}

    public function suscripcionDe(?User $usuario): ?Suscripcion
    {
        if ($usuario === null || $usuario->es_superadmin || $usuario->empresa_id === null) {
            return null;
        }

        return Suscripcion::query()->where('empresa_id', $usuario->empresa_id)->first();
    }

    public function soloConsulta(?User $usuario): bool
    {
        return $this->suscripcionDe($usuario)?->soloConsulta() ?? false;
    }

    /** Texto de la franja de solo consulta (también es el mensaje cuando se bloquea un guardado). */
    public function mensajeSoloConsulta(Suscripcion $s): string
    {
        $contacto = $this->ajustes->contacto();
        $fin = match (true) {
            $s->estado === Suscripcion::CANCELADA => 'Tu suscripción está dada de baja',
            $s->suspendida => 'Tu suscripción está suspendida',
            $s->es_prueba => 'Tu prueba gratis terminó el '.$s->vence_en->format('d/m/Y'),
            default => 'Tu suscripción venció el '.$s->vence_en->format('d/m/Y'),
        };

        return "{$fin}; tienes acceso de solo consulta: puedes ver y exportar, pero no registrar ni cambiar nada. Contacta a {$contacto}.";
    }

    /**
     * @return array{nivel: string, texto: string}|null
     */
    public function franja(?User $usuario): ?array
    {
        $s = $this->suscripcionDe($usuario);
        if ($s === null) {
            return null;
        }
        $estado = $s->estadoEfectivo(null, $this->ajustes->diasPorVencer());
        if (in_array($estado, [Suscripcion::SOLO_CONSULTA, Suscripcion::CANCELADA], true)) {
            return ['nivel' => 'danger', 'texto' => $this->mensajeSoloConsulta($s)];
        }
        if ($estado === Suscripcion::EN_GRACIA) {
            return ['nivel' => 'warning', 'texto' => ($s->es_prueba ? 'Tu prueba gratis terminó' : 'Tu suscripción venció').' el '.$s->vence_en->format('d/m/Y')
                .'. Tienes hasta el '.$s->gracia_hasta?->format('d/m/Y').' para '.($s->es_prueba ? 'contratar tu plan' : 'normalizar el pago')
                .'; después la plataforma quedará en solo consulta. Contacta a '.$this->ajustes->contacto().'.'];
        }

        return null;
    }
}
