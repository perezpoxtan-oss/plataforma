<?php

namespace App\Services\Relojes\Relojes;

use App\Models\Procedimiento;
use App\Models\ProcedimientoVersion;
use App\Models\User;
use App\Services\Procedimientos\AdministradorProcedimientos;
use App\Services\Relojes\Espera;
use App\Services\Relojes\PendientesPorPersona;
use App\Services\Relojes\Reloj;
use Illuminate\Support\Collection;

/** Procedimiento por aprobar (renglón 14; C-22). */
class ProcedimientosPorAprobar extends Reloj
{
    public function clave(): string
    {
        return 'procedimientos_aprobar';
    }

    public function nombre(): string
    {
        return 'Procedimientos por aprobar';
    }

    public function suplenteOmision(): string
    {
        return 'quien aprueba procedimientos en toda la empresa (o el administrador)';
    }

    public function modulos(): array
    {
        return ['procedimientos'];
    }

    public function permiso(): ?string
    {
        return 'procedimientos.aprobar';
    }

    public function esperas(?int $empresaId): Collection
    {
        return ProcedimientoVersion::with('procedimiento:id,empresa_id,clave')->where('estado', ProcedimientoVersion::EN_REVISION)
            ->orderBy('id')->limit(500)->get()
            ->filter(fn (ProcedimientoVersion $v) => $v->procedimiento !== null)
            ->map(fn (ProcedimientoVersion $v) => new Espera('procedimiento_revision', (int) $v->id, $v->enviado_en ?? $v->updated_at ?? now(),
                'Procedimiento por aprobar: '.$v->procedimiento->clave, route('procedimientos.show', $v->procedimiento_id), null,
                ['procedimiento_id' => (int) $v->procedimiento_id]))->values();
    }

    public function responsables(Espera $e, ?int $empresaId): Collection
    {
        $p = Procedimiento::find($e->datos['procedimiento_id']);
        $v = ProcedimientoVersion::with('aplicaciones')->find($e->referenciaId);
        if ($p === null || $v === null) {
            return collect();
        }
        $servicio = app(AdministradorProcedimientos::class);

        return User::where('empresa_id', $empresaId)->where('activo', true)->where('es_superadmin', false)->get()
            ->filter(fn (User $u) => $servicio->motivoNoAprueba($u, $p, $v) === null)->values();
    }

    /** D-01: el renglón de Mis pendientes que ya existía y lo cuenta. */
    public function renglonPropio(): ?string
    {
        return 'procedimientos_aprobar';
    }

    /**
     * D-02: le toca (y lo puede atender) si está en lo que esta persona puede
     * aprobar con su alcance: el mismo conjunto que su renglón de Mis pendientes
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
        return $this->llaves[(int) $actor->id] ??= array_flip(app(PendientesPorPersona::class)->procedimientosPorAprobar($actor));
    }

    public function olvidar(): void
    {
        parent::olvidar();
        $this->llaves = [];
    }
}
