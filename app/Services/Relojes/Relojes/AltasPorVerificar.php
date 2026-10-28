<?php

namespace App\Services\Relojes\Relojes;

use App\Models\Colaborador;
use App\Models\User;
use App\Services\Padrones\AltasPorVerificar as Altas;
use App\Services\Relojes\Espera;
use App\Services\Relojes\PendientesPorPersona;
use App\Services\Relojes\Reloj;
use Illuminate\Support\Collection;

/**
 * Altas por verificar (renglón 17; C-22, C-27): personas, vehículos y
 * empresas externas que la caseta dio de alta, y colaboradores provisionales.
 * Recuerda a quien verifica ese padrón en la sede; pasa a quien lo verifica
 * en toda la empresa (o al administrador).
 */
class AltasPorVerificar extends Reloj
{
    public function clave(): string
    {
        return 'altas';
    }

    public function nombre(): string
    {
        return 'Altas por verificar';
    }

    public function suplenteOmision(): string
    {
        return 'quien verifica ese padrón en toda la empresa (o el administrador)';
    }

    public function permiso(): ?string
    {
        return 'visitantes.editar';
    }

    public function esperas(?int $empresaId): Collection
    {
        $lista = collect();
        foreach (Altas::PADRONES as $padron => $d) {
            $tabla = (new $d['modelo'])->getTable();
            foreach ($d['modelo']::query()->pendientesDeVerificar()->orderBy($tabla.'.created_at')->limit(200)->get() as $r) {
                $lista->push(new Espera('alta_'.$padron, (int) $r->id, $r->created_at ?? now(), $d['singular'].' por verificar: '.app(Altas::class)->titulo($padron, $r),
                    route('altas_por_verificar.index', ['pestana' => $padron]), $r->sede_alta_id !== null ? (int) $r->sede_alta_id : null, ['permiso' => $d['permiso'].'.editar']));
            }
        }
        foreach (Colaborador::where('provisional', true)->where('activo', true)->whereNull('fusionado_en_id')->orderBy('created_at')->limit(200)->get() as $c) {
            $lista->push(new Espera('alta_colaboradores', (int) $c->id, $c->created_at ?? now(), 'Colaborador por verificar: '.$c->nombreCompleto(),
                route('altas_por_verificar.index', ['pestana' => 'colaboradores']), $c->sede_id !== null ? (int) $c->sede_id : null, ['permiso' => 'colaboradores.aprobar']));
        }

        return $lista;
    }

    public function responsables(Espera $e, ?int $empresaId): Collection
    {
        return $this->conPermiso($empresaId, $this->permisoDe($e), $e->sedeId);
    }

    public function puedeVer(User $actor): bool
    {
        return collect(['visitantes.editar', 'vehiculos.editar', 'proveedores.editar', 'colaboradores.aprobar'])->contains(fn ($p) => $actor->can($p));
    }

    /** D-01: el renglón de Mis pendientes que ya existía y lo cuenta. */
    public function renglonPropio(): ?string
    {
        return 'altas';
    }

    /**
     * D-02: le toca (y lo puede atender) si está en lo que esta persona puede
     * verificar con su alcance: el mismo conjunto que su renglón de Mis pendientes
     * (PendientesPorPersona), así el número, el color, el recordatorio y la
     * delegación siguen una sola regla.
     */
    public function leToca(User $actor, Espera $e): bool
    {
        return isset($this->llavesDe($actor)[$e->llave()]);
    }

    public function puedeAtender(User $actor, Espera $e): bool
    {
        return $this->leToca($actor, $e);
    }

    /** @var array<int, array<string, int>> */
    private array $llaves = [];

    /** @return array<string, int> */
    private function llavesDe(User $actor): array
    {
        return $this->llaves[(int) $actor->id] ??= array_flip(app(PendientesPorPersona::class)->altas($actor)['llaves']);
    }

    public function olvidar(): void
    {
        parent::olvidar();
        $this->llaves = [];
    }
}
