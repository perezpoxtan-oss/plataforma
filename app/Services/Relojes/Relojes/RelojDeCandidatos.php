<?php

namespace App\Services\Relojes\Relojes;

use App\Models\Empresa;
use App\Services\Candidatos\PlazosCandidatos;
use App\Services\Relojes\Espera;
use App\Services\Relojes\Reloj;
use Illuminate\Support\Collection;

/**
 * Plazos de candidatos (C-23, E-07): el recordatorio diario ya lo manda
 * PlazosCandidatos a Recursos Humanos de la sede; el motor solo pasa al
 * suplente al doble del plazo: a quien edita candidatos en toda la empresa.
 */
abstract class RelojDeCandidatos extends Reloj
{
    /** Plazo de PlazosCandidatos (días) que manda en este reloj. */
    abstract protected function plazo(): string;

    public function modulos(): array
    {
        return ['candidatos'];
    }

    public function permiso(): ?string
    {
        return 'candidatos.editar';
    }

    public function suplenteOmision(): string
    {
        return 'quien edita candidatos en toda la empresa (o el administrador)';
    }

    public function recuerda(): bool
    {
        return false;
    }

    public function dondeSeConfigura(): ?string
    {
        return 'Candidatos → Documentos y plazos (al doble del plazo pasa al suplente)';
    }

    public function umbralesPropios($empresa): ?array
    {
        $dias = $empresa instanceof Empresa ? app(PlazosCandidatos::class)->plazo($empresa, $this->plazo()) : PlazosCandidatos::DEFECTO[$this->plazo()];

        return [$dias * 1440, $dias * 2880];
    }

    public function responsables(Espera $e, ?int $empresaId): Collection
    {
        return $this->conPermiso($empresaId, 'candidatos.editar', $e->sedeId);
    }
}
