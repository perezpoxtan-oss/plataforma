<?php

namespace App\Services\Relojes\Relojes;

use App\Models\PaseSalida;
use App\Models\User;
use App\Services\Autorizaciones\Escalamiento;
use App\Services\PasesSalida\AdministradorPasesSalida;
use App\Services\Relojes\Espera;
use App\Services\Relojes\Reloj;
use Illuminate\Support\Collection;

/**
 * Pase vencido sin regresar (renglón 10; C-24): ya tenía su recordatorio
 * diario (plataforma:pases-vencidos); ahora entra a Mis pendientes de quien
 * aprueba pases en la sede y de su solicitante, y pasa al jefe de seguridad.
 */
class PasesVencidos extends Reloj
{
    public function clave(): string
    {
        return 'pases_vencidos';
    }

    public function nombre(): string
    {
        return 'Pases vencidos sin regresar';
    }

    public function suplenteOmision(): string
    {
        return 'el jefe de seguridad de la sede';
    }

    public function modulos(): array
    {
        return ['pases_salida'];
    }

    public function permiso(): ?string
    {
        return 'pases_salida.aprobar';
    }

    public function recuerda(): bool
    {
        return false;
    }

    public function umbralesPropios($empresa): ?array
    {
        return [1, 72 * 60];
    }

    public function renglonDef(): ?array
    {
        return ['pases_vencidos', 'Pases vencidos sin regresar', 'bi-exclamation-triangle', route('pases-salida.index', ['filtro' => 'vencidos'])];
    }

    public function textosInicio(): ?array
    {
        return ['1 pase de salida venció sin regresar', '%d pases de salida vencieron sin regresar', 'Debían regresar y no han vuelto. Pregunta por ellos o ciérralos con lo que falta.', 'Ver vencidos'];
    }

    public function esperas(?int $empresaId): Collection
    {
        return app(AdministradorPasesSalida::class)->filtrar(PaseSalida::query(), 'vencidos')->orderBy('pases_salida.id')->limit(500)
            ->get(['pases_salida.id', 'pases_salida.sede_id', 'pases_salida.folio', 'pases_salida.fecha_tentativa_regreso', 'pases_salida.creado_por', 'pases_salida.colaborador_id'])
            ->map(fn (PaseSalida $p) => new Espera('pase_salida_vencido', (int) $p->id, PaseSalida::dia($p->fecha_tentativa_regreso)?->copy()->addDay()->startOfDay() ?? now(),
                'Pase '.$p->folio.' vencido sin regresar', route('pases-salida.show', $p->id), (int) $p->sede_id,
                ['creado_por' => $p->creado_por !== null ? (int) $p->creado_por : null, 'colaborador' => $p->colaborador_id !== null ? (int) $p->colaborador_id : null]))->values();
    }

    public function responsables(Espera $e, ?int $empresaId): Collection
    {
        return $this->conPermiso($empresaId, 'pases_salida.aprobar', $e->sedeId);
    }

    /** También le toca a quien lo pidió (el solicitante o quien lo registró). */
    public function leToca(User $actor, Espera $e): bool
    {
        return parent::leToca($actor, $e) || (int) $actor->id === $e->datos['creado_por']
            || ($e->datos['colaborador'] !== null && (int) ($actor->getAttributes()['colaborador_id'] ?? 0) === $e->datos['colaborador']);
    }

    public function suplentes(Espera $e, ?int $empresaId, Collection $responsables, ?int $rolId): Collection
    {
        return $rolId === null ? app(Escalamiento::class)->jefesDeSeguridad((int) $empresaId, (int) $e->sedeId) : parent::suplentes($e, $empresaId, $responsables, $rolId);
    }

    public function puedeVer(User $actor): bool
    {
        return $actor->can('pases_salida.ver');
    }
}
