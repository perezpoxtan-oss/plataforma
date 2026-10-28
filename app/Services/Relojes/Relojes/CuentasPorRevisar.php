<?php

namespace App\Services\Relojes\Relojes;

use App\Models\SolicitudCuenta;
use App\Models\User;
use App\Services\Cuenta\SolicitudesCuenta;
use App\Services\Recepcion\Destinatarios;
use App\Services\Relojes\Espera;
use App\Services\Relojes\PendientesPorPersona;
use App\Services\Relojes\Reloj;
use App\Support\Tenancy\EmpresaScope;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Collection;

/**
 * Cuentas por revisar (renglones 20 y 23; C-22, C-36): «Pedir a mi
 * administrador» (contraseña o verificación en dos pasos) y las cuentas
 * desactivadas por la baja de su colaborador. Las bloqueadas por intentos se
 * resuelven solas al vencer el bloqueo, así que no llevan suplente.
 */
class CuentasPorRevisar extends Reloj
{
    public function clave(): string
    {
        return 'cuentas';
    }

    public function nombre(): string
    {
        return 'Cuentas por revisar';
    }

    public function suplenteOmision(): string
    {
        return 'quien administra usuarios en toda la empresa (o el administrador)';
    }

    public function permiso(): ?string
    {
        return 'usuarios.editar';
    }

    public function esperas(?int $empresaId): Collection
    {
        $lista = collect();
        foreach (SolicitudCuenta::query()->withoutGlobalScope(EmpresaScope::class)->where('empresa_id', $empresaId)->where('estado', SolicitudCuenta::PENDIENTE)
            ->with('usuario:id,name,empresa_id')->orderBy('created_at')->limit(300)->get() as $s) {
            $lista->push(new Espera(SolicitudesCuenta::REFERENCIA, (int) $s->id, $s->created_at ?? now(), ($s->usuario?->name ?? 'Una cuenta').' pide '.$s->etiquetaTipo(),
                route('usuarios.index', ['revisar' => 1]), null, ['permiso' => SolicitudesCuenta::PERMISOS[$s->tipo] ?? 'usuarios.editar', 'usuario' => (int) $s->user_id, 'tipo' => $s->tipo]));
        }
        foreach (User::where('empresa_id', $empresaId)->where('activo', false)->where('es_superadmin', false)->whereNotNull('desactivado_por_baja_en')
            ->where('desactivado_por_baja_en', '>=', now()->subDays(7))->limit(200)->get(['id', 'name', 'desactivado_por_baja_en']) as $u) {
            $lista->push(new Espera('cuenta_desactivada', (int) $u->id, $u->desactivado_por_baja_en, 'Cuenta dada de baja con su colaborador: '.$u->name,
                route('usuarios.index', ['revisar' => 1]), null, ['permiso' => 'usuarios.editar']));
        }

        return $lista;
    }

    public function responsables(Espera $e, ?int $empresaId): Collection
    {
        if ($e->referenciaTipo !== SolicitudesCuenta::REFERENCIA) {
            return $this->conPermiso($empresaId, 'usuarios.editar', null);
        }
        $usuario = User::find($e->datos['usuario']);
        $servicio = app(SolicitudesCuenta::class);

        return app(Destinatarios::class)->conPermisoEnAlgunaSede((int) $empresaId, $e->datos['permiso'])
            ->filter(fn (User $u) => (int) $u->id !== (int) $e->datos['usuario'] && $servicio->puedeAtender($u, $usuario, $e->datos['tipo']))->values();
    }

    public function puedeVer(User $actor): bool
    {
        return $actor->can('usuarios.editar') || $actor->can('usuarios.desbloquear');
    }

    /** D-01: el renglón de Mis pendientes que ya existía y lo cuenta. */
    public function renglonPropio(): ?string
    {
        return 'cuentas';
    }

    /**
     * D-02: le toca (y lo puede atender) si está en lo que esta persona puede
     * revisar en Usuarios con su alcance: el mismo conjunto que su renglón de Mis pendientes
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
        return $this->llaves[(int) $actor->id] ??= array_flip(app(PendientesPorPersona::class)->cuentas($actor, (int) app(Tenant::class)->empresaId())['llaves']);
    }

    public function olvidar(): void
    {
        parent::olvidar();
        $this->llaves = [];
    }
}
